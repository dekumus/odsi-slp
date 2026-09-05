# Conventions

House style for the repository. Consistency here is what lets an agent or a new
contributor extend the codebase without reading all of it first.

## Naming

| Thing | Pattern | Example |
| --- | --- | --- |
| PHP namespace | `ODSI\LMS\`, `ODSI\Social\`, `ODSI\Bridge\` | `ODSI\LMS\Courses\Progress` |
| Post type | `odsi_<singular>` | `odsi_course`, `odsi_social_group` |
| Taxonomy | `odsi_<singular>_<facet>` | `odsi_course_category` |
| Custom table | `{$wpdb->prefix}odsi_<plugin>_<plural>` | `wp_odsi_lms_enrollments` |
| Post meta | `_odsi_<name>` (underscore-prefixed: hidden) | `_odsi_course_id` |
| Option | `odsi_<plugin>_<name>` | `odsi_lms_settings` |
| Action / filter | `odsi_<plugin>_<event>` | `odsi_lms_course_completed` |
| REST namespace | `odsi-<plugin>/v1` | `odsi-lms/v1` |
| Text domain | plugin slug | `odsi-lms` |
| CSS class | `odsi-<plugin>-<block>__<element>--<modifier>` | `odsi-lms-outline__item--depth-1` |
| Script/style handle | plugin slug, optionally suffixed | `odsi-lms-course-builder` |

Post type names are capped at 20 characters by WordPress. Check before inventing
one.

## PHP

- `declare( strict_types = 1 );` at the top of every file, after the docblock.
- `defined( 'ABSPATH' ) || exit;` after the namespace declaration.
- WordPress Coding Standards, tabs for indentation.
- Type every parameter, property and return. Use `?T`, union types and
  `T|WP_Error` rather than returning `false` for errors.
- Constructor property promotion for dependencies; no service locator lookups
  inside domain classes.
- `final` by default. Open a class for extension deliberately, not by omission.
- One class per file, PSR-4 path matching the namespace.
- Docblocks on every class, method and property. Say *why*, not what the
  signature already says.

## Database

- Every table name comes from a `Schema::table()` lookup. Never build one at a
  call site.
- Every value goes through `$wpdb->prepare()`. Table names are interpolated only
  from `Schema` and always with a `phpcs:ignore` naming the reason.
- All SQL lives in a `Repositories\*` class. Services and controllers do not
  touch `$wpdb`.
- Every foreign key column gets an index. Every "find rows for this user on this
  course" access pattern gets a composite index in that column order.
- Timestamps are `datetime` in UTC, written with `current_time( 'mysql', true )`.
- Schema changes bump `Schema::DB_VERSION` and are applied through `dbDelta()`,
  which is additive: it never drops a column, so removals need an explicit
  migration step.

## Hooks

- Fire an action after any state change a third party could reasonably care
  about, with enough arguments to act on it without a second query.
- Put a filter in front of any decision someone might want to override:
  permissions, ordering, labels, query args, computed results.
- Document every hook with a docblock listing each parameter. These docblocks
  are the integration contract.
- Never remove or change a hook signature without a deprecation cycle.

## Security

- Capability check every mutation at the **service** layer, and again in the
  route or form handler. UI-only checks are not checks.
- Nonce every form and admin-post action; REST routes use the standard
  `wp_rest` nonce and a real `permission_callback` (never `__return_true` on a
  write route).
- Sanitise on input, escape on output, every time, even when the value came from
  our own code five lines earlier.
- `wp_unslash()` before sanitising anything from `$_POST` or `$_GET`.
- Never trust an id from a request to imply ownership; look the row up and
  compare.

## Front end

- Templates live in `templates/`, are overridable at
  `wp-content/themes/<theme>/odsi-<plugin>/<file>.php`, and receive their data
  as documented variables — they never query.
- CSS is scoped to plugin class names and built on custom properties so a theme
  restyles by redefining tokens.
- **Shared design tokens.** Both plugins derive their own tokens
  (`--odsi-lms-*`, `--odsi-social-*`) from one shared set, so a theme styles
  the whole platform by defining these once on `:root` or `body`:

  | Token | Meaning | Fallback |
  | --- | --- | --- |
  | `--odsi-accent` | Buttons, progress, active states | `#2563eb` |
  | `--odsi-accent-contrast` | Text on the accent | `#ffffff` |
  | `--odsi-surface` | Cards, tracks, quiet backgrounds | `#f5f6f8` |
  | `--odsi-border` | Hairlines and input borders | `#d9dce1` |
  | `--odsi-muted` | Secondary text | `#5b6470` |
  | `--odsi-radius` | Corner radius of buttons, cards and inputs | `8px` |

  A plugin never reads a shared token directly in a rule; it reads its own
  token, whose default is the shared one. Adding a token means adding it to
  both plugins and to this table. The bundled `odsi-learn` theme maps the set
  onto its `theme.json` palette (ADR-019).
- JavaScript is progressive enhancement: every control it binds to is a real
  element rendered by PHP.
- No jQuery in new code.

## Internationalisation

- Every user-facing string wrapped, with the correct text domain literal (never
  a variable or constant).
- `printf` placeholders are numbered (`%1$s`) whenever there is more than one,
  and preceded by a `/* translators: */` comment.

## Commits

- Subject line in the imperative, under ~72 characters.
- Body explains why the change is shaped the way it is. Assume the reader has
  the diff and lacks the context.

One documented exception: `odsi-bridge` modules resolve the two plugins'
services from their containers lazily inside hook callbacks, because the
bridge must not hold references to either plugin at boot.
