# Architecture decision record

Each entry states the decision, why it was taken, and what it costs. A decision
is changed by adding a superseding entry, not by editing history.

---

## ADR-001 — Build the social engine from scratch

**Status:** Accepted

**Context.** BuddyPress is GPL and is what BuddyBoss forked. Building on it
would save months on activity feeds, groups, friendships and messaging.

**Decision.** Build from scratch. Do not extend BuddyPress, and do not ship a
BuddyPress compatibility shim.

**Why.** BuddyPress's data model and template stack predate the block editor and
the REST API, and its extension points assume a procedural, globals-heavy style
that would leak into everything we build on top. Coupling our release cycle to
theirs on a product where the community layer is half the value is the wrong
trade. Owning the schema also lets us design the feed around the LMS from the
start rather than adapting a generic one.

**Cost.** Months of work that BuddyPress would have given us. No access to the
existing BuddyPress add-on ecosystem. Migration from an existing BuddyPress site
becomes an import tool we have to write.

---

## ADR-002 — Hybrid storage: custom post types plus custom tables

**Status:** Accepted

**Context.** Two obvious extremes. Everything in `wp_posts`/`wp_postmeta`
(LearnDash's approach) or everything in custom tables.

**Decision.** Authored content is a custom post type. High-volume relational
data is a custom, indexed table.

- **`wp_posts`:** courses, lessons, topics, quizzes, questions, certificates,
  cohorts, social groups, forum content.
- **Custom tables:** enrollments, per-step progress, quiz attempts, quiz
  answers, submissions, issued certificates, activity, activity meta,
  reactions, group members, connections, follows, notifications, messages,
  message recipients, profile field data.

**Why.** Posts buy us the block editor, revisions, media handling, permalinks,
autosave, the core REST API and every editorial plugin — for free, and correctly.
None of that applies to a progress row. Meanwhile `wp_postmeta` has no useful
composite indexes for "every completed step for this user on this course", which
is the query the whole LMS is built on; at real user counts that becomes a table
scan. The dividing line is: **does a human author it in an editor?**

**Cost.** Two storage idioms to learn. Custom tables need their own migrations,
their own REST exposure, and their own uninstall handling. Cross-store queries
(posts joined to progress) need care.

---

## ADR-003 — Hybrid UI: React for builders, PHP templates for the front end

**Status:** Accepted

**Context.** A course builder is a drag-and-drop tree editor; that is painful in
jQuery. A course page is content; that should be a theme's job.

**Decision.** React with `@wordpress/components` for complex admin surfaces
(course builder, quiz builder, reports). Classic, overridable PHP templates for
everything on the front end. Blocks wrap the same renderers as the shortcodes.

**Why.** The two halves have opposite requirements. Admin builders need rich
interaction and have a captive, modern-browser audience. Front-end pages need to
inherit theme styling, be overridable by a child theme, render without
JavaScript, and be indexable. Forcing one stack across both compromises
whichever half loses.

**Cost.** Two front-end toolchains. Some duplicated presentation logic between
the block editor preview and the PHP template.

---

## ADR-004 — Per-plugin kernel, no shared runtime library

**Status:** Accepted

**Context.** Both plugins need the same small kernel: a container, a `Bootable`
contract, a schema migrator, an asset registrar, a template loader. The obvious
move is a shared Composer package.

**Decision.** Each plugin carries its own copy under its own namespace. No
shared runtime package.

**Why.** Two independently versioned, independently installable plugins loading
the same classes is a fatal-error waiting to happen the moment their versions
diverge — the classic WordPress vendor-collision problem, and it fails at
`plugins_loaded` with a white screen rather than gracefully. The shared surface
is roughly 400 lines of infrastructure with no domain logic in it. Duplicating
that is cheaper than owning a version negotiation protocol.

**Cost.** A kernel bug gets fixed twice. The two copies will drift; that is
acceptable because neither is domain logic.

---

## ADR-005 — Plugins communicate through hooks only

**Status:** Accepted

**Decision.** `odsi-social` never references an `ODSI\LMS\*` symbol, and
`odsi-lms` never references an `ODSI\Social\*` symbol. Everything crossing the
boundary goes through a documented action or filter, or through `odsi-bridge`,
which may depend on both.

**Why.** It is the only way both plugins stay installable alone, and the only
way either can be tested without the other. It also makes the integration
surface explicit and documentable instead of emergent.

**Cost.** Some indirection. The bridge has to be kept in step with both sides.

**Enforcement.** A CI check greps each plugin for the other's namespace.

---

## ADR-006 — Course outline is derived, never stored

**Status:** Accepted

**Decision.** A course's ordered step list is computed from child relationships
and `menu_order` by a single service (`Courses\Structure`). It is not persisted
as an ordering column or a serialised tree.

**Why.** Two sources of truth for ordering always drift, and the drift shows up
as a learner who cannot finish a course because progress counts a step the
outline no longer contains. Deriving it means adding, removing or reordering
content can never corrupt existing progress; completion percentages intersect
stored progress against the live outline.

**Cost.** Outline resolution costs queries. Mitigated with a per-request cache
and, later, an object-cache layer.

---

## ADR-007 — Section lessons are containers: never a gate, always auto-completed

**Status:** Accepted (closes open question 1)

**Context.** The scaffold's outline places a lesson before its topics and treats
the previous node as the gate. For the first topic of a lesson that is the
lesson itself, which cannot be complete until its topics are — a deadlock under
linear progression. Some LMS products resolve this by making the learner mark
the lesson complete *after* its topics, which puts a redundant click at the end
of every section.

**Decision.** A lesson with topics is a *section*: it is never a gate, cannot be
marked complete, and gets its own completion row automatically when its last
descendant completes. A lesson without topics is a *leaf* and behaves like a
topic. A quiz gates only the node that follows it.

**Why.** It matches what a learner sees: sections are headings, leaves are
work. It removes the deadlock and the redundant click. Keeping a completion row
for the section means progress arithmetic and reports can still treat every node
uniformly.

**Cost.** Publishing the first topic under a leaf lesson changes the lesson's
kind and the meaning of its existing completion row. The spec accepts that the
row stands (`LMS-PRG-010`); it is the conservative outcome for the learner.

---

## ADR-008 — Re-enrollment resets the enrollment date

**Status:** Accepted (closes open question 5)

**Decision.** Reactivating an `expired` or `cancelled` enrollment sets
`enrolled_at` to now and recomputes `expires_at`. Drip schedules keyed to
enrollment restart.

**Why.** Access windows and drip both anchor on the same date, and a learner
who has lapsed and returned expects a fresh window. Preserving the original date
would make a re-purchased course expire immediately.

**Cost.** The original enrollment date is not preserved on the row. Reporting
that needs it must read the audit trail (v2) rather than the enrollment.

---

## ADR-009 — Progress survives unenrollment

**Status:** Accepted (closes open question 4)

**Decision.** Removing a learner from a course deletes the enrollment and keeps
every progress and attempt row. Deleting those is a separate, explicit reset.

**Why.** Enrollment is an access decision; progress is a record of work done.
Conflating them means an administrative slip destroys a learner's history. The
cost of retained rows is storage; the cost of lost rows is trust.

**Cost.** Orphaned progress accumulates for learners who never return. The
maintenance job may prune rows older than a configurable retention in v2.

---

## ADR-010 — Cohorts grant enrollment

**Status:** Accepted (closes open question 3)

**Decision.** Adding a member to a cohort enrolls them on each of its courses
with `source = cohort` and `source_id` = the cohort. Removing them cancels only
enrollments with that source and id; other enrollments and all progress remain.

**Why.** Cohorts exist for classes and client teams, where "in the cohort"
*means* "on the courses". Tagging the source makes removal precise, so a learner
who also bought the course individually keeps it.

**Cost.** Two enrollment paths for the same course cannot coexist on one row
(the row is unique per user and course), so a cohort add on top of a self
enrollment is a no-op that leaves `source = self`. Documented in `LMS-ENR-012`.

---

## ADR-011 — Hidden content answers 404, never 403

**Status:** Accepted

**Decision.** Across both plugins, a request for content the caller may not see
returns 404. 403 is reserved for content the caller can see but may not act on.

**Why.** A 403 confirms that the thing exists. For a hidden group, a private
message thread or a quiz attempt id, that confirmation is itself a leak, and
makes ids enumerable. Consistency matters more than the small loss of
diagnostic precision.

**Cost.** Support conversations occasionally have to distinguish "does not
exist" from "you cannot see it". Logs carry the distinction; responses do not.

---

## ADR-012 — Follows and connections are independent relationships

**Status:** Accepted

**Decision.** A follow is a directed edge with no consent step; a connection is
a mutual edge with a request and acceptance. Neither implies the other. Both are
stored separately and queried separately.

**Why.** They answer different questions — "whose posts do I want" versus "who
do I know" — and communities use them differently. Coupling them (auto-follow
on connect, say) is a product policy that can be layered on with a listener;
coupling them in the schema cannot be undone.

