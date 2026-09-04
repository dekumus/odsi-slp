# ODSI Social Learning Platform

A WordPress social learning platform: courses people take together. Two
independent GPL plugins plus an optional bridge.

| Plugin | Role | Status |
| --- | --- | --- |
| [`odsi-lms`](plugins/odsi-lms) | Courses, lessons, topics, quizzes, assignments, enrollment, progress, reports, certificates, cohorts, course builder, learner emails | 170 tests + browser E2E passing |
| [`odsi-social`](plugins/odsi-social) | Profiles, activity, groups, connections, notifications, emails, messaging, blocks | 164 tests + browser E2E passing |
| [`odsi-bridge`](plugins/odsi-bridge) | Course activity in the feed, groups linked to courses, shared progress | Built and tested (15 tests) |

Each plugin installs and works alone. The bridge activates only when both are
present.

## Status

Both engines exist and are proven by integration tests against WordPress on
MariaDB: the LMS (enrollment, outlines, progress, access, quizzes, REST) and the
community plugin (privacy, feeds, groups, connections, notifications, messages,
REST), and the bridge between them (course events in the feed, groups linked
to courses, shared progress). PHPCS and PHPStan level 6 are clean across all
three, and the LMS learner flow, the editor's course builder and the community
member flow pass end to end in a real browser against a fresh install with the
default block theme.
Read
[`docs/03-current-state.md`](docs/03-current-state.md) for the honest inventory.

## Start here

| Document | What it covers |
| --- | --- |
| [`CLAUDE.md`](CLAUDE.md) | Repository context, constraints, conventions — loaded automatically by Claude Code |
| [`docs/00-project-brief.md`](docs/00-project-brief.md) | What we are building, for whom, and what success looks like |
| [`docs/01-decisions.md`](docs/01-decisions.md) | Architecture decision record |
| [`docs/02-conventions.md`](docs/02-conventions.md) | Naming, code style, security and hook conventions |
| [`docs/03-current-state.md`](docs/03-current-state.md) | What exists at HEAD, and every known gap |
| [`docs/04-parity-scope.md`](docs/04-parity-scope.md) | Capability matrix with v1 / v2 / later tiers |
| [`docs/briefs/`](docs/briefs) | Self-contained assignments, ready to hand to an agent |

## Next steps

The five briefs in [`docs/briefs/`](docs/briefs) are complete. What remains is
listed as numbered gaps in
[`docs/03-current-state.md`](docs/03-current-state.md) and as the v2 items in
[`docs/04-parity-scope.md`](docs/04-parity-scope.md). Site owners start with
the [owner guide](docs/40-site-owner-guide.md).

## Licence and provenance

GPL-2.0-or-later.

This is an independent implementation. It contains no code from LearnDash,
BuddyBoss or any other commercial plugin, and uses none of their trademarks.
Where those products are mentioned in the documentation it is to describe a
capability area, not to claim any association with them.
