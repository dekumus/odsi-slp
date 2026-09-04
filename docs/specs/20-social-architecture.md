# `odsi-social` — architecture

How the community plugin is built. The behaviour it implements is in
`11-social-functional-spec.md`; the tables are in `21-social-schema.md`. This
document is about boundaries: which class owns which decision, how data flows,
and where the seams for extension are.

## 1. Shape

The plugin mirrors `odsi-lms` exactly at the kernel level (ADR-004): a
`Plugin` singleton owning a `Container`, services registered as lazy factories,
components implementing `Bootable`, a `Schema` + `Migrator` pair, a
`Repositories\AbstractRepository` base, `Support\Capabilities`, `Support\Meta`,
`Support\Assets`, `Frontend\Templates`. A developer who has read the LMS should
be able to navigate this plugin without a map.

```
ODSI\Social\
  Plugin, Container, Installer
  Contracts\        Bootable, Repository, ActivityRenderer, NotificationRenderer
  Database\         Schema, Migrator
  Repositories\     one class per table (15)
  Members\          Profiles, ProfileFields, Directory, Presence
  Connections\      Connections, Follows
  Activity\         Activity, Feed, Privacy, Reactions, Mentions, Renderers
  Groups\           Groups, Membership, Roles
  Notifications\    Notifications, Renderers, Cleanup
  Messages\         Messages
  PostTypes\        GroupPostType
  Rest\             RestServiceProvider + one controller per area
  Frontend\         Router (virtual pages), Templates, Shortcodes
  Admin\            AdminMenu, ProfileFieldsScreen, Settings
  Support\          Capabilities, Meta, Assets, Settings, Cursor, Sanitizer
```

## 2. Domain model

Entities and the invariants each service enforces. Storage is in the schema
document; this is the shape.

| Entity | Storage | Identity | Invariants |
| --- | --- | --- | --- |
| Member | `wp_users` + `members` index row | user id | Index row exists for every user who has logged in; created lazily on first authenticated request |
| Profile field group / field | `profile_groups`, `profile_fields` | id | A field belongs to one group; deleting a group deletes its fields and their data |
| Profile data | `profile_data` | (field, user) | Visibility is the member's choice only where the field allows; otherwise the field default |
| Connection | `connections` | unordered pair | Symmetric; one row per pair stored as (low, high); `initiator_id` says who asked |
| Follow | `follows` | (follower, following) | Directed; no self-follow |
| Activity | `activity` | id | `parent_id = 0` for items; a comment's parent is always an item (re-parented on write); items in a group have `privacy = group`; `(component, external_id)` unique when set |
| Reaction | `reactions` | (activity, user) | One per member per item; `reaction_count` on the item is exact |
| Group | `odsi_social_group` post + `groups` index row | post id | At least one organiser at all times; index row mirrors visibility and slug |
| Membership | `group_members` | (group, user) | State machine in the spec; the last organiser cannot leave or be demoted |
| Notification | `notifications` | id | Never for the actor's own action; unread rows collapse on `collapse_key` |
| Thread / message | `threads`, `messages`, `thread_participants` | id | One thread per unordered pair in v1 (`pair_key`); a participant's delete is per-participant |

## 3. Component map

Each service owns one concern, holds the rules for it, and is the only writer
of its tables. Cross-service effects go through actions, never direct calls in
both directions.

```
                 ┌──────────────┐
   REST/Router ─▶│  Activity    │──posts──▶ activity table
                 │  (write)     │──fires──▶ odsi_social_activity_posted
                 └──────┬───────┘                │
                        │ uses                   ▼
                 ┌──────▼───────┐        ┌──────────────┐
                 │  Privacy     │◀───────│ Mentions     │──▶ Notifications
                 │  (one rule)  │        └──────────────┘
                 └──────▲───────┘
                        │ uses                   Reactions ──▶ Notifications
                 ┌──────┴───────┐
   REST/Router ─▶│  Feed (read) │──reads──▶ activity + reactions + members
                 └──────────────┘

   Connections, Follows ──fire──▶ odsi_social_connection_* / follow_* ──▶ Notifications, Members counts
   Groups, Membership   ──fire──▶ odsi_social_group_*                   ──▶ Activity (joined), Notifications
   Messages             ──fire──▶ odsi_social_message_sent               ──▶ Notifications
```

