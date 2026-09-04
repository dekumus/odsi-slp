# `odsi-social` — schema

Fifteen custom tables plus one post type. For each table: what it holds, the
columns, the access patterns it must serve, and an index justified by each
pattern. Growth notes say which tables need a retention answer.

Conventions: `bigint(20) unsigned` for every id; `datetime` in UTC; `varchar`
lengths chosen for the `utf8mb4` 767-byte index limit on older MySQL
(`varchar(191)` where a column is indexed and free-form). Every table has an
auto-increment `id` primary key unless stated.

Tables are prefixed `{$wpdb->prefix}odsi_social_`.

---

## `groups` — index row per group post

The group's authored content (name, description, avatar, cover) lives in the
`odsi_social_group` post. Feed and directory queries need the group's
visibility and slug without joining `wp_posts` + `wp_postmeta`, so those are
mirrored here and kept in step by `Groups\Groups` on every save.

| Column | Type | Notes |
| --- | --- | --- |
| `post_id` | bigint | **PK**; the group post |
| `slug` | varchar(191) | mirrors `post_name` |
| `visibility` | varchar(16) | `public` / `private` / `hidden` |
| `member_count` | int unsigned | active members; denormalised |
| `activity_count` | int unsigned | denormalised |
| `last_active` | datetime | latest activity or membership change |
| `created_at` | datetime | |

| Access pattern | Index |
| --- | --- |
| Join from activity on `group_id` for visibility | PK |
| Directory: visible groups by newest / members / activity | `(visibility, created_at)`, `(visibility, member_count)`, `(visibility, last_active)` |
| Resolve `/groups/{slug}/` | unique `(slug)` |

Growth: one row per group. No retention concern.

---

## `group_members`

| Column | Type | Notes |
| --- | --- | --- |
| `group_id` | bigint | |
| `user_id` | bigint | |
| `role` | varchar(16) | `organiser` / `moderator` / `member` |
| `status` | varchar(16) | `active` / `pending` / `invited` / `banned` |
| `inviter_id` | bigint | 0 unless invited |
| `created_at` | datetime | |
| `updated_at` | datetime | |

| Access pattern | Index |
| --- | --- |
| "Is U in G, and as what?" (every group page, every privacy check) | unique `(group_id, user_id)` |
| Member list of G by role; organiser count for the invariant | `(group_id, status, role)` |
| U's groups (personal feed subquery, "my groups") | `(user_id, status)` |
| Pending requests for organisers to review | covered by `(group_id, status, role)` |

Growth: members × groups. Bounded by the community; no retention.

---

## `members` — index row per member

Ordering the directory by activity, and showing connection and follower counts
on every profile card, must not touch `wp_usermeta`. This row is created lazily
on a member's first authenticated request and maintained by the services that
change its counts.

| Column | Type | Notes |
| --- | --- | --- |
| `user_id` | bigint | **PK** |
| `last_active` | datetime | written at most every five minutes |
| `activity_count` | int unsigned | non-comment items |
| `connection_count` | int unsigned | |
| `follower_count` | int unsigned | |
| `following_count` | int unsigned | |
| `avatar_id` | bigint | attachment, 0 = Gravatar |
| `cover_id` | bigint | attachment |
| `message_setting` | varchar(16) | `anyone` / `connections` / `no_one` |
| `created_at` | datetime | |

| Access pattern | Index |
| --- | --- |
| Directory by recently active | `(last_active)` |
| Profile card counts | PK |

Growth: one row per member.

---

## `profile_groups`, `profile_fields`, `profile_data`

Admin-defined extended profile fields and the values members enter. These are
configuration and per-user data, not editorial content, so they are tables
rather than posts.

**`profile_groups`**: `id`, `name` varchar(191), `description` text,
`sort_order` int. Index `(sort_order)`.

**`profile_fields`**: `id`, `group_id`, `name` varchar(191), `type`
varchar(16), `options` longtext (JSON for select/multiselect), `required`
tinyint, `default_visibility` varchar(16), `allow_visibility_change` tinyint,
`sort_order` int, `created_at`. Indexes: `(group_id, sort_order)`.

**`profile_data`**: `id`, `field_id`, `user_id`, `value` longtext,
`visibility` varchar(16) NULL (NULL = use the field default).

| Access pattern | Index |
| --- | --- |
| Render a profile: all of U's values | `(user_id)` |
| Save one value; uniqueness | unique `(field_id, user_id)` |
| Directory filter by a field value (v2) | `(field_id, value(64))` — added with the feature, not now |

Growth: members × fields.

---

## `connections`

Symmetric relationship stored once per unordered pair. The pair is normalised
to `(user_low, user_high)` with `user_low < user_high`, so uniqueness is a
plain unique index and "are A and B connected" is one lookup with the pair
ordered by the caller. `initiator_id` records who asked, which the state
machine needs for accept/withdraw/decline permissions.

