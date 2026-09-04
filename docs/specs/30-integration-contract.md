# Integration contract — `odsi-bridge`

The seam between learning and community. This document is the complete list
of events that cross the boundary, what the bridge does with each, and the
rules that keep ADR-005 intact: neither `odsi-lms` nor `odsi-social` knows the
other exists; only the bridge does, and it may depend on both.

## 1. Posture

- The bridge boots only when both plugins are active and at a compatible
  version. Otherwise it registers an admin notice and deactivates itself; it
  never fatals, and its own data survives for the next activation.
- Every write the bridge performs goes through the owning plugin's public
  service. It never touches another plugin's tables.
- Every effect is idempotent. A re-fired hook or a retried request produces no
  duplicate activity, no duplicate membership, no duplicate link.
- Three integrations, each independently switchable in settings and through
  the `odsi_bridge_modules` filter, because site owners will want two of the
  three.

## 2. Events crossing the boundary

### LMS → community

| LMS hook | Signature | Bridge effect | Visible to | Twice? |
| --- | --- | --- | --- | --- |
| `odsi_lms_user_enrolled` | `($user_id, $course_id, $enrollment_id, $args)` | Posts `learning/enrolled` activity; adds the learner to the linked group as an active member | Group if linked and the learner is an active member of it, else members-only | `external_id = enrolled:{course}:{user}` → one item; membership add is a no-op on an active row |
| `odsi_lms_course_completed` | `($user_id, $course_id)` | Posts `learning/completed` activity | as above | `completed:{course}:{user}` |
| `odsi_lms_quiz_completed` | `($result, $user_id, $quiz_id)` | When `passed`, posts `learning/passed_quiz` activity | as above | `passed_quiz:{quiz}:{user}` → the first pass only |
| `odsi_lms_user_unenrolled` | `($user_id, $course_id, $reset_progress)` | Removes the learner from the linked group unless they are an organiser or moderator | — | Removal of an absent row is a no-op |
| `odsi_lms_enrollment_expired` | `($user_id, $course_id, $row_id)` | As `_user_unenrolled` | — | idem |
| `odsi_lms_cohort_enrollment_cancelled` | `($user_id, $course_id, $cohort_id)` | As `_user_unenrolled` | — | idem |
| `odsi_lms_answer_graded` | `($attempt_id, $question_id, $points, $passed)` | When `$passed`, posts `learning/passed_quiz` for the attempt's quiz | as above | same key as `_quiz_completed` |
| `trashed_post` (course or group) | `($post_id)` | Removes the link | — | — |
| `deleted_post` (course) | `($post_id, $post)` | Removes the course ↔ group link | — | — |

### Community → LMS

| Social hook | Signature | Bridge effect |
| --- | --- | --- |
| `odsi_social_group_deleted` | `($group_id)` | Removes the course ↔ group link. **Does not** touch enrollments. |
| `odsi_social_group_member_left` | `($group_id, $user_id, $via)` | Nothing. Leaving a group never changes enrollment (§ 4). |

Access lost any other way crosses too: `odsi_lms_enrollment_expired` and
`odsi_lms_cohort_enrollment_cancelled` remove the learner from the linked
group exactly like an unenrollment, `trashed_post` on a course or group drops
the link, and `odsi_lms_answer_graded` posts `passed_quiz` for an essay quiz
that passes once graded. Nothing else crosses. In particular the bridge does not post activity for
lesson completion (too noisy for v1; a filter `odsi_bridge_activity_events`
lets a site opt in per event) and does not enroll anyone because they joined
a group (§ 4).

## 3. Hooks the bridge needed that did not exist

| Plugin | Addition | Why |
| --- | --- | --- |
| `odsi-social` | `Groups\Membership::add( $group_id, $user_id, $via )` and `remove_member( $group_id, $user_id )` — system-level activation and removal with no actor permission check (removal spares organisers and moderators), firing the membership actions with `$via` | The state machine's public methods all take an acting member and enforce visibility; a system enrolling a learner into a hidden cohort group has no actor. |

No hook was added to `odsi-lms`; its published events carry everything the
bridge needs.

## 4. Course ↔ group linkage

One course links to at most one group and one group to at most one course.
The link is the bridge's own row in `odsi_bridge_course_groups`.

| Scenario | Outcome |
| --- | --- |
| Admin links course C to group G | Existing active and completed enrollments of C are added to G as members: the first 200 in the request, the rest queued through `odsi_bridge_sync_link` cron events; a link displaced from either side fires `odsi_bridge_course_unlinked` |
| Learner enrolls on C | Added to G as `member`, `via = course_enrollment` |
| Learner is unenrolled from C | Removed from G unless organiser or moderator |
| Member leaves G | Enrollment untouched |
| Member joins G directly | Enrollment untouched; the group is a conversation, not a gate |
| Group G deleted | Link removed; enrollments untouched |
| Course C deleted | Link removed; group untouched |
| Course C unlinked by admin | Memberships untouched |
| Course linked to a *hidden* group | Enrollment adds members exactly as for any group; hidden groups are the natural fit for cohorts |

Bridge activity for a linked course is posted **into** the group with
`privacy = group`, so it follows the group's visibility. For an unlinked course
it is posted with `privacy = members`.

## 5. Progress visibility

Active members of a linked group may see each other's percentage on the linked
course, through `GET /odsi-bridge/v1/groups/{id}/progress` and the
`[odsi_group_progress]` shortcode. Non-members receive 404 (ADR-011); visitors
get the REST layer's 401; community admins may read any linked group. The
percentage is read from the LMS's `Courses\Progress` service, never recomputed.

## 6. Ordering and idempotency rules

- Bridge listeners run at priority 20 so that the owning plugin's own listeners
  have finished.
- Activity idempotency is `(component = learning, external_id)` as defined by
  `SOC-ACT-012`.
- The bridge never fires its own actions during another plugin's action
  handler beyond the `odsi_bridge_*` hooks listed in its code, which exist so a
  site can react to the bridge's writes.

## 7. Deactivation

Deactivating either plugin makes the bridge dormant on the next request (its
dependency check fails, it deactivates itself and shows a notice). Reactivating
both and then the bridge resumes with the same link table; the idempotency keys
mean no historical activity is duplicated.
