# `odsi-lms` — v1 functional specification

This document specifies behaviour. It is the source the test suite is written
from. Terms are defined in `12-glossary.md`; where this document and the
glossary differ, the glossary wins and this document has a bug.

## Conventions

- Acceptance criteria are numbered `LMS-<AREA>-<nnn>` and referenced from tests
  by that id. Ids are never reused; a withdrawn criterion is struck through, not
  deleted.
- Roles in stories: **learner** (any user in relation to a course), **instructor**
  (author of the course, or any user with `manage_odsi_lms`), **admin** (site
  administrator), **visitor** (logged out).
- "Rejected" means the operation returns an error and changes nothing. Silently
  ignoring an invalid operation is a bug.
- Timestamps are UTC. "Now" is server time at the moment of the request.

---

## 1. Course authoring — `LMS-AUT`

### Stories

- As an instructor, I want to create a course from lessons, topics and quizzes so
  that learners work through it in the order I intend.
- As an instructor, I want to reorder and move content without breaking anyone's
  progress, so that I can improve a course that is already running.
- As an admin, I want instructors to edit only their own courses so that one
  instructor cannot alter another's.

### Criteria

**LMS-AUT-001** Courses, lessons, topics, quizzes, questions, certificates and
cohorts are WordPress post types. A course has an archive at `/courses/` and a
permalink at `/course/{slug}/`; lessons, topics and quizzes have permalinks at
`/lesson/`, `/topic/` and `/quiz/`; questions, certificates and cohorts have no
public URL.

**LMS-AUT-002** A lesson belongs to at most one course, recorded as
`_odsi_course_id`. A topic belongs to exactly one lesson (`_odsi_lesson_id`) and
inherits that lesson's course; setting a topic's course to anything other than
its lesson's course is corrected to the lesson's course on save.