| Column | Type | Notes |
| --- | --- | --- |
| `user_low` | bigint | the smaller id |
| `user_high` | bigint | the larger id |
| `initiator_id` | bigint | one of the two |
| `status` | varchar(16) | `pending` / `accepted` |
| `created_at` | datetime | |
| `accepted_at` | datetime NULL | |

| Access pattern | Index |
| --- | --- |
| Is (A, B) connected / pending? | unique `(user_low, user_high)` |
| A's connections and requests where A is the low id | covered by the unique index with `status` filtered in PHP; add `(user_low, status)` |
| A's connections where A is the high id | `(user_high, status)` |
| Pending requests *to* A (A is not the initiator): needs both directions | the two indexes above plus `initiator_id` comparison in the query |

The privacy subquery uses both indexes via `(user_low = ? OR user_high = ?)`,
which MySQL executes as an index merge. Under load it is cheaper to run it as a
`UNION` of the two halves; `ConnectionRepository::ids_for()` does exactly that
and caches the result per request.

Growth: bounded by members². No retention.

---

## `follows`

| Column | Type | Notes |
| --- | --- | --- |
| `follower_id` | bigint | |
| `following_id` | bigint | |
| `created_at` | datetime | |

| Access pattern | Index |
| --- | --- |
| Does A follow B? Unfollow. | unique `(follower_id, following_id)` |
| Who does A follow? (personal feed subquery) | same index, prefix |
| Who follows B? (follower list, counts) | `(following_id)` |

Growth: bounded by members².

---

## `activity` — the hot table

Every update, comment and system event. Written on nearly every user action,
read on nearly every page.

| Column | Type | Notes |
| --- | --- | --- |
| `user_id` | bigint | author |
| `component` | varchar(32) | `activity`, `groups`, `members`, or a registered component |
| `type` | varchar(32) | `update`, `comment`, `joined_group`, … |
| `content` | longtext | sanitised source |
| `parent_id` | bigint | 0 for items; the item id for comments |
| `group_id` | bigint | 0 when not in a group |
| `primary_item_id` | bigint | what it is about (a group, a course …) |
| `secondary_item_id` | bigint | |
| `privacy` | varchar(16) | `public` / `members` / `connections` / `only_me` / `group` |
| `status` | varchar(16) | `published`; `hidden` reserved for moderation |
| `external_id` | varchar(191) NULL | idempotency key, scoped by component |
| `comment_count` | int unsigned | denormalised |
| `reaction_count` | int unsigned | denormalised |
| `is_edited` | tinyint | |
| `date_recorded` | datetime | |
| `date_updated` | datetime | |

| Access pattern | Index |
| --- | --- |
| Site feed: items, newest first, cursor | `feed (parent_id, status, date_recorded, id)` |
| Group feed | `group_feed (group_id, parent_id, status, date_recorded, id)` |
| Profile feed | `user_feed (user_id, parent_id, status, date_recorded, id)` |
| Comments of an item, latest three | `comments (parent_id, date_recorded, id)` — the leading column also serves cascade delete |
| Type / component filter on the site feed | `type_feed (type, parent_id, date_recorded, id)` |
| Idempotent external post | unique `external (component, external_id)` — NULLs do not collide |
| Everything about item X (bridge cleanup) | `item (component, primary_item_id)` |

Personal feed uses `feed` with the author/group predicate as semi-joins on
`follows`, `connections` and `group_members`; the optimiser drives it from the
smaller side. Measured, not assumed: the harness includes a seeded benchmark
test that asserts a page of 20 stays under a fixed query count and a
generous time bound.

Growth: unbounded, roughly proportional to member-days. Retention answer:
none in v1 (a feed that forgets is a product decision, not a storage one). The
partitioning path, if ever needed, is by `date_recorded` range; nothing in the
indexes precludes it.

---

## `activity_meta`

Key/value on activity for things a column would be wrong for: link preview
data, attached media ids, a bridge's structured payload.

`id`, `activity_id`, `meta_key` varchar(191), `meta_value` longtext.
Indexes: `(activity_id, meta_key(64))`, `(meta_key(64))`. Cascades with the
item.

---

## `reactions`

| Column | Type | Notes |
| --- | --- | --- |
| `activity_id` | bigint | |
| `user_id` | bigint | |
| `reaction` | varchar(16) | `like` in v1 |
| `created_at` | datetime | |

| Access pattern | Index |
| --- | --- |
| Has V reacted to each of these 20 items? Set / replace / remove. | unique `(activity_id, user_id)` |
| Who reacted to X (the "and 3 others" list) | same index, prefix |
| Delete a member's reactions on account deletion | `(user_id)` |

`reaction_count` on the item makes counting free. Growth: bounded by items ×
members in practice far lower.

---

## `notifications`

