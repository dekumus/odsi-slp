# Brief 05 — close the gaps in the LMS scaffold

## Objective

Take `odsi-lms` from a coherent scaffold to something a site owner could
actually run, by working through the fourteen known gaps against the now-written
functional spec and the now-existing test harness. Every change lands with a
test.

## Inputs

1. `CLAUDE.md`
2. `docs/03-current-state.md` — the numbered gap list is the work queue
3. `docs/specs/10-lms-functional-spec.md` — the target behaviour
4. `docs/DEVELOPMENT.md` — how to run what you write
5. `plugins/odsi-lms/src/`

## Constraints

- Extend the existing kernel. A second pattern for the same job is worse than an
  imperfect first one.
- Every behavioural change is accompanied by a test that fails before it and
  passes after.
- Where the spec and the scaffold disagree, the spec wins — but say so in the
  commit message rather than silently changing behaviour.
- Do not start the React builder before the classic meta boxes are proven by
  tests. The fallback path is what most sites will actually run when a bundle
  fails to load.

## Priority order

Ordered by risk retired per unit of work, not by visibility.

1. **Gap 10 — `Migrator::maybe_migrate()` on every request.** A version compare
   on a front-end page load is a smell today and a bug the moment the option
   cache misses. Move it behind `admin_init` plus an explicit upgrade routine.
2. **Gap 12 — the cron handler.** `odsi_lms_daily_maintenance` is scheduled and
   nothing listens. Hook `EnrollmentRepository::expire_due()` to it. An access
   window that never actually expires is a correctness bug, not a missing feature.
3. **Gap 14 — outline cache invalidation.** `Structure` caches per request but
   only the builder flushes it. Invalidate on `save_post`, `deleted_post` and
   `updated_post_meta` for the relationship keys.
4. **Gap 2 — reports.** Instructors cannot currently see anything. Build the
   enrollment list and the progress/completion report on top of the existing
   repositories, with a `WP_List_Table` and real pagination.
5. **Gap 7 — the quiz player.** The single most visible hole: a quiz page is
   currently an empty div. Render questions, submit answers, show results.
6. **Gap 8 — manual grading.** Essay answers are gradable in the model and
   ungradable in practice, so `needs_grading` is a trap. Build the queue.
7. **Gap 5 — the React course builder**, with `@wordpress/scripts` build
   tooling, writing to the same meta keys as the meta boxes.
8. **Gap 3 — certificates**, then **gap 4 — submissions**.
9. **Gap 9 — object caching** on the outline and progress reads.
10. **Gap 13 — the `.pot` file** and an i18n build script.

Gaps 6 (blocks) and 11 (commerce) are deferred; leave them documented.

## Deliverables

- Working implementations of items 1–8, each with tests.
- `docs/03-current-state.md` updated as each gap closes — the file must never
  overstate what works.
- Any behaviour the spec did not anticipate raised as a spec amendment, not
  decided silently in code.

## Definition of done

- [ ] Items 1–3 are complete; these are correctness bugs, not features, and none
      of the rest should land before them.
- [ ] Every closed gap has a test that fails on the previous commit.
- [ ] `docs/03-current-state.md` accurately describes the plugin at HEAD.
- [ ] CI green, PHPCS and PHPStan clean.
- [ ] A learner can complete the full flow in `docs/00-project-brief.md` success
      criteria 1–3 in a real browser against `wp-env`.

## Out of scope

- The social plugin and the bridge.
- Commerce and payment fulfilment.
- Performance optimisation beyond the caching in item 9.
