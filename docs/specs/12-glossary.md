# Glossary

One definition per term. When two documents disagree with each other, the one
that agrees with this file is right.

Terms are grouped by domain. Where two terms are commonly confused, the entry
says explicitly how they differ.

## Learning

**Course** — The unit a learner enrolls on. Owns an ordered outline of nodes.
Has an access mode and, optionally, an access window. A course with no nodes
can be published but can never be completed.

**Node** — Any item in a course outline: a lesson, a topic or a quiz. "Step" is
a synonym used in code and URLs; prefer *node* in prose because a *section
lesson* is a node that a learner never explicitly steps through.

**Outline** — The ordered, flattened list of a course's nodes, derived at read
time from parent relationships and `menu_order` (ADR-006). Order is: each lesson,
then its topics in order (each topic followed by its own quizzes), then the
lesson's quizzes; course-level quizzes last.

**Lesson** — A node directly under a course. Comes in two forms that behave
differently and must be named differently:

- **Leaf lesson** — A lesson with no topics. The learner completes it by marking
  it complete. It gates the node after it.
- **Section lesson** — A lesson with one or more topics. It is a container: it
  completes automatically when every node beneath it completes, is never marked
  complete by the learner, and is **never a gate**.

**Topic** — A node under a lesson. Always a leaf. Completed by marking complete.

**Quiz** — A node under a course, a lesson or a topic, made of questions.
Completed by passing. A quiz gates the node after it in outline order and
nothing else; it does not retroactively lock its parent.

**Gate** — A node whose completion is required before the next node unlocks,
when the course uses linear progression. Every leaf node is a gate. Section
lessons are not. The gate for node *N* is the nearest preceding non-section node
in the outline.

**Question** — Belongs to exactly one quiz. Has a type, a point value and an
answer definition. Types in v1: single choice, multiple response, true/false,
fill in the blank, essay.

**Attempt** — One sitting of a quiz by one learner. Retained forever. Has a
lifecycle: in progress → completed or abandoned.

**Pass** — An attempt whose percentage meets or exceeds the quiz's pass mark and
that needs no manual grading. A learner who has ever passed a quiz has completed
that node, regardless of later attempts.

**Enrolled** — The learner has an enrollment row for the course, in any status.
Do not use this to mean "may open the course"; that is *has access*.

**Has access** — The learner may open the course's content. True when the
enrollment status is `active` or `completed` and the access window, if any, has
not closed. Also true for open-access courses regardless of enrollment, and for
instructors of the course.

**Access mode** — Per course: `open` (anyone, no enrollment needed), `free`
(self-enroll with one click), `paid` (enrollment through a commerce
integration), `closed` (enrollment only by an administrator or a cohort).

**Access window** — Optional number of days from enrollment after which access
ends. Zero means unlimited.

**Drip** — A per-node release rule: on a fixed date, or a number of days after
enrollment. A node whose drip has not elapsed is inaccessible even when its gate
is complete.

**Linear progression** — Per course: when on, nodes unlock in outline order
behind their gates. When off, every dripped node is accessible immediately.

**Progress** — Per learner per node: a row recording started, in progress or
completed. Course progress is derived: completed nodes present in the current
outline, divided by nodes in the current outline.

**Complete (course)** — Every node in the outline is complete. Flips the
enrollment to `completed`. Fires `odsi_lms_course_completed`.

**Cohort** — An administrative grouping of learners (a class, a client's staff,
a semester intake) attached to one or more courses. Membership of a cohort
enrolls the member on the cohort's courses. Distinct from a *group*: a cohort
is an LMS construct about *who is taught together*; a group is a social
construct about *who talks together*. The bridge may link one to the other.

**Instructor** — A user who may author courses and see reports for them. The
`odsi_instructor` role, or any user with `manage_odsi_lms`. For a specific
course, the course's author is also treated as its instructor.

**Learner** — Any user in relation to a course they are enrolled on. Not a role.

**Certificate** — A template post rendered into an award when a learner completes
a course that references it. An **issued certificate** is the immutable record
of that award, with a public verification code.

**Submission** — A learner's upload or text in response to an assignment
lesson, awaiting or holding a grade.

## Community

**Member** — Any registered user, viewed through the social plugin. Every user
is a member; there is no opt-in.

**Profile** — A member's public page: avatar, cover image, display name,
extended profile fields, and their activity.

**Profile field** — An admin-defined field with a type, a field group, a
visibility rule and whether the member may change that rule.

**Connection** — A **mutual** relationship between two members created by a
request from one and an acceptance by the other. Symmetric: if A is connected to
B, B is connected to A. Either side can remove it.

**Follow** — A **one-way** relationship. A follows B means B's activity appears
in A's personal feed. Needs no acceptance. Not symmetric. Distinct from a
connection: connecting does not follow, following does not connect, and a member
may do either without the other.

**Activity** — A single item in a feed: a member's update, a comment, a system
event such as "joined a group", or, with the bridge, a course event. Has an
author, a component, a type, a privacy level, and optionally a group.

**Update** — An activity authored directly by a member as free text. The thing
the "post" box creates.

**Comment** — An activity whose parent is another activity. In v1, comments are
one level deep: a comment on a comment is a comment on the original item.

**Reaction** — A member's typed response to an activity. v1 ships one type,
`like`. One reaction per member per activity; re-reacting replaces.

**Mention** — `@username` inside an update or comment. Notifies the mentioned
member and links to their profile.

**Feed** — An ordered list of activity a viewer may see. Four scopes:

- **Site feed** — all activity the viewer is permitted to see.
- **Personal feed** — activity by members the viewer follows or is connected
  to, activity in groups the viewer belongs to, and the viewer's own.
- **Group feed** — activity in one group.
- **Profile feed** — activity by one member.

**Privacy (activity)** — Who may see an item: `public` (anyone, including logged
out), `members` (any logged-in member), `connections` (the author's
connections), `only_me`, or `group` (governed by the group's visibility). The
resolution rule is a single decision table in
`11-social-functional-spec.md § Privacy resolution`.

**Group** — A community space with members, roles, a feed and a visibility.
Three visibilities:

- **Public** — listed; content and member list visible to all; anyone may join.
- **Private** — listed; content and member list visible to members only;
  joining requires a request and approval, or an invitation.
- **Hidden** — unlisted; invisible to non-members; invitation only.

**Group role** — `organiser` (full control, at least one per group always),
`moderator` (manage content and members, not settings), `member`.

**Membership** — A member's relationship to a group, with a role and a status:
`active`, `pending` (requested, awaiting approval), `invited`, `banned`.

**Notification** — A per-member record that something happened that concerns
them, with a read state. A member is never notified of their own action.

**Thread** — A private message conversation between a fixed set of members. In
v1, exactly two. The same two members always share one thread.

**Message** — One entry in a thread.

## Platform

**Bridge** — The `odsi-bridge` plugin. The only place course concepts and
community concepts may be mentioned in the same file.

**Hook surface** — The set of documented actions and filters a plugin publishes.
It is the integration contract (ADR-005) and is versioned like an API.
