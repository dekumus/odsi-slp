# `odsi-social` — v1 functional specification

Behavioural specification for the community plugin. Terms are defined in
`12-glossary.md`. Criteria are numbered `SOC-<AREA>-<nnn>` and referenced from
tests by id.

Roles in stories: **member** (any logged-in user), **visitor** (logged out),
**organiser** / **moderator** (group roles), **admin** (site administrator or
any user with `manage_odsi_social`).

The plugin knows nothing about courses. Anything course-shaped here is an
example of a *component* the bridge may register, never a built-in.

---

## 1. Members and profiles — `SOC-MEM`

### Stories

- As a member, I want a profile with a photo, cover image and a few fields about
  me so that others can find and recognise me.
- As an admin, I want to define which fields exist and their default visibility
  so that the community collects what it needs and no more.
- As a member, I want to control who sees each field so that I can share my
  employer with connections but not with the public.

### Criteria

**SOC-MEM-001** Every registered user is a member. A profile exists for every
user from the moment their account exists; there is no separate creation step
and no way to opt out short of deleting the account.

**SOC-MEM-002** A profile is reachable at `/members/{user_nicename}/`. The URL
uses `user_nicename`, never the numeric id, and returns 404 for an unknown or
deleted user.

**SOC-MEM-003** Avatar: a member may upload an image that replaces their
Gravatar everywhere the site calls `get_avatar()`. Removing it restores the
Gravatar. Maximum dimensions and file types are admin settings with defaults of
2048 px and `jpg, png, gif, webp`.

**SOC-MEM-004** Cover image: as `SOC-MEM-003`, displayed on the profile header
only.

**SOC-MEM-004b** An attachment id offered as an avatar or cover (member or
group) is accepted only when the actor uploaded it or may edit it; a replaced
image this plugin stored is deleted. Uploads are capped at 5 MB
(`odsi_social_image_max_bytes`), 8192 px a side, and ten per member per hour.

**SOC-MEM-004a** Uploads arrive through a plain multipart form at
`/members/{nicename}/edit/` (the member's own page; admins may edit anyone's)
or through `POST /members/me/{avatar|cover}`. The file must decode as an image
of an allowed type; larger images are shrunk in place to the `avatar_max_px`
setting; the attachment is owned by the member. A non-image or disallowed
type rejects the whole save with a message. Removing restores the default.

**SOC-MEM-005** Profile fields are defined by an admin in named field groups.
Types: `text`, `textarea`, `select`, `multiselect`, `date`, `url`, `checkbox`.
Each has a required flag, a default visibility, and a flag saying whether the
member may change that visibility.

**SOC-MEM-006** Field visibility is one of `public`, `members`, `connections`,
`only_me`. When a member may not change it, the admin default applies and the
member's stored preference, if any, is ignored.

**SOC-MEM-007** A required field with no value (for a multiselect, no
selection) blocks saving the profile edit form with a message naming the
field, but never blocks any other action on the site. A malformed field entry
is rejected with a 400. Registration is not gated by profile fields in v1.

**SOC-MEM-008** The member directory at `/members/` lists members who have
logged in at least once (those with a recorded "last active"), newest first by
default, with alternative orderings of alphabetical (display name) and recently
active. Searchable by display name and `user_nicename` substring. Paginated at
an admin-set size, default 20. Reading a profile never creates the member's
index row; only the member's own presence or settings do, and a purged
member's row is never recreated by a counter adjustment.

**SOC-MEM-009** The directory is visible to visitors when the admin setting
"public directory" is on (default on). A visitor sees only fields whose
visibility is `public`.

**SOC-MEM-010** Deleting a user removes their profile, field data, avatar and
cover; reassigns nothing. Their activity, comments, reactions, memberships,
connections, follows, notifications to others, and messages are handled per
`SOC-EDGE` "member deleted".

**SOC-MEM-011** "Last active" for a member is updated at most once per five
minutes on any authenticated request, so that ordering by activity does not cost
a write per page view.

---

## 2. Connections and follows — `SOC-CON`

### Stories

- As a member, I want to send a connection request and have it accepted so that
  we both know we know each other.
- As a member, I want to follow someone interesting without asking, so that I see
  their posts.
- As a member, I want to withdraw a request, decline one, or remove a connection,
  each without drama.

