# Current state

Accurate as of the commit that last touched this file; check `git log -1 -- docs/03-current-state.md`. Read this before writing code, and update it
when you change what it describes.

## Summary

| Plugin | State |
| --- | --- |
| `odsi-lms` | Engine, reports, grading, certificates, cohorts, quiz player and a React course builder in the editor, proven by 30 unit and 107 integration tests, plus the learner flow, the assignment hand-in and the builder end to end in a real browser against WordPress 7.1 with a block theme. PHPCS, PHPStan level 6 and ESLint clean. |
| `odsi-social` | Built: kernel, 15 custom tables, 13 repositories, every v1 domain service, REST namespace, virtual-page router, templates, admin screens, blocks, profile and group settings pages, notification emails. 123 integration tests including the full privacy decision table through both PHP and SQL, plus a browser E2E flow (post, comment, like, connect, group, message, notifications) passing against the block theme. PHPCS, PHPStan level 6 and ESLint clean. |
| `odsi-bridge` | Built: dependency-checked bootstrap that deactivates itself with a notice when either plugin is missing, a link table, three switchable modules (course activity into the feed, course ↔ group linkage with membership sync, group progress visibility), settings screen, course meta box. 8 integration tests. |

Verification so far: the integration suite boots WordPress core with the
plugin as a must-use plugin, runs activation, and exercises enrollment, outline
derivation, progress, access, the quiz lifecycle and every REST route against a
real database. The Playwright flow (publish a course, enroll, be gated, complete, pass a quiz,
reach 100%) passes against a fresh install with the default block theme. It
found two product bugs on its first runs that no PHP test could have: the
plugin's templates never applied on block themes (ADR-017), and questions
created over REST never linked to their quiz because the relationship meta was
not registered for that post type. Both are fixed.

## `odsi-bridge` — what exists

The contract is `docs/specs/30-integration-contract.md`. The bridge depends on
both plugins and neither depends on it; a test scans both for `ODSI\Bridge`.

- `odsi-bridge.php` — boots at `plugins_loaded` priority 10 (after both
  dependencies at 5); `dependency_errors()` checks each namespace constant and
  minimum version; on failure it shows a notice and deactivates itself.
- `Modules\CourseActivity` — `learning/enrolled`, `learning/completed`,
  `learning/passed_quiz` posted through `Activity::post()` with idempotency
  keys, into the linked group when there is one; registers its own renderer.
- `Modules\GroupLinkage` — the `odsi_bridge_course_groups` table; link/unlink;
  enrollment adds to the group via `Membership::add()`, unenrollment removes
  plain members via `Membership::remove_member()`; either side's deletion
  removes the link; course-edit meta box.
- `Modules\ProgressVisibility` — `GET /odsi-bridge/v1/groups/{id}/progress`
  and `[odsi_group_progress]`, members only, 404 otherwise.
- `Admin\SettingsScreen` — three switches under the Learning menu; the
  `odsi_bridge_modules` filter does the same in code.

Known gaps: no front-end control for organisers to link their own group to a
course (admins only, via the course meta box); the enrollment sync on link is
capped at 200 learners and does not yet defer larger cohorts to cron; no
lesson-level activity (deliberately, see the contract).

## `odsi-social` — what exists

