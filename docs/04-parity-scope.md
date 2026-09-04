# Scope and parity targets

The capability areas a mature WordPress LMS and community suite are expected to
cover, each assigned a release. This is the scope contract: **if it is not
marked v1, it is not in v1**, and adding to v1 is a decision, not a drift.

Tiers:

- **v1** — required for the success criteria in `docs/00-project-brief.md`.
- **v2** — expected by buyers, not required to prove the product.
- **Later** — real demand, large surface, no reason to block on it.

The parity targets are described by capability, not by any product's UI. We are
matching what users need to do, not how a competitor draws it.

## LMS (`odsi-lms`)

### Course authoring

| Capability | Tier | Notes |
| --- | --- | --- |
| Courses containing lessons; lessons containing topics | v1 | Built |
| Quizzes attachable to a course, lesson or topic | v1 | Built |
| Course builder in the editor | v1 | Built: sidebar panel with add, move and detach; no drag and drop |
| Ordering via `menu_order` | v1 | Built |
| Categories, tags, difficulty levels | v1 | Built |
| Featured image, excerpt, block-editor content | v1 | Inherited from post types |
| Course duplication / cloning | v1 | Row action; LMS-AUT-013 |
| Shared course steps (one lesson in several courses) | Later | Breaks the single `_odsi_course_id` model; needs a join table |
| Import / export of a course as a portable file | v2 | |
| Course prerequisites | v1 | LMS-ACC-009 |
| Course points / required-points gating | Later | |

### Enrollment and access

| Capability | Tier | Notes |
| --- | --- | --- |
| Open, free, paid and closed access modes | v1 | Enforced; `paid` is sold through WooCommerce or any gateway that fires the purchase actions (LMS-COM) |
| Manual enrollment by an admin | v1 | Built: Reports screen |
| Self-enrollment on free/open courses | v1 | Built |
| Time-limited access (N days from enrollment) | v1 | Built, expired by cron |
| Unenrollment, with optional progress reset | v1 | Built: Reports screen |
| Cohorts / course groups | v1 | Built: membership grants enrollment (ADR-010); no leader role yet |
| WooCommerce integration | v2 | The realistic path to `paid` |
| Subscriptions and recurring access | Later | |
| Enrollment expiry notifications | v1 | Warning before and notice after; LMS-ENR-015/016 |

### Learning experience

| Capability | Tier | Notes |
| --- | --- | --- |
| Sequential (linear) progression | v1 | Built |
| Mark a step complete | v1 | Built |
| Progress bar and percentage | v1 | Built |
| Course outline with locked-state indication | v1 | Built |
| Resume where you left off | v1 | Built: `resume` in the outline route and the continue button |
| Drip-fed content by date or days-after-enrollment | v1 | Built |
| Video progression (must watch before completing) | v2 | |
| Assignments: upload, grade, feedback | v1 | Text and/or file, approve/reject with points and feedback, gates completion |
| Notes and bookmarks | Later | |
| Focus / distraction-free course player mode | v2 | |

### Assessment

| Capability | Tier | Notes |
| --- | --- | --- |
| Single choice, multiple response, true/false | v1 | Built |
| Fill in the blank | v1 | Built |
| Essay / free text with manual grading | v1 | Built: Grading screen |
| Points per question, pass mark, attempt limits | v1 | Built |
| Attempt history retained | v1 | Built |
| Time limits | v1 | Built: enforced at submit with a grace period, countdown in the player |
| Question bank with categories | v1 | Built |
| Question randomisation and per-attempt sampling | v2 | |
| Sorting, matching, ordering question types | v2 | Extend via `odsi_lms_grade_answer` |
| Certificates on completion | v1 | Built: issued, rendered, revocable; print-styled, no PDF |
| Public certificate verification by code | v1 | Built |

### Instructor and admin

| Capability | Tier | Notes |
| --- | --- | --- |
| Instructor role with own-content capabilities | v1 | Built |
| Enrollment list per course | v1 | Built: sortable, filterable list table |
| Progress and completion report | v1 | Built |
| Quiz results and per-question breakdown | v1 | Reports screen and CSV; LMS-ADM-009 |
| CSV export of the enrollment report | v1 | Built (LMS-ADM-006) |
| Bulk enroll / unenroll | v1 | Bulk enroll from a list (LMS-ADM-008); unenroll stays per row |
| Email notifications on enrollment, completion and assignment results | v1 | Built (LMS-ADM-007) |
| Learner-facing dashboard | v1 | Built: `[odsi_my_courses]`, the My courses block, certificates list |

## Social (`odsi-social`)

Every v1 row below is built and tested; see `docs/03-current-state.md`.

### Members

| Capability | Tier |
| --- | --- |
| Member profiles with cover image and avatar | v1 |
| Extended profile fields, admin-defined | v1 |
| Member directory with search and filters | v1 |
| Follow / following | v1 |
| Friend connections (mutual, request-and-accept) | v1 |
| Profile privacy levels | v2 |
| Member types | v2 |
| Blocking and reporting | v2 |
| Profile completion prompts | Later |

### Activity

| Capability | Tier |
| --- | --- |
| Site-wide, group and personal activity feeds | v1 |
| Post an update with text | v1 |
| Comment on an activity | v1 |
| Reactions (at minimum, a like) | v1 |
| Media attachments (image, video, document) | v2 |
| @mentions with notification | v1 |
| Feed filtering by type and scope | v1 |
| Cursor pagination and infinite scroll | v1 |
| Link previews | v2 |
| GIFs, emoji picker, polls | Later |
| Moderation queue | v2 |

### Groups

| Capability | Tier |
| --- | --- |
| Public, private and hidden groups | v1 |
| Join, request-to-join, invite | v1 |
| Roles: member, moderator, organiser | v1 |
| Group activity feed | v1 |
| Group directory | v1 |
| Group forums / discussions | v2 |
| Group events and calendar | Later |
| Sub-groups and hierarchies | Later |

### Communication

| Capability | Tier |
| --- | --- |
| In-app notifications with read state | v1 |
| Notification preferences per type | v2 |
| Private one-to-one messaging | v1 |
| Group messaging threads | v2 |
| Email per notification, with member opt-out | v1 |
| Email digests | v2 |
| Real-time delivery (websockets / push) | Later |

## Bridge (`odsi-bridge`)

| Capability | Tier |
| --- | --- |
| Course events posted into the activity feed (enrolled, completed, passed) | v1 |
| A social group automatically linked to a course | v1 |
| Group members' progress visible to the group | v1 |
| Course-scoped discussion on lessons | v2 |
| Leaderboards and gamification | Later |
| Instructor announcements to a course's group | v2 |

## Deliberately out of scope for the platform

SCORM and xAPI ingestion, a mobile app, a live-video classroom, an
authoring-tool marketplace, multi-site tenancy, and any AI feature. Each is a
product in its own right.