### Connection state machine

One row per unordered pair of members.

| From | To | Trigger | Actor |
| --- | --- | --- | --- |
| *(none)* | `pending` | request | either member, becomes `initiator` |
| `pending` | `accepted` | accept | the non-initiator only |
| `pending` | *(none)* | withdraw | the initiator only |
| `pending` | *(none)* | decline | the non-initiator only; the initiator is not notified |
| `accepted` | *(none)* | remove | either member; the other is not notified |

Rejected: a request when any row exists for the pair; accept by the initiator;
a request to oneself; a request to a non-existent user.

### Criteria

**SOC-CON-001** Connections are symmetric. `is_connected(A, B)` equals
`is_connected(B, A)` at all times; the storage order of the pair is invisible to
callers.

**SOC-CON-002** A follow is a directed edge. Following needs no acceptance and
sends no notification in v1. A member may not follow themselves. Following a
member you already follow is an idempotent no-op.

**SOC-CON-003** Accepting a connection does **not** create follows in either
direction; following someone does **not** create a connection. The two are
independent and each is queried on its own.

**SOC-CON-004** A `pending` request notifies the recipient once. Acceptance
notifies the initiator once. Decline, withdraw and remove notify no one.

**SOC-CON-005** A member's connections list and followers/following lists are
visible per the same visibility levels as profile fields, with an admin default
of `members`.

**SOC-CON-006** Counts of connections, followers and following are cached per
member and invalidated on any change to that member's edges. A count that is
stale after a change is a bug.


---

## 3. Activity — `SOC-ACT`

### Stories

- As a member, I want to post an update, comment on others', and like things,
  so that the site feels alive.
- As a member, I want to choose who sees each post so that I can be candid with
  connections.
- As a member, I want to @mention someone and have them see it.
- As an admin, I want to delete anything, and members to delete their own.

### Criteria

**SOC-ACT-001** An activity item has: author, `component` (a string naming the
subsystem, e.g. `activity`, `groups`, `members`, or a bridge-registered
component), `type` (e.g. `update`, `comment`, `joined_group`), content, privacy,
optional group, optional parent (for comments), optional primary and secondary
item ids (what it is about), and a recorded timestamp.

**SOC-ACT-002** A member may post an *update* with text up to an admin-set
length (default 5,000 characters). Empty or whitespace-only content is rejected.
Content is stored as submitted after `wp_kses` with the `post` allowed-tags set,
and rendered with autolinking of URLs and mentions.

**SOC-ACT-003** Privacy on an update is chosen by the author from `public`,
`members`, `connections`, `only_me`. The admin sets the default (default
`members`) and may restrict the choices; the post form offers only the allowed
levels with the default preselected, and the default is always one of the
allowed set. An update posted in a group has privacy `group` and no other
choice.

**SOC-ACT-004** A comment is an activity with `type = comment` and a `parent`
that is a non-comment activity. A comment posted with a parent that is itself a
comment is re-parented to that comment's parent. Comments inherit the parent's
privacy and group and cannot set their own. The single item view carries up to
500 comments, oldest first; feeds carry the three most recent (`SOC-ACT-035`).

**SOC-ACT-005** A reaction is one row per (member, activity). Reacting again
with the same type is a no-op; with a different type replaces. Removing a
reaction deletes the row. v1 offers the single type `like`.

**SOC-ACT-006** Reaction and comment counts on an item are exact and visible to
anyone who can see the item. They are not decremented for reactions by members
who can no longer see the item.

**SOC-ACT-007** `@{user_nicename}` in an update or comment is a mention. On
save, each mentioned member who can see the item is notified once per item;
mentions of members who cannot see it (privacy) are rendered as plain text
(the renderer asks the privacy rule per mentioned member) and send nothing.
Self-mentions send nothing.

**SOC-ACT-008** The author may edit an update's content within an admin-set
window (default 60 minutes, 0 disables editing). Edits mark the item as edited
and do not re-notify anyone. Comments follow the same rule.

**SOC-ACT-009** The author may delete their own update or comment at any time.
Deleting an update deletes its comments and reactions. Group organisers and
moderators may delete anything in their group. Admins may delete anything.

**SOC-ACT-010** Every write to activity fires `odsi_social_activity_posted`
after the row exists, with the full item; deletes fire
`odsi_social_activity_deleted` before removal with the same shape.

