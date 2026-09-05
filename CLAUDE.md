# ODSI Social Learning Platform

A WordPress social learning platform built as two independent, interoperating
GPL plugins plus a bridge:

| Plugin | Directory | Role | Comparable to |
| --- | --- | --- | --- |
| `odsi-lms` | `plugins/odsi-lms/` | Courses, lessons, topics, quizzes, enrollment, progress, certificates | LearnDash |
| `odsi-social` | `plugins/odsi-social/` | Profiles, activity feed, groups, connections, notifications, messaging | BuddyBoss / BuddyPress |
| `odsi-bridge` | `plugins/odsi-bridge/` | Optional glue: course activity in the feed, social groups tied to cohorts | BuddyBoss's LearnDash integration |

Each plugin must install, activate and function **on its own**. The bridge
activates only when both are present.

## Hard constraints

1. **No copied source.** LearnDash and BuddyBoss are commercial products. Their
   names, branding and code are off limits. Feature parity is the goal;
   reimplementation from the public behaviour is the method. BuddyPress is GPL
   and may be read for reference, but we are not forking it.
2. **PHP 8.1+, WordPress 6.4+.** Typed properties, enums, `match`, constructor
   promotion and `readonly` are all fair game.
3. **GPL-2.0-or-later** for everything in this repo.
4. **No cross-plugin hard dependencies.** `odsi-social` must never call an
   `ODSI\LMS\*` class directly, and vice versa. They communicate through
   WordPress actions and filters, or through the bridge.
5. **Data belongs to the site owner.** Uninstall never destroys content unless
   the owner explicitly opted in.

## Architectural decisions already made

These are settled. Revisit them only with a written argument in
`docs/01-decisions.md`.

- **Hybrid storage.** Authored content is a custom post type; high-volume
  relational data is a custom indexed table. See ADR-002.
- **Social engine built from scratch**, not on BuddyPress. See ADR-001.
- **Hybrid UI.** React (`@wordpress/components`) for complex admin builders;
  classic PHP templates for front-end pages so themes can override them. See
  ADR-003.
- **Small lazy service container** per plugin, no shared runtime library
  between the two plugins. See ADR-004.

## Conventions

Full detail in `docs/02-conventions.md`. The short version:

- Namespaces `ODSI\LMS\`, `ODSI\Social\`, `ODSI\Bridge\`; PSR-4 from each
  plugin's `src/`.
- Post types `odsi_*`; tables `{$wpdb->prefix}odsi_lms_*` / `odsi_social_*`;
  meta keys `_odsi_*`; options `odsi_lms_*` / `odsi_social_*`.
- Hooks `odsi_lms_*` / `odsi_social_*`. Every decision a third party might want
  to change should already pass through a filter.
- REST namespaces `odsi-lms/v1`, `odsi-social/v1`.
- WordPress Coding Standards, tabs for indentation, Yoda-free but always
  `prepare()`d SQL. Every file starts with `declare( strict_types = 1 );` and
  guards on `defined( 'ABSPATH' ) || exit;`.
- Text domains match the plugin slug. Every user-facing string is translated
  and escaped at output.

## Repository layout

```
docs/                    Specs, decisions and agent briefs
  briefs/                Task briefs written to be handed to an agent
plugins/odsi-lms/        LMS plugin (built and tested, see docs/03-current-state.md)
plugins/odsi-social/     Community plugin (built and tested)
plugins/odsi-bridge/     Integration plugin (built and tested; needs both others)
themes/odsi-learn/       Block theme; may use plugin hooks and post types, never plugin classes
```

## Working agreements

- Read `docs/03-current-state.md` before writing code; it says exactly what
  exists and what is still a stub.
- Prefer extending the existing kernel over introducing a second pattern.
- New custom tables mean a `Schema::DB_VERSION` bump and a migration path.
- Anything a learner or member can reach must be permission-checked at the
  service layer, not only in the UI. Route handlers check access before they
  mutate.