**Cost.** The personal feed query unions two edge tables. It is bounded and
indexed; see the schema document.

---

## ADR-013 — Activity comments live in the activity table

**Status:** Accepted

**Decision.** A comment is an activity row whose `parent_id` is the item it
comments on. Comments are one level deep; a reply to a comment is re-parented to
the item.

**Why.** A comment is subject to everything an item is — privacy inheritance,
mentions, profile feeds, deletion cascade — and one table gives all of that one
code path. The feed read pattern (N items, three latest comments each) costs the
same either way: a `UNION ALL` of per-parent `LIMIT 3` sub-selects on
`(parent_id, date_recorded, id)`.

**Cost.** The hottest table is larger; feed indexes lead with a `parent_id`
column that is always zero for item reads.

---

## ADR-014 — Notifications collapse at write time

**Status:** Accepted

**Decision.** Repeated events on the same `(recipient, component, action,
item)` update the existing unread notification row rather than adding one. The
mechanism is a nullable `collapse_key` with a unique index on
`(user_id, collapse_key)`; marking a row read nulls the key.

**Why.** Notifications are read far more often than written. Folding at write
time makes the unread list an indexed scan and the unread count a row count.
Nulling the key on read makes "the next event after reading opens a new row"
fall out of the index rather than out of logic.