**SOC-ACT-011** A component other than the built-ins may post activity through
the public service with its own `component` and `type` strings, and register a
renderer for that type. An item with a type that has no renderer is rendered as
its content, so a bridge being deactivated never breaks a feed.

**SOC-ACT-012** An activity posted with an `external_id` (component-scoped) is
idempotent: posting again with the same component and `external_id` returns the
existing item and writes nothing. This is how the bridge avoids duplicating
"completed a course" on a re-fired hook.

### Privacy resolution

There is exactly one rule for "may V see item I", implemented once and called by
every read path — feed queries, single-item views, notifications, mention checks
and search. It has no exceptions elsewhere in the codebase.

Inputs: viewer V (may be a visitor), item I with author A, privacy P, group G
(optional), and for comments the resolution of the parent instead.

| P | G | V may see I when |
| --- | --- | --- |
| any | any | V is A, or V is an admin |
| `public` | none | always |
| `members` | none | V is logged in |
| `connections` | none | V is connected to A |
| `only_me` | none | never (beyond the first row) |
| `group` | public | always |
| `group` | private | V is an active member of G |
| `group` | hidden | V is an active member of G |
| any except `group` | any | **invalid**: an item in a group always has P = `group`; treat as `group` |
| `group` | none | **invalid**: treat as `only_me` |

Comments: resolve the parent; a comment is visible exactly when its parent is.

Deleted, banned or otherwise absent authors do not change the rule; an item by
a deleted author with `public` privacy is still public.

**SOC-ACT-020** The privacy rule above is implemented in one function, and a
test asserts every row of the table.

### Feeds

**SOC-ACT-030** Site feed: every non-comment item V may see, newest first.

**SOC-ACT-031** Personal feed: non-comment items V may see, where the author is
V, or is followed by V, or is connected to V, or the item is in a group V is an
active member of. Newest first.

**SOC-ACT-032** Group feed: non-comment items in G that V may see, newest first.
A visitor may see a public group's feed.

**SOC-ACT-033** Profile feed: non-comment items authored by member M that V may
see, newest first, excluding items in hidden groups V does not belong to (the
privacy rule already ensures this; stated for clarity).

**SOC-ACT-034** Every feed paginates by an opaque cursor derived from
(recorded timestamp, id) of the last row *fetched*, not the last row shown:
a page that a visibility filter empties still leads to the next page.
Fetching the next page after new items were posted never repeats or skips an
item. Page size is admin-set, default 20, maximum 50; a request without a
size (or with 0) gets the admin default.

**SOC-ACT-035** Each feed item carries its three most recent visible comments
and total comment count, whether V has reacted and the reaction count, the
author's display data, and whether V may delete it (`SOC-ACT-009`). The cost of
a page of 20 is bounded and independent of what is on it: no per-item database
query for comments, reactions, authors, avatars, groups, the viewer's
memberships or the viewer's connections. Everything the privacy rule and the
renderers read for a page is primed in a fixed number of queries first.

**SOC-ACT-036** Feeds may be filtered by `type` and, on the site feed, by
`component`. A filter for a type that has no items returns an empty page, not an
error.

**SOC-ACT-037** Who reacted to an item is listable, newest first, by anyone
who may view the item (`GET /activity/{id}/reactions`, and on the single item
page); a viewer who cannot see the item gets 404, never an empty list.

---

## 4. Groups — `SOC-GRP`

### Stories

- As a member, I want to create a group, invite people and post in it.
- As an organiser, I want to approve requests and remove people who misbehave.
- As a member, I want private groups to be genuinely private.

### Membership state machine

One row per (group, member).

| From | To | Trigger | Actor |
| --- | --- | --- | --- |
| *(none)* | `active` | join | the member, public group only |
| *(none)* | `pending` | request | the member, private group only |
| *(none)* | `invited` | invite | organiser or moderator; any group visibility |
| `pending` | `active` | approve | organiser or moderator |
| `pending` | *(none)* | reject / withdraw | organiser/moderator / the member |
| `invited` | `active` | accept | the member |
| `invited` | *(none)* | decline / revoke | the member / organiser or moderator |
| `active` | *(none)* | leave | the member, unless sole organiser |
| `active` | *(none)* | remove | organiser or moderator, never on an organiser by a moderator |
| `active` | `banned` | ban | organiser or moderator, never on an organiser |
| `banned` | *(none)* | unban | organiser or moderator |