Dependency direction is strictly: repositories ← services ← controllers.
`Privacy` depends on `ConnectionRepository` and `GroupMemberRepository` and on
nothing else. `Feed` depends on `Privacy`. `Activity` (the writer) depends on
`Privacy` only to validate that a comment's author may see its parent.
`Notifications` depends on nothing in the domain; other services call it or fire
actions it listens to. Nothing depends on `Feed`.

### Service responsibilities

**`Members\Profiles`** — read a profile for a viewer (fields filtered by
visibility), update own fields, avatar and cover handling through the media
library, `get_avatar` integration.

**`Members\ProfileFields`** — admin CRUD of groups and fields; the visibility
rule "may the member change this" lives here.

**`Members\Directory`** — the directory query with search, ordering and
pagination; reads the `members` index row for ordering by activity.

**`Members\Presence`** — updates `last_active` at most once per five minutes
(`SOC-MEM-011`); creates the index row lazily.

**`Connections\Connections`** — the connection state machine; every method
takes the acting user and the target and rejects per the spec table. Fires
`odsi_social_connection_requested / accepted / removed`.

**`Connections\Follows`** — follow / unfollow; fires `odsi_social_followed /
unfollowed`.

**`Activity\Privacy`** — the single privacy rule, in two representations that
must agree: `can_view( viewer_id, item ): bool` for single items, and
`where_clause( viewer_id ): array{sql, params}` for feed queries. One test
table drives both. See § 5.

**`Activity\Activity`** — post an update, post a comment, edit, delete,
idempotent external post. Validates content and privacy, re-parents comments,
maintains `comment_count`, fires the activity hooks. Does not know about
notifications.

**`Activity\Feed`** — the four feed scopes with cursor pagination and a fixed
query budget per page (§ 6). Hydrates each page with authors, reaction state
and the three latest comments.

**`Activity\Reactions`** — set / remove; maintains `reaction_count`; fires
`odsi_social_reaction_added / removed`.

**`Activity\Mentions`** — parse `@nicename` tokens, resolve to users, filter by
`Privacy::can_view`, fire `odsi_social_mentioned` per recipient.

**`Activity\Renderers`** — registry of `type => ActivityRenderer` producing the
action sentence and markup; the fallback renderer prints content only
(`SOC-ACT-011`).

**`Groups\Groups`** — create (atomically with the first organiser membership),
update settings, delete (cascade), keep the index row in step with the post.

**`Groups\Membership`** — the membership state machine and role changes, with
the organiser invariant enforced in one place. Fires
`odsi_social_group_member_*`.

**`Notifications\Notifications`** — `notify( recipient, actor, component,
action, item_id, secondary_id, collapse: bool )`; mark read; counts; cleanup.
Excludes actor == recipient unconditionally (`SOC-NOT-002`). Listens to the
domain actions listed in the spec's trigger table.

**`Notifications\Renderers`** — registry of `(component, action) =>
NotificationRenderer`, with the generic fallback.

**`Messages\Messages`** — find-or-create the pair thread, send, list inbox,
read thread, per-participant delete, unread counts, the "who may message me"
check.

**`Frontend\Router`** — rewrite rules for `/members/`, `/groups/`, `/activity/`,
`/notifications/`, `/messages/`; resolves to a virtual page rendered through the
theme's page template with the plugin's template inside. Base slugs are
settings.

## 4. Hook surface

The public contract. Every hook is documented at its call site with a full
docblock; this is the index. Signatures are `( $arg1, $arg2, … )`.

### Actions

