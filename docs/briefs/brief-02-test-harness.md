# Brief 02 — test harness and developer environment

## Objective

Make this repository executable and verifiable. Right now nothing in it has ever
run against WordPress; the only check performed has been `php -l`. Build the
environment, the test suites and the CI that turn "it should work" into "it is
proven to work on every push", and prove the harness itself by using it to test
the existing LMS scaffold.

## Inputs

1. `CLAUDE.md`
2. `docs/02-conventions.md` — the standards CI must enforce
3. `docs/03-current-state.md` — the gap list, especially items 1–14
4. `plugins/odsi-lms/` — the code under test
5. `docs/specs/` if brief 01 has landed; otherwise test the scaffold's actual
   behaviour and note where it looks wrong rather than guessing at intent

## Constraints

- The whole harness must run offline after a single install step, and must run
  identically in CI and on a developer's machine. A test that passes only on one
  is worse than no test.
- Fast feedback matters more than exhaustive coverage. Unit tests that need no
  database must be runnable in seconds, separately from integration tests.
- No test may depend on the ordering of another, on a fixed post id, or on a
  previous test's leftover rows.
- The harness serves both plugins. Do not build something that only fits the
  LMS; `plugins/odsi-social/` is empty today and must slot in without rework.

## Deliverables

### Environment

- `.wp-env.json` at the repo root mapping both plugin directories into a
  WordPress install, pinned to a specific WordPress and PHP version matching the
  plugin headers (WP 6.4+, PHP 8.1+).
- A root `package.json` with scripts covering: start/stop the environment,
  run each test suite, lint, and build assets.
- A root `composer.json` with the PHP dev toolchain, and per-plugin
  `composer.json` files kept as the distributable autoload manifests.
- `docs/DEVELOPMENT.md` — the setup path from a fresh clone to a green test run,
  written for someone who has never used `wp-env`, including how to reset a
  broken environment.

### Static analysis

- `phpcs.xml.dist` — WordPress Coding Standards, with the project's text
  domains, prefixes and minimum PHP/WP versions configured so
  `WordPress.WP.I18n` and `PHPCompatibilityWP` actually fire.
- `phpstan.neon.dist` with `szepeviktor/phpstan-wordpress`. Start at the highest
  level that passes on the current code with a baseline file, and record the
  target level in the config as a comment.
- Both must run over both plugins and pass on the committed code, either cleanly
  or via an explicit, committed baseline.

### Test suites

- **Unit** (`tests/unit/`, PHPUnit, Brain Monkey or equivalent, no WordPress
  bootstrap). Cover the pure logic first: `Quizzes\Grader` for every question
  type including malformed and empty answers; `Container` resolution and its
  circular-dependency error; `Support\Capabilities` map generation.
- **Integration** (`tests/integration/`, the WordPress PHPUnit suite with a real
  database). Cover, at minimum:
  - Activation creates all six tables with the expected indexes, and running the
    migrator twice is a no-op.
  - Enrollment: enroll, duplicate enroll, access expiry, unenroll with and
    without progress reset.
  - `Courses\Structure`: outline ordering across lessons, topics and quizzes;
    next/previous at both boundaries.
  - `Courses\Progress`: percentage arithmetic, completing every step marking the
    course complete, and progress rows for deleted steps not pushing past 100%.
  - `Courses\Access`: linear progression blocking, both drip modes, instructor
    bypass, logged-out access.
  - Quiz lifecycle: attempt limits, scoring, a pass marking the step complete,
    and an essay answer correctly **not** completing the step.
  - Every REST route: happy path, unauthenticated, unauthorised, and a
    not-found id. `POST /steps/<id>/complete` must be proven to reject a locked
    step — that is the route most likely to become a way to skip a course.
- **E2E** (`tests/e2e/`, Playwright against `wp-env`). One flow, end to end:
  publish a course with two lessons and a quiz, enroll as a learner, be blocked
  from lesson 2, complete lesson 1, pass the quiz, see 100%.
- **Fixtures** (`tests/fixtures/` or a shared factory trait). Factories for
  course-with-content, enrolled learner, and quiz-with-questions. Every
  integration test builds its world from these, not from inline `wp_insert_post`
  calls.

### CI

- `.github/workflows/ci.yml`: lint, static analysis, unit, integration and E2E,
  as separate jobs so a failure names itself. Matrix across the supported PHP
  versions. Cache Composer and npm.
- A job asserting **ADR-005**: `plugins/odsi-lms/` contains no `ODSI\Social`
  reference and `plugins/odsi-social/` contains no `ODSI\LMS` reference.
- A job asserting each plugin activates cleanly on its own, with
  `WP_DEBUG`/`WP_DEBUG_DISPLAY` on and no PHP notice emitted.

## Definition of done

- [ ] `npm install && composer install && npm run env:start && npm test` takes a
      fresh clone to a full green run, and `docs/DEVELOPMENT.md` says exactly that.
- [ ] Unit tests run without a database in under 10 seconds.
- [ ] Every listed integration case exists and passes, or is committed as a
      skipped test naming the scaffold bug it exposes.
- [ ] The E2E flow passes headless in CI.
- [ ] PHPCS and PHPStan pass over both plugins.
- [ ] CI is green on the working branch.
- [ ] Any scaffold bug found is reported in `docs/03-current-state.md` and, when
      the fix is small and obviously correct, fixed in a separate commit from
      the harness itself.

## Out of scope

- Building missing features. If a test cannot be written because the feature
  does not exist, write the test skipped with a reason and move on.
- Reworking the LMS architecture.
- Performance benchmarking. Correctness first.