Rejected: join on a private or hidden group; request on a hidden group; any
transition that would leave the group with zero organisers; a moderator acting
on an organiser; a member acting on themselves except leave, withdraw, decline,
accept. A member holding an invitation accepts it by joining or requesting,
whatever the group's visibility, and declines it by removing themselves.

### Criteria

**SOC-GRP-001** A group is a post of type `odsi_social_group` with a name,
description, avatar, cover image, visibility and a slug used at
`/groups/{slug}/`. Its creator becomes its first organiser as part of creation,
atomically: a group with no organiser must not be observable. A group
published from wp-admin gets its post author as organiser on save, and every
save recounts the member count from the membership table.

**SOC-GRP-002** Any member may create a group when the admin setting "members
may create groups" is on (default on); otherwise only admins.

**SOC-GRP-003** Visibility is `public`, `private` or `hidden`. Changing
visibility never changes memberships. Changing to `hidden` removes the group from
the directory immediately.

**SOC-GRP-004** The group directory at `/groups/` lists public and private
groups; hidden groups appear only to their active members and admins. Sortable
by newest, most members and recently active; searchable by name.

**SOC-GRP-005** A visitor may see a public group's page, feed and member list.
A logged-in non-member may see a private group's page (name, description, member
count) and a join-request button, but not its feed or member list. A non-member
requesting a hidden group's page receives 404, unless they hold an invitation:
an invitee may see a hidden group's page (with accept and decline controls),
but not its feed or member list until they accept. The group page shows the
first 50 active members whenever the member list is visible.

**SOC-GRP-006** Roles: `organiser`, `moderator`, `member`. Organisers may do
everything, including change settings, promote, demote and delete the group.
Moderators may manage content and members per the state machine but not
settings or roles. Promotion and demotion is organiser-only; an organiser may
demote themselves only when another organiser exists.

**SOC-GRP-005a** The group post type is not public: no core REST endpoint,
sitemap, feed, search result or `?odsi_social_group=` query exposes a group,
so a hidden group's existence is knowable only through the community routes
that apply `SOC-GRP-005`. A trashed group leaves the community (its posts are
invisible) until it is restored. Descriptions are formatted text; they never
run shortcodes or dynamic blocks.

**SOC-GRP-006a** Organisers manage a group at `/groups/{slug}/manage/`: name,
description, visibility, photo and cover through a multipart form, and the
member lists (approve or decline requests; promote to moderator or organiser,
demote an organiser or moderator, remove, ban, unban) through per-row forms. Anyone else gets a 404 there. Every action
re-runs the same service checks as the REST routes; the page is never the
authority.

**SOC-GRP-007** Deleting a group deletes its memberships and its activity
(with comments and reactions), and fires `odsi_social_group_deleted` before
removal. It does not delete members' accounts or their activity elsewhere.

**SOC-GRP-008** Group events post activity: member joined (on any transition to
`active` except by invite acceptance in a hidden group, where nothing is
posted), group created. Requests, invitations, bans and removals post nothing.

**SOC-GRP-009** Notifications: request → each organiser and moderator;
approval → the requester; invitation → the invitee; acceptance of an invitation
→ the inviter; promotion (a role change to a higher rank) → the promoted
member; demotion, ban and removal → no one.

**SOC-GRP-010** A member's list of groups shows every group they are `active`
in, plus their `pending` requests and `invited` invitations in separate
sections. Hidden groups they are active in or invited to are shown to them.
The list appears as "Your groups" at the top of the group directory for a
logged-in member, and at `GET /groups/mine`.

---

## 5. Notifications — `SOC-NOT`

### Criteria

**SOC-NOT-001** A notification has: recipient, actor, component, action, item
id, secondary item id, read state, and a timestamp. It is rendered to a sentence
and a link by a renderer registered for its (component, action); an unknown pair
renders as a generic "activity on the site" line, never as an error.

**SOC-NOT-002** A member is never notified of their own action. Every trigger
below excludes the actor from recipients.

**SOC-NOT-003** Exhaustive v1 triggers. Any notification not in this table is a
bug; any addition is a spec change.