| Hook | Arguments | Fires when |
| --- | --- | --- |
| `odsi_social_register_services` | `Container` | Services registered; add-ons register theirs |
| `odsi_social_booted` | `Plugin` | Boot complete |
| `odsi_social_activity_posted` | `object $item` | After any activity row is written (update, comment, external) |
| `odsi_social_activity_updated` | `object $item, object $previous` | After an edit |
| `odsi_social_activity_deleted` | `object $item` | Before removal; comments and reactions still exist |
| `odsi_social_reaction_added` | `int $activity_id, int $user_id, string $type` | After the row is written |
| `odsi_social_reaction_removed` | `int $activity_id, int $user_id` | After removal |
| `odsi_social_mentioned` | `int $mentioned_id, object $item, int $author_id` | Per visible mention, after the item is written |
| `odsi_social_connection_requested` | `int $initiator_id, int $recipient_id` | Row created as pending |
| `odsi_social_connection_accepted` | `int $initiator_id, int $recipient_id` | Transition to accepted |
| `odsi_social_connection_removed` | `int $user_a, int $user_b, string $previous_status` | Any removal: withdraw, decline, remove |
| `odsi_social_followed` | `int $follower_id, int $following_id` | Row created |
| `odsi_social_unfollowed` | `int $follower_id, int $following_id` | Row removed |
| `odsi_social_group_created` | `int $group_id, int $creator_id` | Post and organiser row exist |
| `odsi_social_group_updated` | `int $group_id, array $changes` | Settings changed; `changes` names visibility when it changed |
| `odsi_social_group_deleted` | `int $group_id` | Before cascade |
| `odsi_social_group_member_joined` | `int $group_id, int $user_id, string $via, int $inviter_id` | Transition to active; `via` is `join`, `approve`, `accept_invite`, or `system` / a bridge-supplied value such as `course_enrollment` for `Membership::add()` |
| `odsi_social_group_member_requested` | `int $group_id, int $user_id` | Pending row |
| `odsi_social_group_member_invited` | `int $group_id, int $user_id, int $inviter_id` | Invited row |
| `odsi_social_group_member_left` | `int $group_id, int $user_id, string $via` | Row removed from active; `via` is `leave`, `remove` |
| `odsi_social_group_member_banned` / `_unbanned` | `int $group_id, int $user_id, int $actor_id` | |
| `odsi_social_group_role_changed` | `int $group_id, int $user_id, string $role, string $previous` | |
| `odsi_social_notification_created` | `object $notification, bool $collapsed` | After write; email listeners go here |
| `odsi_social_notifications_read` | `int $user_id, int[] $ids` | |
| `odsi_social_message_sent` | `object $message, int[] $recipient_ids` | After write |
| `odsi_social_member_active` | `int $user_id` | Presence write (at most every five minutes) |
| `odsi_social_daily_maintenance` | — | Cron |

### Filters

| Hook | Value | Purpose |
| --- | --- | --- |
| `odsi_social_bootable_services` | `string[]` | Which services boot this request |
| `odsi_social_can_view_activity` | `bool, int $viewer_id, object $item` | Final say over the privacy rule; applied in the PHP path only, and the feed excludes any item this filter would deny by post-filtering the page (documented cost) |
| `odsi_social_activity_privacy_choices` | `string[], int $user_id, int $group_id` | Restrict what a member may pick |
| `odsi_social_activity_content` | `string, object $item` | Rendered content |
| `odsi_social_activity_max_length` | `int` | Default 5000 |
| `odsi_social_feed_query_args` | `array, string $scope, int $viewer_id` | Adjust a feed query before it runs |
| `odsi_social_feed_per_page` | `int` | Default 20, capped at 50 |
| `odsi_social_can_create_group` | `bool, int $user_id` | |
| `odsi_social_can_message` | `bool, int $sender_id, int $recipient_id` | After the recipient's setting |
| `odsi_social_notification_recipients` | `int[], string $component, string $action, array $context` | Add or remove recipients before writing |
| `odsi_social_mention_pattern` | `string` | Regex for mentions |
| `odsi_social_directory_query_args` | `array, int $viewer_id` | |
| `odsi_social_profile_field_visibility` | `string, int $field_id, int $user_id` | |
| `odsi_social_locate_template` | `string $path, string $name` | |
| `odsi_social_rest_controllers` | `object[], Container` | |
| `odsi_social_route_slugs` | `array<string,string>` | Base slugs for virtual pages |

Anything a listener needs to act should be in the arguments. A listener that
has to re-query for the item it was just told about is a sign the hook is
under-specified; fix the hook.

## 5. Privacy: one rule, two representations

`Activity\Privacy` implements the decision table from the functional spec.
Every read path calls it; no other class inspects `privacy` or group visibility.

```php
final class Privacy {
    /** Single item. */
    public function can_view( int $viewer_id, object $item ): bool;

    /** Feed predicate: WHERE fragment over aliases a (activity) and g (groups index). */
    public function where_clause( int $viewer_id ): array; // ['sql' => '...', 'params' => [...]]
}
```

`where_clause()` expands to, for a logged-in viewer V:

