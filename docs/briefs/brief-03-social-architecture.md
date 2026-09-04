# Brief 03 — `odsi-social` architecture and schema

## Objective

Design the social plugin completely before writing it: domain model, database
schema, service boundaries, hook surface and REST API. Then scaffold it to the
same standard as `odsi-lms`, so the two plugins are recognisably the same
codebase.

The schema is the expensive decision here. An activity table takes a write on
almost every user action and is read on almost every page; getting its shape and
indexes wrong is the kind of mistake that is discovered at 10,000 members and
fixed by a migration nobody wants to run.

## Inputs

1. `CLAUDE.md`
2. `docs/specs/11-social-functional-spec.md` — the behaviour being designed for
   (produced by brief 01; do not start before it exists)
3. `docs/01-decisions.md` — ADR-001 (from scratch), ADR-002 (hybrid storage),
   ADR-004 (own kernel), ADR-005 (hooks only)
4. `docs/02-conventions.md`
5. `plugins/odsi-lms/src/` — the pattern to mirror: `Plugin`, `Container`,
   `Contracts\Bootable`, `Database\Schema`, `Repositories\AbstractRepository`,
   `Support\Meta`

## Constraints

- **Mirror the LMS kernel.** Same container, same `Bootable` contract, same
  migrator pattern, same repository base class — copied into `ODSI\Social\` per
  ADR-004, not shared. A developer who has read one plugin should not have to
  learn a second set of idioms.
- **No reference to any `ODSI\LMS\*` symbol**, in any file, ever. Course-aware
  behaviour belongs to `odsi-bridge`.
- **Hybrid storage per ADR-002.** Groups are a post type because a human authors
  their name, description and cover image. Memberships, activity, connections,
  notifications and messages are custom tables.
- Design for pagination by indexed cursor, not `OFFSET`. A feed at page 500 must
  cost the same as page 1.
- Every table gets its access patterns written down *before* its indexes, and
  every index must be justified by a named query.

## Deliverables

### `docs/specs/20-social-architecture.md`

- Domain model: entities, their relationships, and the invariants that must hold
  (a connection is symmetric; a follow is not; a group always has at least one
  organiser).
- Component map: which service owns which behaviour, and what each depends on.
- The **activity privacy resolution** algorithm, implemented once, named, and
  referenced by every read path — feed, single item, notification, search.
- Hook surface: every action and filter the plugin will publish, with signature
  and the reason it exists. This is a public contract; design it deliberately
  rather than adding hooks reactively later.
- REST surface under `odsi-social/v1`: routes, methods, parameters, response
  shapes, permission rules.
- Caching strategy: what is cached, keyed how, invalidated by what.

### `docs/specs/21-social-schema.md`

For each table: purpose, full column list with types and nullability, every
index with the query that justifies it, expected row-growth rate, and the
retention or archival answer for the ones that grow without bound.

Cover at least: activity, activity meta, reactions, group members, connections,
follows, notifications, messages, message recipients, profile field definitions,
profile field data.

Two design questions to settle explicitly, with the reasoning recorded:

1. **Comments on activity.** A `parent_id` on the same table, or a separate
   table? State the read-path cost of each for "feed of 20 items with their
   three most recent comments".
2. **Notification aggregation.** Are "12 people liked your post" collapsed at
   write time or at read time? Both are defensible; the choice constrains the
   schema, so make it now.

### Plugin scaffold

`plugins/odsi-social/` built to the level `odsi-lms` is at today:

- `odsi-social.php` with header, environment guard, PSR-4 fallback autoloader.
- `src/Plugin.php`, `src/Container.php`, `src/Contracts/`, `src/Installer.php`,
  `uninstall.php` (data-preserving by default).
- `src/Database/Schema.php` and `Migrator.php` implementing the designed schema.
- `src/Repositories/` with `AbstractRepository` and one repository per table.
- `src/PostTypes/` registering the group post type and any taxonomies.
- `src/Support/Capabilities.php` with the social role and capability map.
- Service classes with real method signatures and docblocks. Method bodies may
  be minimal where the behaviour is brief 05's job, but the **contract** must be
  complete — no `TODO` in a signature.
- Tests for anything with logic in it, using the harness from brief 02.

### `docs/01-decisions.md`

New ADRs for the two design questions above, and for any other choice a future
maintainer would otherwise reopen.

## Definition of done

- [ ] Every table's every index is justified by a named query in the schema doc.
- [ ] The privacy rule exists in exactly one place in the code.
- [ ] `plugins/odsi-social/` activates cleanly on a WordPress install with
      `odsi-lms` absent, creating all tables and emitting no notice.
- [ ] Grepping `plugins/odsi-social/` for `ODSI\LMS` returns nothing.
- [ ] PHPCS and PHPStan pass over the new plugin at the same level as the LMS.
- [ ] `docs/03-current-state.md` updated to describe the social plugin honestly,
      including what is contract-only.

## Out of scope

- Front-end templates, CSS and JavaScript. A later brief.
- The bridge to the LMS. Brief 04.
- Forums, events, media uploads, real-time delivery — all v2 or later.
- Any BuddyPress compatibility layer. ADR-001 settled this.