| Event | Recipients | Collapses |
| --- | --- | --- |
| Connection requested | the requested member | no |
| Connection accepted | the initiator | no |
| Mentioned in an update or comment | each mentioned member who can see it | no |
| Comment on my update | the update's author | yes, per update |
| Comment on an update I commented on | each other commenter who can see it, excluding the update's author | yes, per update |
| Reaction on my update or comment | the author | yes, per item |
| Group join requested | each organiser and moderator | no |
| Group request approved | the requester | no |
| Group invitation | the invitee | no |
| Group invitation accepted | the inviter | no |
| Promoted in a group | the promoted member | no |
| New private message | each other thread participant | yes, per thread |

**SOC-NOT-004** "Collapses" means: while an unread notification exists for the
same (recipient, component, action, item id), a new event updates that
notification's actor and timestamp and increments its count rather than creating
a second row. Once read, the next event creates a new row. The rendered sentence
for a collapsed notification names the most recent actor and the count of others
("Ana and 3 others liked your post").

**SOC-NOT-005** A member may mark one, or all, notifications read; opening a
message thread marks that thread's "new message" notification read. Unread
count is exact, cached per member, invalidated on any write to that member's
notifications. The notifications page paginates at 20.

**SOC-NOT-006** A notification whose item has been deleted is deleted with it,
including notifications about the comments that go with a deleted update (a
like on a comment is keyed on the comment). A notification whose actor has
been deleted is retained and renders the actor as "a former member".

**SOC-NOT-007** Notifications are delivered in-app only in v1. Email delivery
is a v2 concern and must be implementable as a listener on
`odsi_social_notification_created` without changes to this spec.

**SOC-NOT-008** Notifications older than an admin-set retention (default 90
days) and read are deleted by the daily maintenance job. Unread ones are kept.


**SOC-NOT-008** Each newly written notification also sends one plain-text
email to the recipient with the notification text and its URL, unless the
member turned emails off on their profile settings page (on by default) or
`odsi_social_notification_email` suppresses it. A notification that folds
into an existing unread row (`SOC-NOT-004`) sends no further email.

---

## 6. Private messages — `SOC-MSG`

### Criteria

**SOC-MSG-001** A thread has exactly two participants in v1. Starting a message
to a member with whom a thread already exists appends to that thread. There is
therefore at most one thread per unordered pair.

**SOC-MSG-002** A member may message any other member unless the recipient's
setting "who may message me" (default `anyone`; alternatives `connections`,
`no_one`) excludes them, in which case the send is rejected with a message
saying so and nothing is written.

**SOC-MSG-003** A message has a sender, thread, content (same sanitisation as
updates, default maximum 10,000 characters) and timestamp. Empty content is
rejected.

**SOC-MSG-004** Each participant has an unread count per thread, incremented on
every message they did not send, zeroed when they open the thread.

**SOC-MSG-005** A participant may delete a thread for themselves. The other
participant still sees it. A message sent after a one-sided delete restores the
thread for the deleter with the full history. A thread deleted by both
participants is physically removed by the maintenance job.

**SOC-MSG-006** The inbox lists threads newest-message-first, with the other
participant, the last message excerpt, and the unread count. Paginated.

**SOC-MSG-007** A message's content is visible only to the thread's
participants and to no admin screen in v1. Admins may see thread metadata
(participants, counts) for moderation and may delete a thread outright.


**SOC-NOT-009** Notifications go only to members who can still see the item
(`Privacy::can_view`), so someone removed from a private group hears nothing
more about its posts.

### Housekeeping — `SOC-OPS`

**SOC-OPS-001** The daily maintenance job also recounts every denormalised
counter — an item's comment and reaction counts, a group's member count, a
member's activity, connection, follower and following counts — from the rows
they summarise, so an interrupted request or a direct database edit never
leaves a count wrong for more than a day.

**SOC-OPS-002** Uninstalling with "delete all community data" enabled removes
the plugin's tables, capabilities and settings, and also its group posts and
their meta, the avatars and covers it stored, members' email preferences, its
transients, the rewrite flag and the cron event. Without the opt-in nothing is
removed.

### Abuse limits — `SOC-ABUSE`