Architecture in `docs/specs/20-social-architecture.md`, tables in
`docs/specs/21-social-schema.md`. The kernel is a copy of the LMS kernel under
`ODSI\Social\` (ADR-004); the layout mirrors it file for file.

- **Data**: `Database\Schema` with fifteen tables (activity, activity meta,
  reactions, groups index, group members, members index, three profile tables,
  connections, follows, notifications, threads, participants, messages); one
  repository per table under `Repositories\`.
- **Activity**: `Privacy` (the one rule, `can_view()` + `where_clause()`),
  `Activity` (post, comment, edit, delete, idempotent external post), `Feed`
  (four scopes, cursor pagination, fixed query budget), `Reactions`,
  `Mentions`, `Renderers`.
- **Connections**: `Connections` (state machine), `Follows`.
- **Groups**: `Groups` (create atomically with organiser, settings, delete
  cascade, index mirroring), `Membership` (state machine, organiser
  invariant, successor promotion), `GroupActivity` (joined/created items).
- **Notifications**: `Notifications` (write-time collapse, counts, retention),
  `Listeners` (the spec's trigger table), `Renderers`.
- **Messages**: `Messages` (pair threads, per-participant delete, unread).
- **Members**: `Presence`, `Profiles` (visibility-filtered view, edit form
  model, field values, avatar/cover, message setting), `ProfileFields`,
  `Uploads` (image validation and resizing), `Directory`, `Lifecycle`
  (account deletion cleanup).
- **Notifications**: `Notifications` (write-time collapse), `Listeners`,
  `Renderers`, `Emails` (one email per fresh notification, member opt-out).
- **Interfaces**: REST `odsi-social/v1` with six controllers; `Frontend\Router`
  for `/members/`, `/groups/`, `/activity/`, `/notifications/`, `/messages/`;
  `Frontend\Shortcodes` dispatching to templates under `templates/`;
  `Frontend\Forms` handling the profile edit and group manage pages through
  admin-post so they work without JavaScript; blocks
  `odsi-social/activity-feed`, `member-directory`, `group-directory`
  (`Blocks\Blocks`, editor bundle in `assets/build/blocks.js`); admin
  settings and profile-field screens; progressive-enhancement JS and CSS.

### Known gaps in the social plugin

1. ~~No browser verification.~~ Closed: `tests/e2e/social-member-flow.spec.js`.
2. ~~Avatar and cover upload UI~~ Closed: `Members\Uploads` (type allow-list,
   image check, shrink to `avatar_max_px`), REST upload routes, and the
   profile and group forms.
3. ~~Profile edit form~~ Closed: `/members/{nicename}/edit/`, a plain
   multipart form handled by `Frontend\Forms` (fields, visibilities, photo,
   cover, message setting).
4. ~~Group settings UI~~ Closed: `/groups/{slug}/manage/` for organisers:
   settings, images, requests, roles, bans.
5. ~~Reaction "who" lists~~ Closed for reactions: `Feed::reactors()`, REST, and
   the single item page (SOC-ACT-012). Comment authors were always listed.
6. **No object caching** beyond per-request repository caches and the unread
   counters.
7. **Feed benchmark** asserts a query budget; nothing asserts a time bound
   under a seeded load.
8. ~~Email delivery~~ Closed: `Notifications\Emails`, one email per fresh
   notification, per-member opt-out on the profile settings page
   (SOC-NOT-008). No digests.

## `odsi-lms` — what exists

### Kernel

- `odsi-lms.php` — header, environment guard (PHP 8.1 / WP 6.4), PSR-4 fallback
  autoloader so the plugin runs from a plain checkout without `composer install`,
  activation and deactivation hooks.
- `Plugin` — singleton kernel. Registers every service factory, boots the ones
  implementing `Bootable`, filters the boot list, fires `odsi_lms_booted`.
- `Container` — lazy service locator with circular-dependency detection.
- `Contracts\Bootable`, `Contracts\Repository` — the two interfaces everything
  hangs off.
- `Installer` — creates tables, roles and rewrite rules; schedules a daily cron;
  seeds default settings once.
- `uninstall.php` — drops tables and roles **only** when
  `reset_data_on_uninstall` was explicitly enabled.

### Data layer

`Database\Schema` defines six tables and `Database\Migrator` applies them with
`dbDelta()`, guarded by a `DB_VERSION` option and re-checked on every request.

| Table | Holds | Key indexes |
| --- | --- | --- |
| `odsi_lms_enrollments` | user ↔ course, status, source, access window | unique `(user_id, course_id)`, `(course_id, status)`, `(user_id, status)` |
| `odsi_lms_progress` | one row per user per step | unique `(user_id, object_id)`, `(user_id, course_id)`, `(course_id, status)` |
| `odsi_lms_quiz_attempts` | one row per sitting, retained | `(user_id, quiz_id)`, `(quiz_id, status)`, `(course_id, user_id)` |
| `odsi_lms_quiz_answers` | one row per answered question, answer as JSON | unique `(attempt_id, question_id)`, `needs_grading` |
| `odsi_lms_submissions` | assignment uploads and grading | `(user_id, lesson_id)`, `(course_id, status)` |
| `odsi_lms_certificates` | issued awards and their public codes | unique `code`, `(user_id, course_id)` |

Repositories: `AbstractRepository` (prepare/insert/update/format plumbing),
`EnrollmentRepository`, `ProgressRepository`, `QuizAttemptRepository`.

### Content types

`PostTypes` registers `odsi_course`, `odsi_lesson`, `odsi_topic`, `odsi_quiz`,
`odsi_question`, `odsi_certificate`, `odsi_cohort`. `Taxonomies` registers
course category, tag and level, plus question category. `Support\Meta` is the
single registry of meta keys and their REST schemas.

Relationships are meta-based: a lesson stores `_odsi_course_id`; a topic or quiz
stores `_odsi_lesson_id` and inherits `_odsi_course_id` from it. Ordering is
`menu_order`.

### Services

- `Courses\Structure` — flattens a course into an ordered node list with a
  `section` flag; resolves next/previous and `gate()` (skips sections, ADR-007);
  `is_section()`; per-request cache invalidated on every post, status and
  relationship-meta change.
- `Courses\Enrollment` — enroll (no-op on an active row; fresh `enrolled_at` on
  reactivation), unenroll, access-window calculation, fires
  `odsi_lms_user_enrolled` / `_unenrolled` on real transitions only.
- `Courses\Maintenance` — daily cron: expires lapsed enrollments and fires
  `odsi_lms_enrollment_expired` per row.
- `Courses\Progress` — leaf completion (quizzes and sections are rejected),
  automatic section completion, derived percentage, course completion through
  the `odsi_lms_required_step_ids` filter, `resume_step()`, fires
  `odsi_lms_step_completed` / `_course_completed`.
- `Courses\Access` — composes enrollment, drip and linear progression (via
  `gate()`); `lock_reason()` distinguishes enroll / drip / progression for the
  three locked notices; writes a `source = open` enrollment on first access to
  an open course; filters `the_content`.
- `Assignments\Assignments` — a leaf lesson or topic can require a hand-in
  (text and/or an allow-listed upload); one pending submission at a time;
  approve (points capped, feedback) completes the step through the
  `odsi_lms_can_complete_step` gate on `Progress`; reject allows another try;
  auto-approve option; cleared on progress reset; fires
  `odsi_lms_submission_created` / `_graded`.
- `Quizzes\Grader` — grades single, multiple, true/false, fill-blank and essay
  answers; extensible via `odsi_lms_grade_answer`.
- `Quizzes\QuizService` — resumes an open attempt, abandons timed-out ones,
  counts only closed attempts against the limit, enforces the time limit with
  a 30 s grace at submit, returns a per-question breakdown, `grade_answer()`
  for manual marking that can complete the node; wipes attempts on a progress
  reset.
- `Courses\Cohorts` — cohort membership enrolls on the cohort's courses with
  `source = cohort`; removal cancels only those; progress kept (LMS-ENR-012).
- `Certificates\Certificates` — issues on completion for courses with a
  template, readable unguessable codes, `/certificate/{code}/` rendering with
  placeholders, public verification, revocation.
- `Reports\EnrollmentReport` — enrollment rows with progress (paginated,
  sortable, filterable, percentages in one query), course summary, the manual
  grading queue, paged CSV export.
- `Notifications\Emails` — learner emails on enrollment, completion (with
  certificate link) and assignment decisions, behind the
  `email_notifications` setting and the `odsi_lms_email` filter.
- `Support\Settings` — typed access to the plugin option, edited on the
  Learning → Settings screen.
- `Support\ObjectCache` — outlines in the persistent object cache, invalidated
  on the same events as the per-request cache.
- `Frontend\ContentDecorator` — every piece of front-end LMS UI, through
  `the_content`, for block and classic themes alike (ADR-017).

### Interfaces

- REST `odsi-lms/v1`: `GET /courses/<id>/outline` (with `resume`),
  `POST /courses/<id>/enroll`, `GET /me/courses`, `POST /steps/<id>/complete`,
  `GET /quizzes/<id>/questions`, `POST /quizzes/<id>/attempts`,
  `POST /attempts/<id>/submit`. Builder routes for course editors:
  `GET|POST /courses/<id>/builder`, `POST /courses/<id>/builder/reorder`,
  `DELETE /courses/<id>/builder/<node>` (detaches, never deletes). Assignments:
  `GET|POST /steps/<id>/submissions`, `GET /submissions`,
  `POST /submissions/<id>/grade`. A test-only
  `POST /e2e/questions/<id>/answers` exists when `ODSI_E2E` is defined.
- Admin: "Learning" menu whose dashboard is the enrollment report
  (`WP_List_Table`, enroll / remove / reset actions), a grading queue for
  essays and assignment submissions (approve with points and feedback, or
  reject), classic relationship meta boxes on every child post type, an
  Assignment box on lessons and topics, cohort course and member boxes, and
  the course builder: a block-editor sidebar
  panel on `odsi_course` (`assets/src/course-builder`, compiled to
  `assets/build`, which is committed so a checkout works without Node) that
  shows the outline with drafts, adds lessons, topics and quizzes, moves
  them up and down, and detaches them. It only ever writes the same
  relationship meta and `menu_order` the meta boxes write.
- Front end: content-injected course UI on any theme; classic-theme templates
  for course, lesson, quiz and archive; shortcodes `odsi_course_outline`,
  `odsi_course_progress`, `odsi_enroll_button`, `odsi_my_courses`,
  `odsi_course_grid`, `odsi_certificate_verify`, `odsi_my_certificates`;
  blocks `odsi-lms/course-outline`, `course-progress`, `enroll-button`,
  `my-courses`, `course-grid` rendering through the shortcode code
  (`Blocks\Blocks`, editor bundle `assets/build/blocks.js`, "ODSI" block
  category shared with the community plugin); a quiz player
  (`assets/js/quiz-player.js`) rendering questions, timing, submission and
  results; token-based CSS.

### Extension points already published

`odsi_lms_register_services`, `odsi_lms_bootable_services`, `odsi_lms_booted`,
`odsi_lms_post_type_definitions`, `odsi_lms_course_outline`,
`odsi_lms_pre_enroll`, `odsi_lms_user_enrolled`, `odsi_lms_user_unenrolled`,
`odsi_lms_step_completed`, `odsi_lms_course_completed`,
`odsi_lms_can_access_course`, `odsi_lms_can_access_step`,
`odsi_lms_resume_can_open`, `odsi_lms_required_step_ids`,
`odsi_lms_enrollment_expired`, `odsi_lms_grade_answer`,
`odsi_lms_quiz_started`, `odsi_lms_quiz_completed`, `odsi_lms_answer_graded`,
`odsi_lms_can_complete_step`, `odsi_lms_submission_created`,
`odsi_lms_submission_graded`, `odsi_lms_assignment_mime_types`,
`odsi_lms_assignment_max_bytes`, `odsi_lms_email`, `odsi_lms_emails_enabled`,
`odsi_lms_report_csv_columns`, `odsi_lms_report_csv_row`,
`odsi_lms_rest_controllers`, `odsi_lms_locate_template`,
`odsi_lms_enqueue_frontend_assets`, `odsi_lms_daily_maintenance` (cron).

## Known gaps in the LMS

Numbering is kept from the original list. Struck-through items are closed.

1. ~~No tests, and no test harness at all.~~ Closed by brief 02.
2. ~~Reports and dashboard are placeholder screens.~~ Closed.
3. ~~Certificates.~~ Closed: issued, rendered, verified, revocable. No PDF;
   the page is print-styled.
4. ~~Submissions~~ Closed: `Assignments\Assignments`, REST, front-end hand-in
   form with history, grading in the Grading screen, `AssignmentsTest`.
5. ~~The React course builder is not written.~~ Closed: editor sidebar panel
   backed by `Rest\BuilderController`, covered by `BuilderTest` and the
   `lms-course-builder` E2E flow. Drag and drop is not implemented; ordering
   is by move buttons.
6. ~~No blocks.~~ Closed: five LMS blocks and three community blocks, dynamic,
   with server-rendered editor previews (LMS-IF-004, SOC-IF-004).
7. ~~The quiz player is a mount point.~~ Closed; proven by the E2E flow.
8. ~~Manual grading has no interface.~~ Closed: the Grading screen.
9. ~~No object caching.~~ Closed for outlines. Progress reads are still
   uncached (they are single indexed lookups).
10. ~~`Migrator::maybe_migrate()` on every request.~~ Closed.
11. **No commerce integration** — `access_mode = paid` is enforced but nothing
    can fulfil it.
12. ~~No cron handler.~~ Closed.
13. ~~No i18n build.~~ Closed: `languages/*.pot` for all three plugins, regenerated with `npm run i18n`.
14. ~~No `Structure` cache invalidation on post save.~~ Closed.
15. ~~Cohort behaviour is unimplemented.~~ Closed.
16. ~~No enrollment or grading admin actions.~~ Closed.
17. ~~Reports have no CSV export~~ Closed: paged, formula-safe export from the
    Reports screen (LMS-ADM-006). A per-question quiz breakdown stays v2.
18. ~~The enrollment report loads percentages per row~~ Closed:
    `Progress::course_percentages()` is one GROUP BY query per page.

## Security review (this branch)

Three adversarial reviews (one per plugin plus tooling) were run and every
confirmed finding fixed with a regression test in `SecurityTest` for each
plugin and `BridgeTest`:

- LMS: locked step content leaked through core REST, feeds and search
  excerpts; instructors could place nodes in another author's course through
  the classic meta box; drafts could be completed, attempted and submitted to;
  self-enrollment worked on unpublished courses; learner uploads were
  browsable in the Media Library and media REST; assignment text allowed
  images and links; CSV formula guard missed whitespace triggers; row actions
  in the enrollment table were nested forms and never submitted.
- Community: hidden groups were readable through core REST, sitemaps and
  feeds (ADR-018); group descriptions ran shortcodes and dynamic blocks
  (a self-referencing shortcode took the directory down); any attachment id
  could be claimed as an avatar or cover; no rate limits (request/withdraw
  loops emailed the target each time); mentions were rewritten inside
  attribute values; REST content was not kses-filtered; profiles ignored the
  private-directory setting; trashed groups stayed visible; commenters
  removed from a group kept being notified.
- Bridge and tooling: course events announced draft and private courses;
  the course meta box listed hidden groups to instructors; uninstall
  destroyed links without opt-in; self-deactivation ran on any admin_init;
  link sync stopped at 200 learners; the local dev router served paths
  outside its root; the serve script could overwrite a real site; CI ran
  with a writable token.

Not changed, by design: self-enrolling on a free or open course linked to a
private or hidden group joins that group (documented on the meta box); open
courses record an enrollment on first read (published courses only now).

## Test coverage map

| Spec area | Suite | Tests |
| --- | --- | --- |
| Grader, container, capability map | unit | 30 |
| Schema, activation, roles | integration `SchemaTest` | 6 |
| `LMS-ENR-*` | integration `EnrollmentTest` | 11 |
| `LMS-OUT-*`, `LMS-AUT-005/006` | integration `StructureTest` | 10 |
| `LMS-PRG-*` | integration `ProgressTest` | 11 |
| `LMS-ACC-*` | integration `AccessTest` | 11 |
| `LMS-QZ-*` | integration `QuizTest` | 15 |
| `LMS-IF` REST, `LMS-ACC-007` | integration `RestTest` | 9 |
| `LMS-ADM-*`, `LMS-ENR-007/012`, certificates, cache, CSV, batched percentages | integration `HardeningTest` | 10 |
| `LMS-ADM-007` learner emails | integration `EmailsTest` | 4 |
| Builder routes: tree, add, reorder, detach, ownership | integration `BuilderTest` | 2 |
| `LMS-ASN-*` service, uploads, REST, grading scope | integration `AssignmentsTest` | 9 |
| `LMS-IF-004` block registration and rendering | integration `BlocksTest` | 3 |
| `LMS-ACC-008`, `LMS-AUT-008`, `LMS-ASN-010` regressions | integration `SecurityTest` | 8 |
| Learner flow in a browser | e2e `lms-learner-flow` | 1, passing |
| Course builder in the editor | e2e `lms-course-builder` | 1, passing |
| Assignment hand-in and approval in a browser | e2e `lms-assignment` | 1, passing |
| Blocks on a page and in the editor | e2e `blocks` | 1, passing |
| Profile edit and group manage forms in a browser | e2e `social-settings` | 1, passing |
| Member flow in a browser | e2e `social-member-flow` | 1, passing |
| Social schema, ADR-005 scan | integration `social/SchemaTest` | 20 |
| Privacy decision table, both representations | integration `social/PrivacyTest` | 42 |
| `SOC-ACT-*` | integration `social/ActivityTest` | 14 |
| `SOC-CON-*` | integration `social/ConnectionsTest` | 5 |
| `SOC-GRP-*` | integration `social/GroupsTest` | 9 |
| `SOC-NOT-*` | integration `social/NotificationsTest` | 8 |
| `SOC-NOT-008` notification emails | integration `social/EmailsTest` | 1 |
| `SOC-MSG-*` | integration `social/MessagesTest` | 5 |
| `SOC-MEM-*` | integration `social/MembersTest` | 6 |
| Social REST, ADR-011 | integration `social/RestTest` | 6 |
| Uploads, profile and group forms, image REST routes | integration `social/SettingsFormsTest` | 5 |
| `SOC-IF-004` blocks | integration `social/BlocksTest` | 2 |
| ADR-018, `SOC-ABUSE-*`, `SOC-MEM-004b`, `SOC-NOT-009` | integration `social/SecurityTest` | 9 |
| Integration contract end to end, unpublished courses, full link sync | integration `bridge/BridgeTest` | 10 |

## Open questions

All five questions this section used to hold are now decided in
`docs/specs/10-lms-functional-spec.md` § 11, with the reasoning recorded as
ADR-007 through ADR-010 in `docs/01-decisions.md`:

| Question | Answer | Where |
| --- | --- | --- |
| Does a topic-level quiz block the parent lesson? | No. A quiz gates only the node after it; section lessons never gate and complete automatically. | `LMS-OUT-005`, `LMS-PRG-003`, ADR-007 |
| "Every step" or "every required step"? | Every node in the current outline. A v2 required flag is a filter, not a schema change. | `LMS-PRG-007`, § 11.2 |
| Do cohorts grant enrollment? | Yes, with `source = cohort`; removal cancels only those; progress retained. | `LMS-ENR-012`, ADR-010 |
| Retain progress after unenrollment? | Yes; reset is explicit. | `LMS-ENR-006/007`, ADR-009 |
| Drip after lapse and re-enroll? | Restarts; `enrolled_at` resets on reactivation. | `LMS-ENR-003`, ADR-008 |

The spec also lists, in § 12, fourteen places where the scaffold at `ca23590`
disagrees with the decided behaviour. Those are the hardening brief's queue.
