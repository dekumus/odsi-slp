# Project brief — ODSI Social Learning Platform

## What we are building

A WordPress-based social learning platform: courses that people take together.
Two plugins, each strong enough to stand alone, that become materially more
useful when installed side by side.

The thesis is that learning outcomes improve when coursework and community are
the same product rather than two bolted-together ones. That is the same bet
BuddyBoss made; the difference is that we own the whole stack and can make the
seams disappear.

## Who it is for

- **Learners** — take courses, track progress, earn certificates, and do all of
  that alongside a cohort rather than alone.
- **Instructors** — author courses without a developer, see who is stuck, grade
  what needs grading, and talk to their cohort in one place.
- **Site owners** — run the platform on their own WordPress install, own the
  data, and extend it without forking.

## Non-goals

Naming these now saves argument later.

- Not a SCORM/xAPI-first LMS. Standards support is a later integration, not a
  foundation.
- Not a headless-only product. The front end ships as themeable PHP templates.
- Not a multi-tenant SaaS. One install, one community.
- Not a payments platform. Commerce integrates through WooCommerce and similar
  rather than being reimplemented.
- Not a BuddyPress fork, and not BuddyPress-compatible. Migration tooling may
  come later; API compatibility will not.

## Success criteria for v1

A site owner can, with no code:

1. Author a course of lessons, topics and quizzes, and publish it.
2. Have a learner enroll, work through it in order, be blocked from skipping
   ahead, pass a quiz, and finish the course.
3. See who enrolled, who finished, and who is stuck.
4. Give every course a group whose members see each other's progress in a feed.
5. Have members follow each other, post, comment, react, and get notified.

Everything beyond that is roadmap.

## Delivery shape

The work splits into layers that can be specified independently:

- **Domain and data model** — entities, relationships, storage, invariants.
- **Services** — the rules that operate on the model.
- **Interfaces** — REST, admin screens, front-end templates, blocks.
- **Integration** — the bridge, and third-party surfaces (commerce, email).
- **Harness** — how any of this is proved to work.

`docs/briefs/` contains one brief per layer, written to be handed to an agent
as a standalone assignment.

## Risk register

| Risk | Why it matters | Mitigation |
| --- | --- | --- |
| Scope. LearnDash and BuddyBoss are each many developer-years. | The obvious failure mode is a half-built everything. | Ruthless v1 scope in `docs/04-parity-scope.md`; anything not marked v1 is explicitly deferred. |
| Activity feed write volume. | The feed table takes a write for nearly every user action and becomes the hottest table on the site. | Indexed custom table from day one, no postmeta; feed reads paginate by indexed cursor, never `OFFSET` on large tables. |
| Postmeta blowup in the LMS. | Progress in postmeta is the single most common reason LearnDash sites fall over. | Custom tables for enrollment, progress and attempts. Already decided; see ADR-002. |
| Coupling the two plugins. | A hard dependency makes both unshippable alone and untestable apart. | Hooks-only communication, enforced by a CI check. |
| IP contamination. | Copying a paid plugin's code is a legal problem, not a style problem. | Reimplement from behaviour. Never paste from a commercial plugin. |
| Theme compatibility. | LMS plugins that fight themes get uninstalled. | Template overrides in `theme/odsi-lms/`, tokens-based CSS, no global selectors. |