**SOC-ABUSE-001** Per-member sliding-window limits (filter
`odsi_social_rate_limits`) bound posts, comments, connection requests,
messages, group invitations, group creation and image uploads; exceeding one
returns 429 with `odsi_social_rate_limited`. A withdrawn, declined or removed
connection rests for an hour (`odsi_social_connection_cooldown`) before either
side may request again.

**SOC-ABUSE-002** Rendered activity delivered over REST is filtered with
`wp_kses_post` exactly like template output, and @mentions are rewritten in
text nodes only, never inside a tag.

---

## 7. Interfaces — `SOC-IF`

### REST — namespace `odsi-social/v1`

| Route | Auth | Behaviour |
| --- | --- | --- |
| `GET /members` | any | Directory, `SOC-MEM-008/009`, `search`, `orderby`, `page`. |
| `GET /members/{id}` | any | Profile with fields filtered by visibility for the caller. |
| `PATCH /members/me` | logged in | Update own fields and visibilities. |
| `POST /members/me/{avatar\|cover}` | logged in | Multipart `file`; `SOC-MEM-004a`. 201 with the profile. `DELETE` clears it. |
| `POST /groups/{id}/{avatar\|cover}` | organiser | Multipart `file`. 403 for others; `DELETE` clears it. |
| `GET /activity` | any | Feed. `scope` in `site\|personal\|group\|profile`, plus `group_id` / `user_id`, `type`, `cursor`, `per_page` (0 or absent: the admin default); `render=1` adds each item's server-rendered `html`. |
| `POST /activity` | logged in | Post an update. `content`, `privacy`, optional `group_id`. |
| `GET /activity/{id}` | any | Single item with all comments the caller may see. 404 when not visible. |
| `DELETE /activity/{id}` | logged in | `SOC-ACT-009`. 403 or 404. |
| `POST /activity/{id}/comments` | logged in | Comment. 404 when the parent is not visible. |
| `PUT /activity/{id}/reaction` | logged in | Set reaction, body `type`. 404 when not visible. |
| `DELETE /activity/{id}/reaction` | logged in | Remove. |
| `GET /activity/{id}/reactions` | any | Who reacted, newest first (`SOC-ACT-037`). 404 when not visible. |
| `GET /groups`, `GET /groups/{id}` | any | `SOC-GRP-004/005`. Hidden → 404 for non-members who are not invited. |
| `GET /groups/mine` | logged in | `SOC-GRP-010`: `active`, `pending`, `invited`. |
| `GET /groups/{id}/members` | any | Active members, organisers first. 404 when the group is not visible; 403 when its content is not. |
| `POST /groups` | logged in | Create. `SOC-GRP-002`. |
| `PATCH /groups/{id}` | organiser | Settings. |
| `POST /groups/{id}/membership` | logged in | accept an invitation, else join / request per visibility. |
| `DELETE /groups/{id}/membership` | logged in | leave / withdraw / decline. |
| `POST /groups/{id}/members/{user}` | organiser/moderator | invite, approve, promote, demote, ban, unban, remove via `action`. |
| `GET /connections`, `POST /connections/{user}`, `DELETE /connections/{user}`, `POST /connections/{user}/accept` | logged in | State machine § 2. |
| `PUT /follows/{user}`, `DELETE /follows/{user}` | logged in | `SOC-CON-002`. |
| `GET /notifications`, `POST /notifications/read`, `POST /notifications/{id}/read` | logged in | Own notifications only. |
| `GET /messages`, `GET /messages/{thread}`, `POST /messages/to/{user}`, `POST /messages/{thread}`, `DELETE /messages/{thread}` | logged in | § 6. Inbox; read a thread; start a thread with a member; reply in a thread; leave a thread. Threads not involving the caller → 404. |

"404 when not visible" is deliberate throughout: existence of hidden content is
not disclosed by a 403.

### Templates and shortcodes

**SOC-IF-001** Front-end pages — member directory, profile, group directory,
group, site feed, notifications, messages — are PHP templates under
`templates/`, overridable at `wp-content/themes/{theme}/odsi-social/`.

**SOC-IF-002** Each renders its complete first page without JavaScript;
lists (directories, inbox, notifications) carry pagination links. JavaScript
adds in-page posting, commenting, reacting, "load more" (which appends items
rendered by the same template as the first page) and unread badges.