**Cost.** `actor_count` counts events, not distinct actors, beyond a check
against the previous actor. Exact distinct counts would need read-time
grouping, which we judged not worth it.

---

## ADR-015 — Groups get an index table beside their post

**Status:** Accepted

**Decision.** A group's authored content is an `odsi_social_group` post
(ADR-002). Its query-serving attributes — visibility, slug, member count,
last-active — are mirrored into `odsi_social_groups`, keyed by post id, and
kept in step on every save.

**Why.** The feed privacy predicate needs group visibility on every row it
evaluates. Joining `wp_posts` and `wp_postmeta` inside the hottest query in the
plugin is the kind of decision that works in development and fails at scale.
A narrow index table joined on its primary key costs almost nothing.

**Cost.** Two writes per group save, and a consistency job in the daily
maintenance that re-mirrors any drift.

---

## ADR-016 — The privacy rule has one owner and two representations

**Status:** Accepted

**Decision.** `Activity\Privacy` is the only class that reads an item's
`privacy` or a group's visibility. It exposes `can_view()` for single items
and `where_clause()` for feed queries. A single test table drives both.

**Why.** The functional spec fixes the rule as a decision table. A rule that is
implemented once in PHP and once in SQL is still one rule if a single test
proves the two agree on every row; a rule implemented ad hoc in five read paths
is five rules. The class is the enforcement point: any other class touching
those columns fails review.

**Cost.** Grant-style overrides via `odsi_social_can_view_activity` cannot be
pushed into SQL; they apply to single items only, and the docblock says so.
