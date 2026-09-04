# Current state

Accurate as of commit `ca23590`. Read this before writing code, and update it
when you change what it describes.

## Summary

| Plugin | State |
| --- | --- |
| `odsi-lms` | Scaffolded and coherent. ~5,300 lines. Boots, installs, registers content, exposes REST. Not yet proven by tests, never run against a live WordPress. |
| `odsi-social` | Empty directory. Nothing written. |
| `odsi-bridge` | Empty directory. Nothing written. |

Nothing in this repository has been executed against a real WordPress install.
Every PHP file passes `php -l` and every internal class reference resolves; that
is the entire extent of the verification so far. **Treat the LMS scaffold as a
considered proposal, not as working software.**

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

- `Courses\Structure` — flattens a course into an ordered step list; resolves
  next/previous; per-request cached. The single source of truth for ordering.
- `Courses\Enrollment` — enroll, unenroll, access-window calculation, fires
  `odsi_lms_user_enrolled` / `_unenrolled`.
- `Courses\Progress` — step completion, derived course percentage, automatic
  course completion, fires `odsi_lms_step_completed` / `_course_completed`.
- `Courses\Access` — composes enrollment, drip schedule and linear progression
  into one decision; filters `the_content` to lock steps.
- `Quizzes\Grader` — grades single, multiple, true/false, fill-blank and essay
  answers; extensible via `odsi_lms_grade_answer`.
- `Quizzes\QuizService` — attempt lifecycle, attempt limits, scoring, marks the
  quiz step complete on a pass that needs no manual grading.

### Interfaces

- REST `odsi-lms/v1`: `GET /courses/<id>/outline`,
  `POST /courses/<id>/enroll`, `POST /steps/<id>/complete`,
  `POST /quizzes/<id>/attempts`, `POST /attempts/<id>/submit`.
- Admin: top-level "Learning" menu, placeholder dashboard and reports screens,
  classic relationship meta boxes on every child post type.
- Front end: theme-overridable templates for course, lesson, quiz and archive;
  five shortcodes (`odsi_course_outline`, `odsi_course_progress`,
  `odsi_enroll_button`, `odsi_my_courses`, `odsi_course_grid`); token-based CSS;
  progressive-enhancement JS.

### Extension points already published

`odsi_lms_register_services`, `odsi_lms_bootable_services`, `odsi_lms_booted`,
`odsi_lms_post_type_definitions`, `odsi_lms_course_outline`,
`odsi_lms_pre_enroll`, `odsi_lms_user_enrolled`, `odsi_lms_user_unenrolled`,
`odsi_lms_step_completed`, `odsi_lms_course_completed`,
`odsi_lms_can_access_course`, `odsi_lms_can_access_step`,
`odsi_lms_grade_answer`, `odsi_lms_quiz_started`, `odsi_lms_quiz_completed`,
`odsi_lms_rest_controllers`, `odsi_lms_locate_template`,
`odsi_lms_enqueue_frontend_assets`.

## Known gaps in the LMS scaffold

These are deliberate omissions, not oversights — but they are all still missing.

1. **No tests, and no test harness at all.** Highest priority.
2. **Reports and dashboard are placeholder screens.** No reporting queries exist.
3. **Certificates**: the table exists; nothing issues, renders or verifies one.
4. **Submissions**: the table exists; no upload, grading UI or service.
5. **The React course builder is not written.** `Support\Assets` looks for a
   compiled bundle and silently skips it; the classic meta boxes are the only
   editing UI. No build tooling (`package.json`, `@wordpress/scripts`) exists.
6. **No blocks.** Shortcodes only.
7. **The quiz player is a mount point.** `templates/single-quiz.php` renders an
   empty div; no JS renders questions or posts answers.
8. **Manual grading has no interface**, so `needs_grading` answers are a dead end.
9. **No object caching** anywhere. `Structure` caches per request only.
10. **`Migrator::maybe_migrate()` runs on every request** including the front
    end. It short-circuits on a version compare, but should move behind
    `admin_init` or a scheduled check.
11. **No commerce integration** — `access_mode = paid` is declared and enforced
    but nothing can fulfil it.
12. **No cron handler.** `Installer` schedules `odsi_lms_daily_maintenance` but
    nothing is hooked to it; `EnrollmentRepository::expire_due()` is written and
    never called.
13. **No i18n build**, no `.pot` file.
14. **No `Structure` cache invalidation on post save** beyond the builder's
    explicit `flush()`.

## Open questions for the spec

- Does a topic-level quiz block the parent lesson, or only itself?
- Is course completion "every step" or "every required step", and does that mean
  a per-step `required` flag?
- Do cohorts (`odsi_cohort`) grant enrollment, or only group reporting? What
  happens to a learner's progress when they leave one?
- Should progress rows be retained after unenrollment by default?
- How does drip interact with a learner who enrolls, lapses and re-enrolls?