```sql
(
  a.user_id = %d
  OR a.privacy = 'public'
  OR a.privacy = 'members'
  OR ( a.privacy = 'connections'
       AND a.user_id IN ( SELECT IF(c.user_low = %d, c.user_high, c.user_low)
                          FROM {connections} c
                          WHERE (c.user_low = %d OR c.user_high = %d) AND c.status = 'accepted' ) )
  OR ( a.privacy = 'group'
       AND ( g.visibility = 'public'
             OR a.group_id IN ( SELECT gm.group_id FROM {group_members} gm
                                WHERE gm.user_id = %d AND gm.status = 'active' ) ) )
)
```

For a visitor only the `public` and `group`+`public` branches remain. An admin
gets `1 = 1`. Comments are never selected by feed queries directly; they are
hydrated from their visible parents, which is how they inherit visibility.

The two representations are kept honest by a single test data set: every row of
the spec's table is asserted through `can_view()` **and** by inserting the item
and checking whether the site feed returns it.

## 6. Feed query budget

A page of N items costs exactly five queries, whatever N is:

1. **Items** — the scope predicate ∧ privacy predicate, `parent_id = 0`, cursor
   condition, `ORDER BY date_recorded DESC, id DESC LIMIT N+1` (the extra row
   tells us whether there is a next page).
2. **Comments** — one `UNION ALL` of N sub-selects, each
   `SELECT … WHERE parent_id = ? ORDER BY date_recorded DESC LIMIT 3`. Exact,
   one round trip, index-driven.
3. **Viewer reactions** — `WHERE activity_id IN (…) AND user_id = ?`.
4. **Authors** — one `WP_User_Query` by id for every distinct author across
   items and comments; results are primed into the user cache.
5. **Groups** — one query for the distinct group ids on the page, for names and
   slugs.

Counts (`comment_count`, `reaction_count`) are denormalised on the item so they
cost nothing to read. They are maintained by the writing services and
recomputed by the maintenance job as a safety net.

Cursor: `base64( date_recorded . '|' . id )`, opaque to clients. The next-page
condition is `(date_recorded < ?) OR (date_recorded = ? AND id < ?)`, which
the feed indexes serve directly.

## 7. Caching

| What | Key | Invalidated by |
| --- | --- | --- |
| Member counts (connections, followers, following, activity) | `members` index row | Writes to the respective tables, in the same request |
| Unread notification count | `unread_{user}` in cache group `odsi_social` | Any write to that user's notifications |
| Unread message total | `unread_msgs_{user}` in cache group `odsi_social` | Any write to that user's participant rows |
| Member index rows for a feed page | `MemberRepository::prime()` per request | Not cached across requests |
| Rendered activity item | not cached in v1 | — |
| Feed pages | not cached in v1 | — |

Feed pages are deliberately not cached: invalidation is per-viewer and the
queries are indexed. Revisit with numbers, not instinct.

## 8. REST

Namespace `odsi-social/v1`, routes as listed in the functional spec § 7. One
controller per area: `MembersController`, `ActivityController`,
`GroupsController`, `ConnectionsController`, `NotificationsController`,
`MessagesController`. Every write route has a real `permission_callback`; every
"not visible" outcome is a 404 (ADR-011). Response shapes are built by small
`present()` methods on each service shape REST output; there is no separate presenter layer

## 9. Front-end routing

Virtual pages. `Frontend\Router` registers rewrite rules and query vars
(`odsi_social_page`, `odsi_social_object`, `odsi_social_action`), hooks
`template_include` to render the theme's `page.php` with the plugin's template
injected via `the_content`, and sets the document title. Base slugs come from
`Support\Settings` with defaults `members`, `groups`, `activity`,
`notifications`, `messages`. A theme overrides any template at
`wp-content/themes/{theme}/odsi-social/{name}.php`.

## 10. Extension model

- **New activity type**: post through `Activity::post()` with your `component`
  and `type`; register a renderer with
  `Activity\Renderers::register( $type, $renderer, $component = '*' )`.
- **New notification**: call `Notifications::notify()` or fire your own action
  and listen to it; register a `NotificationRenderer` for your
  `(component, action)`.
- **External idempotent posting** (the bridge): pass `external_id`.
- **Privacy override**: `odsi_social_can_view_activity`. Deny-only overrides
  are cheap; grant overrides are honoured on single items but not in feed
  queries, and the docblock says so.

## 11. What is deliberately not here

Media uploads on activity, forums, real-time delivery, email digests, blocking,
moderation queues, member types. Each has a table or column reserved where it
was cheap to do so (`activity_meta`, `status` on activity, `reaction` type
string), the group index and activity repositories it needs to resolve a
row's group and parent, and nothing else.
