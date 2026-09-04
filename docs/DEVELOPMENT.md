# Development

From a fresh clone to a green test run, and how to get unstuck when it is not.

## Requirements

| Tool | Version | Used for |
| --- | --- | --- |
| PHP | 8.1 – 8.4 with `mysqli` | Everything |
| Composer | 2.x | PHP toolchain |
| Node | 20+ | wp-env, Playwright, asset builds |
| MySQL or MariaDB | any recent | Integration suite (a local server or the Docker one wp-env starts) |
| Docker | optional | `wp-env` for a browsable site and the E2E suite |

Docker is optional. Everything except the E2E suite runs against a plain
local database, and even the E2E suite can run against `bin/serve-local.sh`
when Docker is unavailable.

## Fresh clone to green

```bash
composer install
npm install

# Integration suite needs WordPress core and its test library. This fetches
# both from GitHub (not wordpress.org) into /tmp and writes a test config
# pointing at a local database. Set WP_TESTS_DB_* to override credentials.
bin/install-wp-tests.sh

# Create the database and user the config expects (once):
mysql -uroot -e "CREATE DATABASE IF NOT EXISTS wordpress_test;
  CREATE USER IF NOT EXISTS 'wp'@'localhost' IDENTIFIED BY 'wp';
  GRANT ALL ON *.* TO 'wp'@'localhost'; FLUSH PRIVILEGES;"

composer lint            # PHPCS, WordPress Coding Standards
composer analyse         # PHPStan level 6
composer test:unit       # seconds, no database
composer test:integration
npm run check:boundaries # ADR-005: the two plugins never reference each other
```

`npm test` runs the boundary check, both PHP suites and the E2E suite in
sequence.

## The suites

| Suite | Command | Needs | Runs in |
| --- | --- | --- | --- |
| Unit | `composer test:unit` | nothing | ~1 s |
| Integration | `composer test:integration` | database + WP test library | ~10 s |
| E2E | `npm run test:e2e` | a running WordPress (see below) | ~1 min |

### Unit

`tests/unit/`. Plain PHPUnit with Brain Monkey stubbing WordPress functions.
Put logic that can be tested without a database here: graders, the container,
capability maps, pure services. Extend `ODSI\Tests\Unit\TestCase`, which sets
Brain Monkey up and stubs escaping and translation.

### Integration

`tests/integration/{lms,social,bridge}/`. The WordPress core PHPUnit framework
against a real database. Each test runs in a transaction that is rolled back,
including rows in the plugins' custom tables.

Extend `ODSI\Tests\Integration\TestCase`. It gives you:

- `$this->lms` — an `LmsFactory` that builds courses, lessons, topics, quizzes,
  questions and enrolled learners. `standard_course()` returns a fixed shape
  (leaf lesson, section lesson with two topics and a quiz, leaf lesson) that
  most tests want.
- `$this->as_user( $id, fn() => ... )` — run a callback as a user.
- `$this->rest( 'POST', '/odsi-lms/v1/...', $params )` — dispatch a REST request
  in-process and get the `WP_REST_Response`.

Name tests after the spec criterion they prove: `test_enr_003_...` proves
`LMS-ENR-003`. A failure then names the requirement, not just the method.

The bootstrap loads all three plugins as must-use plugins and runs each
`Installer::activate()`. `WP_TESTS_DIR` points at the test library
(default `/tmp/wordpress-tests-lib`).

### End to end

`tests/e2e/`. Playwright driving a real browser against a running site.

With Docker:

```bash
npm run env:start                    # http://localhost:8888 (dev), :8889 (tests)
npx wp-env run tests-cli wp rewrite structure '/%postname%/' --hard
npx wp-env run tests-cli wp config set ODSI_E2E true --raw
npm run test:e2e
```

Without Docker, after `bin/install-wp-tests.sh`:

```bash
npm run serve:local                  # installs a site into /tmp/odsi-site, serves on :8080
npm run test:e2e:local               # in another terminal
```

`ODSI_E2E` enables a small set of test-only REST routes the suite uses to
seed data that is deliberately not writable over the public API (quiz answer
keys). It must never be defined on a real site.

## Static analysis

- `composer lint` runs PHPCS with `phpcs.xml.dist`: WordPress Coding
  Standards plus PHPCompatibilityWP for PHP 8.1+. `composer lint:fix` applies
  the automatic fixes.
- `composer analyse` runs PHPStan at level 6 with the WordPress extension.
  The target level is recorded in `phpstan.neon.dist`. There is no baseline
  file; keep it that way.

## Resetting a broken environment

| Symptom | Fix |
| --- | --- |
| `WordPress test library not found` | `bin/install-wp-tests.sh` — and check `WP_TESTS_DIR` if you moved it |
| `Error establishing a database connection` in the integration suite | The database or credentials in `/tmp/wordpress-tests-lib/wp-tests-config.php` are wrong; rerun the install script with `WP_TESTS_DB_*` set |
| Integration tests fail with table errors after a schema change | Drop the `wptests_odsi_*` tables; the bootstrap recreates them |
| wp-env is wedged | `npm run env:reset` |
| `Could not authenticate against github.com` from Composer | Your network blocks `api.github.com`. `composer config --global use-github-api false` and `composer install --prefer-source` |
| Playwright cannot find Chromium | `npx playwright install --with-deps chromium` |

## Layout

```
bin/                      install-wp-tests.sh, serve-local.sh, check-plugin-boundaries.sh
tests/unit/               PHPUnit + Brain Monkey
tests/integration/        WordPress core test framework
tests/fixtures/           Factories shared by integration tests
tests/e2e/                Playwright
phpcs.xml.dist            Coding standards
phpstan.neon.dist         Static analysis
phpunit.unit.xml.dist     Unit suite config
phpunit.integration.xml.dist
.wp-env.json              Dockerised WordPress for development and E2E
.github/workflows/ci.yml  What CI runs, job by job
```

## CI

`.github/workflows/ci.yml` runs, as separate jobs: the boundary check, PHPCS,
PHPStan, the unit suite on PHP 8.1–8.4, the integration suite on three
PHP/WordPress pairs against MariaDB, a clean-activation check per plugin with
`WP_DEBUG` on, and the E2E flow. A red job names what is wrong; there is no
single "tests" job to dig through.
