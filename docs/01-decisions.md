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
