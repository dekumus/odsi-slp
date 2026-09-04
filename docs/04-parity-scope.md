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
| Courses containing lessons; lessons containing topics | v1 | Scaffolded |
| Quizzes attachable to a course, lesson or topic | v1 | Scaffolded |
| Drag-and-drop course builder | v1 | Not started; classic meta boxes are the fallback |
| Ordering via `menu_order` | v1 | Scaffolded |
| Categories, tags, difficulty levels | v1 | Scaffolded |
| Featured image, excerpt, block-editor content | v1 | Inherited from post types |
| Course duplication / cloning | v2 | |
| Shared course steps (one lesson in several courses) | Later | Breaks the single `_odsi_course_id` model; needs a join table |
| Import / export of a course as a portable file | v2 | |
| Course prerequisites | v2 | |
| Course points / required-points gating | Later | |

### Enrollment and access

| Capability | Tier | Notes |
| --- | --- | --- |
| Open, free, paid and closed access modes | v1 | Enforced; `paid` has no fulfilment yet |
| Manual enrollment by an admin | v1 | Service exists, no UI |
| Self-enrollment on free/open courses | v1 | REST route exists |
| Time-limited access (N days from enrollment) | v1 | Scaffolded |
| Unenrollment, with optional progress reset | v1 | Service exists, no UI |
| Cohorts / course groups with a group leader | v2 | Post type registered, no behaviour |
| WooCommerce integration | v2 | The realistic path to `paid` |
| Subscriptions and recurring access | Later | |
| Enrollment expiry notifications | v2 | |

### Learning experience

| Capability | Tier | Notes |
| --- | --- | --- |
| Sequential (linear) progression | v1 | Scaffolded |
| Mark a step complete | v1 | REST + service |
| Progress bar and percentage | v1 | Scaffolded |
| Course outline with locked-state indication | v1 | Scaffolded |
| Resume where you left off | v1 | `Structure::next_step()` exists; no UI uses it |
| Drip-fed content by date or days-after-enrollment | v1 | Scaffolded |
| Video progression (must watch before completing) | v2 | |
| Assignments: upload, grade, feedback | v2 | Table exists; nothing else |
| Notes and bookmarks | Later | |
| Focus / distraction-free course player mode | v2 | |

### Assessment

| Capability | Tier | Notes |
| --- | --- | --- |
| Single choice, multiple response, true/false | v1 | Grader implemented |
| Fill in the blank | v1 | Grader implemented |
| Essay / free text with manual grading | v1 grading, v2 UI | Grader flags it; no marking interface |
| Points per question, pass mark, attempt limits | v1 | Scaffolded |
| Attempt history retained | v1 | Scaffolded |
| Time limits | v1 data, v2 enforcement | Meta exists; nothing counts down |
| Question bank with categories | v1 | Taxonomy registered |
| Question randomisation and per-attempt sampling | v2 | |
| Sorting, matching, ordering question types | v2 | Extend via `odsi_lms_grade_answer` |
| Certificates on completion | v2 | Table exists; nothing issues one |
| Public certificate verification by code | v2 | Column exists |

### Instructor and admin

| Capability | Tier | Notes |
| --- | --- | --- |
| Instructor role with own-content capabilities | v1 | Capability map done |
| Enrollment list per course | v1 | Repository method exists, no screen |
| Progress and completion report | v1 | Nothing exists |
| Quiz results and per-question breakdown | v2 | |
| CSV export of any report | v2 | |
| Bulk enroll / unenroll | v2 | |
| Email notifications on enrollment and completion | v2 | |
| Learner-facing dashboard | v1 | `[odsi_my_courses]` is the seed |

## Social (`odsi-social`)

Nothing here is built. The whole column is a specification target.

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