Write-time collapse (ADR-014). `collapse_key` is a short hash of
`(component, action, item_id)` while the row is unread and NULL once read or
for non-collapsing types; the unique index on `(user_id, collapse_key)` then
lets an `INSERT … ON DUPLICATE KEY UPDATE` bump the existing unread row and
lets any number of read rows coexist.

| Column | Type | Notes |
| --- | --- | --- |
| `user_id` | bigint | recipient |
| `actor_id` | bigint | most recent actor |
| `component` | varchar(32) | |
| `action` | varchar(32) | |
| `item_id` | bigint | |
| `secondary_item_id` | bigint | |
| `collapse_key` | char(32) NULL | see above |
| `actor_count` | int unsigned | distinct-ish actors folded in; 1 when not collapsed |
| `is_new` | tinyint | 1 unread |
| `date_notified` | datetime | bumped on collapse |
| `date_read` | datetime NULL | |

| Access pattern | Index |
| --- | --- |
| Upsert a collapsing notification | unique `(user_id, collapse_key)` |
| Bell dropdown: U's unread, newest | `(user_id, is_new, date_notified)` |
| Full list, newest | same index (prefix `user_id`, then range) |
| Delete when the item is deleted | `(component, item_id)` |
| Retention sweep | `(is_new, date_notified)` |

Growth: unbounded. Retention: read rows older than the setting (default 90
days) are deleted by the daily job (`SOC-NOT-008`).

---

## `threads`, `thread_participants`, `messages`

| `threads` | Type | Notes |
| --- | --- | --- |
| `pair_key` | varchar(41) NULL | `"{low}:{high}"` for two-party threads; unique |
| `last_message_id` | bigint | |
| `last_message_at` | datetime | inbox ordering |
| `message_count` | int unsigned | |
| `created_at` | datetime | |

| `thread_participants` | Type | Notes |
| --- | --- | --- |
| `thread_id` | bigint | |
| `user_id` | bigint | |
| `unread_count` | int unsigned | |
| `is_deleted` | tinyint | per-participant soft delete |
| `last_read_at` | datetime NULL | |
| `deleted_at` | datetime NULL | |

| `messages` | Type | Notes |
| --- | --- | --- |
| `thread_id` | bigint | |
| `sender_id` | bigint | |
| `content` | longtext | |
| `date_sent` | datetime | |

| Access pattern | Index |
| --- | --- |
| Find the pair's thread | unique `threads (pair_key)` |
| Inbox: U's live threads newest-message-first | `thread_participants (user_id, is_deleted, thread_id)` joined to `threads (last_message_at)` |
| Unread total for U | same participant index, summed |
| Thread view: messages oldest-first with cursor | `messages (thread_id, date_sent, id)` |
| Is U a participant? (every message route) | unique `thread_participants (thread_id, user_id)` |
| Physical removal after both delete | `thread_participants (is_deleted)` in the maintenance job |

Growth: messages unbounded. Retention: threads deleted by every participant
are removed by the daily job; otherwise kept.

---

## Post type: `odsi_social_group`

`public => false`, `publicly_queryable => false`, `show_in_rest => false`,
`rewrite => false` (ADR-018: groups are reached only through the router at
`/groups/{slug}/`), supports title, editor, thumbnail. Meta:
`_odsi_visibility` (mirrored to the index row), `_odsi_cover_id`,
`_odsi_creator_id`. Capabilities `edit_odsi_social_group(s)` map to
`manage_odsi_social` for admins; members create groups through the service,
which checks the setting, not through `wp-admin`.

---

## Two design questions, decided

### Comments: same table

One table, `parent_id`. Reasons, in order of weight:

1. A comment *is* activity: it appears in profile feeds, in mention handling,
   in "recent activity", and inherits its parent's privacy. Two tables mean two
   code paths for each of those.
2. Deletion and privacy cascade are one `WHERE parent_id = ?`.
3. The read-path cost is identical either way: "twenty items with their three
   most recent comments" is one `UNION ALL` of twenty `LIMIT 3` sub-selects
   against `(parent_id, date_recorded, id)`, whichever table it hits.

Cost: the hot table is larger, and the feed indexes carry a `parent_id`
leading column that is always `0` for feed reads. That is a small, constant
price.

### Notifications: write-time collapse

The bell shows "Ana and 3 others liked your post" as one row because it *is*
one row. Reasons:

1. Reading is far more frequent than writing; folding at write time makes the
   read a plain indexed scan.
2. Unread counts are exact by counting rows.
3. Marking read is an update of `is_new` and `collapse_key`, after which the
   next event naturally opens a fresh row (`SOC-NOT-004`) with no special logic.

Cost: `actor_count` is a count of events, not of distinct actors, unless the
writer checks the previous actor — it does, for the common "same person
reacted twice" case, and accepts imprecision beyond that. Read-time grouping
would give exact distinct-actor counts at the price of grouping on every read.
