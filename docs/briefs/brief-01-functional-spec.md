# Brief 01 — v1 functional specification

## Objective

Turn the scope matrix into a behavioural specification precise enough that two
people building different halves of the platform independently would produce
compatible software. Every v1 capability gets user stories with testable
acceptance criteria, and every open question in `docs/03-current-state.md` gets
a decided answer with a stated reason.

This is the document the test suite is written from. If a behaviour is not
specified here, it is not tested, and it will be built twice differently.

## Inputs

Read, in order:

1. `CLAUDE.md`
2. `docs/00-project-brief.md` — success criteria and non-goals
3. `docs/04-parity-scope.md` — the v1 line
4. `docs/03-current-state.md` — what exists, and the open questions at the end
5. `docs/01-decisions.md` — decisions you may not relitigate
6. `plugins/odsi-lms/src/` — the LMS scaffold, so the spec matches reality or
   consciously overrides it

## Constraints

- Specify **v1 only**. Note v2 implications in a single line where a v1 decision
  would foreclose them; do not design v2.
- Do not contradict an accepted ADR. If you believe one is wrong, write the
  counter-argument as a proposed superseding ADR in `docs/01-decisions.md` and
  carry on specifying the accepted decision.
- Behaviour, not implementation. Name a class only where the scaffold already
  fixes the behaviour.
- Every acceptance criterion must be checkable by a test. "Progress is accurate"
  is not a criterion; "completing 3 of 6 steps reports 50.00" is.

## Deliverables

### `docs/specs/10-lms-functional-spec.md`

Organised by capability area, matching `docs/04-parity-scope.md`. For each:

- User stories in the form *As a {learner|instructor|admin}, I want X so that Y*.
- Acceptance criteria as Given/When/Then, numbered for reference from tests.
- State machines for anything with a lifecycle — enrollment status, quiz attempt
  status, submission status — as an explicit table of transitions, including the
  ones that must be **rejected**.
- Edge cases, each with the decided behaviour: unenrolled mid-course, course
  content deleted after completion, quiz attempt abandoned, re-enrollment after
  expiry, an instructor previewing a locked step.
- Permission matrix: for each action, which role may perform it, on whose
  content, in what state.

### `docs/specs/11-social-functional-spec.md`

Same structure for the social plugin's v1 surface: members, connections,
activity, groups, notifications, messaging.

Give particular care to:

- **Privacy resolution.** One clear rule for who can see a given activity item,
  as a function of its own privacy, its group's visibility, and the viewer's
  relationship to the author. Write it as a decision table. This rule is the
  thing most likely to be implemented inconsistently in five places.
- **Feed composition.** What appears in the site-wide, group and personal feeds,
  and what it means to follow someone.
- **Notification triggers.** An exhaustive table: event, who is notified, who is
  explicitly not (an author is never notified of their own action), and what
  collapses into a single notification.

### `docs/specs/12-glossary.md`

Every domain term with one definition: step, course, cohort, group, connection,
follow, member, activity, reaction, thread. Ambiguous pairs must be
distinguished explicitly — *cohort* vs *group*, *connection* vs *follow*,
*enrolled* vs *has access*.

### Updates to existing files

- `docs/03-current-state.md` — replace the "Open questions" section with a link
  to where each is now answered.
- `docs/01-decisions.md` — a new ADR for each open question you closed that
  turned on a real trade-off rather than a detail.

## Definition of done

- [ ] Every v1 row in `docs/04-parity-scope.md` maps to at least one numbered
      acceptance criterion.
- [ ] Every open question in `docs/03-current-state.md` is answered, with the
      reason stated.
- [ ] Every lifecycle has a transition table including rejected transitions.
- [ ] The activity privacy rule is a single decision table, referenced from
      everywhere else rather than restated.
- [ ] No acceptance criterion needs a human to interpret it before it can be
      turned into a test.
- [ ] Committed on the working branch with a message explaining the decisions
      that were close calls.

## Out of scope

- Writing code. This brief produces documents only.
- UI design, wireframes, copy.
- v2 and later capabilities.
- Revisiting the four accepted architectural decisions.