**SOC-IF-004** Blocks `odsi-social/activity-feed`, `member-directory` and
`group-directory` are dynamic and render through the matching shortcode code
path inside the block wrapper; the editor shows the server-rendered result and
front-end assets load on any singular post containing one of them.

**SOC-IF-003** Routing uses rewrite rules for `/members/`, `/members/{nicename}/`,
`/groups/`, `/groups/{slug}/`, `/activity/`, `/notifications/`, `/messages/`,
each mapped to a virtual page rendered through the theme's page template. The
base slugs are admin settings; templates obtain section URLs through
`odsi_social_page_url` rather than hard-coding a slug. Whether the routed
object exists for the viewer (member, visible group, visible item, own
thread) is resolved through `odsi_social_page_exists` before any output, so a
missing or invisible page is served with HTTP 404 and no-cache headers, never
a 200 carrying a "not found" message.

---

## 8. Permission matrix

| Action | Visitor | Member | Group member | Moderator | Organiser | Admin |
| --- | --- | --- | --- | --- | --- | --- |
| View public content | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| View `members` content | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ |
| View private/hidden group content | ✗ | ✗ | ✓ | ✓ | ✓ | ✓ |
| Post update / comment / react | ✗ | ✓ | ✓ (in group) | ✓ | ✓ | ✓ |
| Delete own item | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Delete any item in group | ✗ | ✗ | ✗ | ✓ | ✓ | ✓ |
| Delete any item anywhere | ✗ | ✗ | ✗ | ✗ | ✗ | ✓ |
| Create group | ✗ | ✓ (setting) | — | — | — | ✓ |
| Invite / approve / remove / ban | ✗ | ✗ | ✗ | ✓ | ✓ | ✓ |
| Promote / demote / settings / delete group | ✗ | ✗ | ✗ | ✗ | ✓ | ✓ |
| Manage profile fields, plugin settings | ✗ | ✗ | ✗ | ✗ | ✗ | ✓ |

---

## 9. Edge cases

| Scenario | Behaviour | Criteria |
| --- | --- | --- |
| Member deleted | Profile data, avatar, cover, connections, follows, memberships, own notifications, and reactions are deleted. Their activity and comments are **retained** with the author rendered as "a former member", unless the admin setting "delete content with user" is on. Message threads are retained for the other participant. | MEM-010, NOT-006 |
| Sole organiser tries to leave | Rejected with a message to promote someone first | GRP state machine |
| Sole organiser's account deleted | The longest-standing moderator becomes organiser; failing that, the longest-standing member; failing that, the group is deleted | GRP-001 invariant |
| Group made private after public posts | Existing items become `group`/private and are hidden from non-members immediately | ACT privacy table, GRP-003 |
| Author changes an update from public to `only_me` | Item, comments and reactions vanish for everyone else; counts unchanged | ACT-006, ACT-008 |
| Connection removed after a `connections` post | The ex-connection can no longer see it; their earlier comments remain attached and visible to those who can see the post | privacy table |
| Mention of a member who cannot see the post | Plain text, no notification | ACT-007 |
| Same bridge event fired twice | One activity item | ACT-012 |
| Reaction on an item that is then deleted | Reaction rows and the collapsed notification are deleted | ACT-009, NOT-006 |
| Recipient blocks messages from anyone | Existing threads remain readable; sending to them fails | MSG-002 |
| Cursor from before a deletion | The deleted item is simply absent; pagination continues | ACT-034 |
| Member banned from a group they had posted in | Their items in that group remain (moderators may delete); they lose visibility of private/hidden group content | GRP state machine, privacy table |

---

## 10. Decisions taken here that constrain the architecture

Recorded for brief 03; each is a spec-level fact the schema must serve.

- Comments are a `parent` on the activity table, one level deep (`ACT-004`).
  Whether that is one table or two is the architect's call; the *behaviour* is
  fixed.
- Notifications collapse per (recipient, component, action, item) while unread
  (`NOT-004`). Whether that collapse is a write-time upsert or a read-time group
  is the architect's call; a count and a "most recent actor" must be available.
- Feed pages must be bounded to a constant number of queries (`ACT-035`).
- Cursor pagination on (timestamp, id) (`ACT-034`).
- Idempotent external posting via (component, external_id) (`ACT-012`).
- Exactly one privacy function (`ACT-020`).