**LMS-AUT-003** A quiz belongs to exactly one of: a course directly, a lesson,
or a topic. It records `_odsi_course_id` always, and `_odsi_lesson_id` when
under a lesson or topic (the topic's id in the latter case). A quiz with no
course is valid to save but appears in no outline.

**LMS-AUT-004** A question belongs to exactly one quiz (`_odsi_quiz_id`). A
question with no quiz is valid to save and is graded by no one.

**LMS-AUT-005** Sibling order is `menu_order` ascending, then publish date
ascending, then id ascending. Ties are therefore impossible.

**LMS-AUT-006** Only published (`publish`) nodes appear in an outline. Draft,
pending, private and trashed nodes are absent, and their progress rows, if any,
are excluded from completion arithmetic while absent.

**LMS-AUT-007** Reparenting a node (changing its course or lesson) leaves its
existing progress rows untouched. Progress rows carry `course_id` as written;
`LMS-PRG-010` defines how stale rows are treated.

**LMS-AUT-008** A user with the `odsi_instructor` role may create, edit,
publish and delete their own courses and nodes, may not edit courses authored by
others, and may not edit non-LMS content. A user with `manage_odsi_lms` may edit
any LMS content.

**LMS-AUT-009** Deleting a course does not delete its nodes. Nodes whose course
no longer exists appear in no outline and remain editable so they can be
reattached.

**LMS-AUT-010** Course access mode is one of `open`, `free`, `paid`, `closed`.
Any other stored value behaves as `closed`.

---

## 2. Outline — `LMS-OUT`

### Criteria

**LMS-OUT-001** The outline of a course is the ordered list: for each lesson in
order — the lesson; then for each of its topics in order — the topic, then the
topic's quizzes in order; then the lesson's quizzes in order. After all lessons,
the course's direct quizzes in order.

**LMS-OUT-002** A lesson with at least one published topic is a *section
lesson*. A lesson with none is a *leaf lesson*. The classification is derived at
read time and can change when topics are published or unpublished.

**LMS-OUT-003** Every node in the outline has exactly one position. A node that
could appear twice (a corrupt relationship) appears at its first position only.

**LMS-OUT-004** The outline of a course with no published nodes is empty. Such a
course reports 0 total nodes and can never be completed.

**LMS-OUT-005** `next(N)` is the node after N in the outline, or none at the
last node. `previous(N)` is the node before N, or none at the first node.
`gate(N)` is the nearest node before N that is not a section lesson, or none.

**LMS-OUT-006** The outline is recomputed whenever a node in it, or a node's
relationship meta, is saved, deleted, trashed, untrashed or has its status
changed. A stale outline observable after any of those events is a bug.

---

## 3. Enrollment — `LMS-ENR`

### Stories

- As a learner, I want to join a free course with one click so that I can start
  immediately.
- As an admin, I want to enroll and remove learners by hand so that I can honour
  offline arrangements.
- As a learner, I want my progress kept if I am removed and re-added so that an
  administrative mistake does not cost me my work.

### State machine

One row per (user, course). States and permitted transitions:

| From | To | Trigger | Notes |
| --- | --- | --- | --- |
| *(none)* | `active` | enroll | `enrolled_at` = now; `expires_at` computed from the course's access window |
| *(none)* | `pending` | enroll with `status = pending` | reserved for commerce awaiting payment |
| `pending` | `active` | activate | `enrolled_at` reset to now |
| `pending` | `cancelled` | cancel | |
| `active` | `completed` | every outline node complete | `completed_at` = now |
| `active` | `expired` | access window elapsed | set by the daily maintenance job or on access check |
| `active` | `cancelled` | unenroll (soft) or admin cancel | |
| `completed` | `active` | progress reset | `completed_at` cleared |
| `completed` | `cancelled` | admin cancel | |
| `expired` | `active` | re-enroll | **`enrolled_at` reset to now**; `expires_at` recomputed |
| `cancelled` | `active` | re-enroll | as above |

Every transition not in the table is rejected. In particular: `pending` →
`completed`, `expired` → `completed`, `cancelled` → `completed`, and any
transition from a state to itself except `active` → `active`, which is an
idempotent no-op.

### Criteria

**LMS-ENR-001** Enrolling a user on a course with no existing row creates one in
`active` with `source` as given (default `manual`), `enrolled_at` = now, and
`expires_at` = now + access window days, or null when the window is zero.

**LMS-ENR-002** Enrolling a user who already has an `active` row changes
nothing and reports success. `enrolled_at`, `source` and `expires_at` are
untouched.

**LMS-ENR-003** Enrolling a user whose row is `expired` or `cancelled`
reactivates it with a fresh `enrolled_at` and recomputed `expires_at`. The
original `enrolled_at` is not preserved anywhere; drip and access windows
restart. *(Closes open question 5.)*

**LMS-ENR-004** The `odsi_lms_pre_enroll` filter runs before any row is written.
Returning false rejects the enrollment with no side effects and no
`odsi_lms_user_enrolled` action.

**LMS-ENR-005** `odsi_lms_user_enrolled` fires exactly once per successful
transition into `active`, never for the `LMS-ENR-002` no-op.

**LMS-ENR-006** Unenrolling deletes the row. Progress rows are retained unless
the caller explicitly requests a reset. *(Closes open question 4.)*

**LMS-ENR-007** Resetting progress deletes every progress row for that user and
course, and every quiz attempt for quizzes in that course, and sets a
`completed` enrollment back to `active`. Issued certificates are not revoked by
a reset.

**LMS-ENR-008** A learner may self-enroll only on `free` or `open` courses. A
self-enroll request on `paid` or `closed` is rejected with a 403 and no row.

**LMS-ENR-009** Enrolling a non-existent user, or on a post that is not a
published course, is rejected. Enrolling on a draft course is rejected for
self-enrollment and permitted for manual enrollment (so cohorts can be prepared
before launch).

**LMS-ENR-010** An `active` row whose `expires_at` is in the past is treated as
`expired` by every access check immediately, before the maintenance job has run.
The maintenance job then persists the state.

**LMS-ENR-011** The daily maintenance job transitions every `active` row with a
past `expires_at` to `expired` and fires `odsi_lms_enrollment_expired` once per
row.

**LMS-ENR-012** Cohorts: adding a user to a cohort enrolls them on each of the
cohort's courses with `source = cohort`, `source_id = cohort id`, without
altering an existing enrollment of a different source. Removing them from the
cohort cancels only enrollments whose source is that cohort. Progress is
retained. *(Closes open question 3. Cohort UI is v2; the rule is fixed now so
the schema and the bridge can rely on it.)*

---

## 4. Access — `LMS-ACC`

### Criteria

**LMS-ACC-001** *Has access to course C* is true for user U when any of: U has
`manage_odsi_lms`; U is C's author; C's access mode is `open`; U's enrollment on
C is `active` or `completed` and not past its `expires_at`. Otherwise false.
Visitors have access only to `open` courses.

**LMS-ACC-002** *Can open node N* is true when all of: U has access to N's course;
N's drip has elapsed for U; and, if the course uses linear progression, `gate(N)`
is none or is complete for U. Instructors of the course and users with
`manage_odsi_lms` bypass drip and progression.

**LMS-ACC-003** Drip `days_after_enrollment = d` has elapsed when
`enrolled_at + d days <= now`. A user with no enrollment (an `open` course) is
treated as enrolled at their first access to the course, recorded as an
enrollment row with `source = open`.

**LMS-ACC-004** Drip `date = D` has elapsed when `D <= now`, for everyone
equally.

**LMS-ACC-005** The course page itself (the post of type `odsi_course`) is
always readable by anyone who can see the post in WordPress terms. Access rules
apply to nodes, not to the course's own content, which is its outline and sales
copy.

**LMS-ACC-006** A node the user cannot open renders, in place of its content, a
notice that says why in one of exactly three ways: log in / enroll (no access to
course), not yet unlocked (gate incomplete), or available on a date (drip). The
node's title remains visible.

**LMS-ACC-007** REST routes apply the same rule as templates. A request to
complete, start or submit against a node the user cannot open is rejected with
403. There is no route that lets a user act on a node they cannot open.

**LMS-ACC-008** `odsi_lms_can_access_course` and `odsi_lms_can_access_step`
filter the final decision. A filter may grant or deny. Filters run after the
built-in rule, receive its result, and receive the ids needed to decide.

---

## 5. Progress — `LMS-PRG`

### Stories

- As a learner, I want to mark a lesson done and see my percentage move so that
  I feel progress.
- As a learner, I want to pick up where I left off so that I never hunt for my
  place.
- As an instructor, I want to add a lesson to a running course without anyone's
  percentage going backwards in a way that looks like lost work.

### Criteria

**LMS-PRG-001** A leaf lesson or a topic is completed by an explicit "mark
complete" action by the learner. Completing an already complete node is an
idempotent success.

**LMS-PRG-002** A quiz node is completed by a passing attempt (`LMS-QZ-020`).
It cannot be marked complete by the learner directly; such a request is rejected.

**LMS-PRG-003** A section lesson cannot be marked complete directly. It is
completed automatically, with its own progress row, at the moment the last of
its descendant nodes completes. *(Closes open question 1.)*

**LMS-PRG-004** Marking complete is rejected when the user cannot open the node
(`LMS-ACC-002`). This holds for REST and for any future form handler.

**LMS-PRG-005** Course progress percentage = (completed nodes ∩ current outline)
/ (nodes in current outline) × 100, rounded half-up to two decimals. Sections
count as nodes. A course with an empty outline reports 0.00.

**LMS-PRG-006** Completing 3 of 6 nodes reports 50.00; 1 of 3 reports 33.33;
2 of 3 reports 66.67.

**LMS-PRG-007** A course is complete when every node in the current outline is
complete. On the transition, the enrollment moves to `completed` and
`odsi_lms_course_completed` fires once. It does not fire again for later
completions of nodes added afterwards unless the enrollment was first reset to
`active`.

**LMS-PRG-008** Adding a node to a completed course drops the percentage below
100 and leaves the enrollment `completed`. The learner may complete the new node;
the percentage returns to 100; `odsi_lms_course_completed` does not fire again.

**LMS-PRG-009** Removing a node from a course whose learner had completed every
other node completes the course for that learner at their next progress event
(the next completion or access check), not retroactively at removal time.

**LMS-PRG-010** Progress rows for nodes no longer in the outline (deleted,
unpublished, reparented) are ignored by all arithmetic and never cause a
percentage above 100.00. They are retained, so republishing a node restores the
learner's completion of it.

**LMS-PRG-011** "Resume" for a learner on a course is the first node in the
outline that is not complete and that the learner can open; if every node is
complete, the last node; if none is openable (all dripped), the first node.

**LMS-PRG-012** `odsi_lms_step_completed` fires once per node per user on the
first completion only, after the row is written and before course completion is
evaluated.

**LMS-PRG-013** Time spent on a node accumulates in seconds across visits and is
never decremented. It is informational; no rule depends on it.

---

## 6. Assessment — `LMS-QZ`

### Stories

- As an instructor, I want to set a pass mark and a number of attempts so that a
  quiz is a real check, not a formality.
- As a learner, I want to see my score and which questions I missed so that I can
  learn from it.
- As an instructor, I want essay answers held for me to mark so that a learner
  is neither blocked forever nor passed automatically.

### Question types

| Type | Answer definition | Submitted shape | Correct when |
| --- | --- | --- | --- |
| `single` | list of options, exactly one `correct` | one option index | the index is the correct one |
| `true_false` | two options, one `correct` | one option index | as `single` |
| `multiple` | list of options, one or more `correct` | list of indexes | the set equals the correct set exactly; partial credit is not awarded in v1 |
| `fill_blank` | list of accepted strings | one string | normalised submission equals a normalised accepted string; normalisation lowercases, trims and collapses internal whitespace |
| `essay` | none | one string | never automatically; flagged `needs_grading` |

A question with a malformed definition (no `correct` option for `single`, an
empty accepted list for `fill_blank`) is treated as unanswerable: it awards 0
points and contributes its points to the possible total, so a broken question
cannot silently inflate a score.

### Attempt state machine

| From | To | Trigger |
| --- | --- | --- |
| *(none)* | `in_progress` | start, when no `in_progress` attempt exists for (user, quiz) |
| `in_progress` | `completed` | submit |
| `in_progress` | `abandoned` | time limit elapsed at submit or at start of a new attempt |
| `completed` | — | terminal |
| `abandoned` | — | terminal |

### Criteria

**LMS-QZ-001** Starting a quiz when the learner has an `in_progress` attempt
that has not timed out returns that attempt rather than creating another.
Attempts are therefore never wasted by a closed browser.

**LMS-QZ-002** Starting a quiz when the `in_progress` attempt has timed out
marks it `abandoned`, then creates a new attempt if attempts remain.

**LMS-QZ-003** An `abandoned` attempt counts against the attempt limit. Attempts
used = count of rows in `completed` or `abandoned`.

**LMS-QZ-004** Max attempts of 0 means unlimited. Otherwise, starting a new
attempt when attempts used ≥ max is rejected.

**LMS-QZ-005** Submitting an attempt that is not `in_progress` is rejected.
Submitting an attempt belonging to another user is rejected with 404 (not 403,
so that attempt ids are not enumerable).

**LMS-QZ-006** Submission with a time limit of `t` minutes made more than
`t` minutes + 30 seconds grace after `started_at` marks the attempt `abandoned`
and is rejected with a message saying the time expired. Answers are not graded.

**LMS-QZ-007** On submit, every question in the quiz is graded, including those
with no submitted answer, which score 0. Each answer row is written with the
raw submission, points earned, points possible, and correctness.

**LMS-QZ-008** Percentage = points earned / points possible × 100, rounded
half-up to two decimals; 0.00 when points possible is 0.

**LMS-QZ-009** `passed` = percentage ≥ pass mark **and** no answer needs
grading. An attempt with an ungraded essay is `completed` with `passed = false`
until graded.

**LMS-QZ-010** Manual grading of an answer sets its points and clears
`needs_grading`, then recomputes the attempt's totals and `passed`. If the
recomputation makes the attempt pass, the quiz node completes for that learner
at that moment, with `odsi_lms_step_completed` firing then.

**LMS-QZ-020** The quiz node is complete for a learner once any of their attempts
has `passed = true`. Later failing attempts do not un-complete it.

**LMS-QZ-021** A learner may retake a passed quiz while attempts remain. The
best attempt (highest percentage, earliest on ties) is the one reported as their
result.

**LMS-QZ-022** The result returned on submit includes points earned, points
possible, percentage, `passed`, `needs_grading`, and a per-question list of
correct/incorrect. It does not include the correct answers for questions the
learner got wrong when the quiz's "show answers" setting is off; that setting
defaults to on. *(Setting is v1; it is a single boolean.)*

**LMS-QZ-023** `odsi_lms_grade_answer` filters every grade. A third party can
implement a new question type by handling its type string there; the built-in
grader returns 0 points and `needs_grading = false` for an unknown type, so an
unhandled custom type fails closed rather than passing.

---

## 7. Instructor and admin — `LMS-ADM`

### Criteria

**LMS-ADM-001** The Learning admin menu is visible to users with
`manage_odsi_lms`. Reports are visible to users with `view_odsi_lms_reports`.

**LMS-ADM-002** The enrollment report for a course lists each enrolled user with
status, enrolled date, percentage and completed date, paginated, sortable by
each column, filterable by status. An instructor sees it only for courses they
author unless they have `manage_odsi_lms`.

**LMS-ADM-003** An admin can enroll a user on a course, remove them, and reset
their progress from the enrollment report. Each action is nonce-protected and
capability-checked server side.

**LMS-ADM-004** The grading queue lists every answer with `needs_grading = 1`,
oldest first, with the question, the submitted text, and a points input capped
at the question's points. Saving applies `LMS-QZ-010`.

**LMS-ADM-005** The daily maintenance job runs `LMS-ENR-011` and any registered
`odsi_lms_daily_maintenance` listeners. It must be safe to run twice in a day.

---

## 8. Interfaces — `LMS-IF`

### REST — namespace `odsi-lms/v1`

| Route | Auth | Behaviour |
| --- | --- | --- |
| `GET /courses/{id}/outline` | any | Outline with per-node `completed` and `locked` for the caller; `enrolled`, `percentage`, `resume` (node id per `LMS-PRG-011`). 404 for a non-course id. |
| `POST /courses/{id}/enroll` | logged in | `LMS-ENR-008`. 201 with enrollment id; 403 for non-self-serve modes. |
| `POST /steps/{id}/complete` | logged in | `LMS-PRG-001..004`. 200 with course id and percentage; 403 when locked; 400 for a quiz or section. |
| `POST /quizzes/{id}/attempts` | logged in | `LMS-QZ-001..004`. 201 with attempt id and `resumed: bool`; 403 locked; 400 no attempts left. |
| `POST /attempts/{id}/submit` | logged in | `LMS-QZ-005..009`. 200 with result; 404 not own; 400 closed or timed out. |
| `GET /quizzes/{id}/questions` | logged in, can open | Questions with options for rendering; never includes which option is correct. |
| `GET /me/courses` | logged in | Courses the caller is enrolled on with status and percentage. |

Every error response is a `WP_Error` with a machine code prefixed `odsi_lms_`
and a translated message.

### Shortcodes and templates

**LMS-IF-001** Shortcodes `odsi_course_outline`, `odsi_course_progress`,
`odsi_enroll_button`, `odsi_my_courses`, `odsi_course_grid` render from the
templates in `templates/parts/` and resolve the course from context when not
given one.

**LMS-IF-002** Every template in `templates/` is overridable by a file of the
same relative path under `wp-content/themes/{theme}/odsi-lms/`. The override is
used in full; there is no partial merge.

**LMS-IF-003** All front-end pages render their complete state without
JavaScript. JavaScript enhances "mark complete" and "enroll" into in-page
actions and renders the quiz player; without it, the quiz page shows a notice.

---

## 9. Permission matrix

| Action | Visitor | Learner (enrolled) | Learner (not enrolled) | Instructor (own course) | Instructor (other's course) | `manage_odsi_lms` |
| --- | --- | --- | --- | --- | --- | --- |
| View course page | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Open a node | open courses only | ✓ (subject to gate/drip) | ✗ | ✓ (bypasses gate/drip) | ✗ | ✓ |
| Self-enroll | ✗ | n/a | free/open only | ✓ | free/open only | ✓ |
| Mark node complete | ✗ | ✓ if openable | ✗ | ✓ | ✗ | ✓ |
| Start / submit quiz | ✗ | ✓ if openable | ✗ | ✓ | ✗ | ✓ |
| Edit course / nodes | ✗ | ✗ | ✗ | ✓ | ✗ | ✓ |
| View enrollment report | ✗ | ✗ | ✗ | ✓ | ✗ | ✓ |
| Enroll / remove others | ✗ | ✗ | ✗ | ✓ | ✗ | ✓ |
| Grade answers | ✗ | ✗ | ✗ | ✓ | ✗ | ✓ |
| Reset another's progress | ✗ | ✗ | ✗ | ✓ | ✗ | ✓ |
| Change plugin settings | ✗ | ✗ | ✗ | ✗ | ✗ | ✓ |

"Instructor (own course)" includes the course's post author whether or not they
hold the `odsi_instructor` role.

---

## 10. Edge cases

| Scenario | Behaviour | Criteria |
| --- | --- | --- |
| Learner unenrolled mid-course | Loses access immediately; progress rows retained; re-enrolling resumes | ENR-006, ENR-003, PRG-011 |
| Enrollment expires mid-course | As above, via `expired`; access checks treat it as expired before the cron runs | ENR-010, ENR-011 |
| Course content deleted after completion | Enrollment stays `completed`; percentage recalculated from the current outline stays at 100 (fewer nodes, all complete) | PRG-010 |
| Node added after completion | Percentage drops; enrollment stays `completed`; no second completion event | PRG-008 |
| Quiz attempt abandoned | Counts as used; can be resumed if within the time limit, otherwise abandoned on next start | QZ-001..003 |
| Re-enrollment after expiry | Fresh `enrolled_at`; drip and window restart | ENR-003 |
| Instructor previews a locked node | Sees it; bypasses gate and drip; their progress is not recorded unless they act | ACC-002 |
| Learner tries REST on a locked node | 403 | ACC-007 |
| Course with zero nodes | 0.00, never completes, enroll button still works | OUT-004, PRG-005 |
| Topic published under a leaf lesson mid-course | Lesson becomes a section; its existing completion row stands; new topic is incomplete; percentage drops | OUT-002, PRG-010 |
| All topics of a section unpublished | Lesson becomes a leaf; a completion row from its section days stands; it counts complete | OUT-002 |
| Two attempts submitted for the same in-progress row concurrently | Second is rejected as not `in_progress` | QZ-005 |
| Essay quiz is the last node of the course | Course does not complete until graded and passing | QZ-009, QZ-010, PRG-007 |

---

## 11. Decisions that close the open questions

1. **Topic-level quiz gating.** A quiz gates only what follows it in outline
   order (`OUT-005`). Section lessons are never gates and complete automatically
   (`PRG-003`). This also fixes a deadlock in the scaffold, see § 12.
2. **Course completion.** Every node in the current outline (`PRG-007`). No
   per-node "required" flag in v1. The completion check is written as "the set
   of nodes that must be complete", filtered by `odsi_lms_required_step_ids`, so
   a v2 optional flag is a filter implementation, not a schema change.
3. **Cohorts.** Grant enrollment (`ENR-012`). Removal cancels only
   cohort-sourced enrollments; progress is retained.
4. **Progress after unenrollment.** Retained (`ENR-006`); reset is explicit
   (`ENR-007`).
5. **Drip after lapse and re-enroll.** Restarts, because `enrolled_at` is reset
   on reactivation (`ENR-003`).

---

## 12. Where the scaffold at `ca23590` disagrees with this spec

Recorded so the hardening brief has an exact list. Each is a bug to fix with a
failing test first.

| Scaffold behaviour | Spec | Fix |
| --- | --- | --- |
| `Structure::previous_step()` returns the section lesson as the gate for its first topic, which can never be complete before the topic → **deadlock under linear progression** | `OUT-005` gate skips sections | Add `gate()`; use it in `Access` |
| `Progress::complete_step()` lets a learner mark a quiz or a section complete via REST | `PRG-002`, `PRG-003` | Reject by type; auto-complete sections |
| `EnrollmentRepository::enroll()` keeps the old `enrolled_at` on reactivation | `ENR-003` | Reset it when the previous status was not `active` |
| `Enrollment::enroll()` fires `odsi_lms_user_enrolled` on the `active` → `active` no-op | `ENR-005` | Fire only on a real transition |
| `QuizService::start()` always creates a new attempt | `QZ-001`, `QZ-002` | Return an open attempt; abandon timed-out ones |
| `QuizAttemptRepository::count_attempts()` counts `in_progress` rows | `QZ-003` | Count `completed` + `abandoned` |
| No time-limit enforcement | `QZ-006` | Check at submit and at start |
| `Grader` returns `is_correct = false`, 0 points for an unknown type but does not distinguish it from a wrong answer | `QZ-023` | Acceptable; document it |
| No `odsi_lms_enrollment_expired`, no cron listener | `ENR-011`, `ADM-005` | Add both |
| `Migrator::maybe_migrate()` on every request | — (gap 10) | Move behind `admin_init` |
| `Access::filter_content()` shows one generic locked message | `ACC-006` | Three distinct reasons |
| `Access` treats a course author as instructor only when `course_id > 0`; a node with no course resolves to 0 and denies the author | `ACC-001` | Deny is correct: a node with no course is in no outline. Document. |
| Open-access courses never write an enrollment row, so `days_after_enrollment` drip can never elapse for them | `ACC-003` | Write a `source = open` row on first access |
| No `resume` in the outline response; no `GET /me/courses`; no `GET /quizzes/{id}/questions` | § 8 | Add routes |
