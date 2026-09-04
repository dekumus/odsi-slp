# Brief 04 — the integration contract and `odsi-bridge`

## Objective

Define and build the seam between learning and community. The bridge is what
makes this a social learning platform rather than two plugins on one site, and
it is also the thing most likely to quietly reintroduce the coupling ADR-005
forbids. Specify the contract first, then implement it.

## Inputs

1. `CLAUDE.md`
2. `docs/01-decisions.md` — ADR-005 above all
3. `docs/specs/10-lms-functional-spec.md`, `docs/specs/11-social-functional-spec.md`
4. `docs/specs/20-social-architecture.md` — the social hook surface
5. `docs/03-current-state.md` — the LMS's published hooks are listed there
6. `docs/04-parity-scope.md` — the bridge's v1 rows

## Constraints

- `odsi-bridge` may depend on both plugins. Neither may depend on it, or on each
  other.
- The bridge must degrade to nothing: if either plugin is deactivated, the
  bridge deactivates itself with an admin notice and leaves no broken behaviour
  behind. Its own data must survive that and resume correctly on reactivation.
- If the bridge needs an event that neither plugin fires, the fix is to add a
  documented hook to that plugin — never to reach into its internals.
- Every write the bridge performs goes through the owning plugin's public
  service or REST API, never directly into the other plugin's tables.

## Deliverables

### `docs/specs/30-integration-contract.md`

- The complete list of LMS events the social side reacts to, and the social
  events the LMS side reacts to, each named with its full signature.
- For each: what the bridge does, what activity or notification it produces, who
  can see it, and what happens when the same event fires twice.
- Any hook that must be **added** to `odsi-lms` or `odsi-social`, with its
  proposed signature and the reason the existing surface is insufficient.
- The linkage model between a course and a group: is it one-to-one, does the
  group own the course or the reverse, what happens when either is deleted, and
  what happens when a member leaves the group but stays enrolled. State it as a
  table of scenarios and outcomes.
- Idempotency and ordering rules. Activity items must not be duplicated by a
  retried request or a re-fired hook.

### `plugins/odsi-bridge/`

Built to the standard of the other two:

- `odsi-bridge.php` with a dependency check that verifies both plugins are
  active and at a compatible version before booting.
- `src/Plugin.php` and a small container, matching the established kernel.
- One module per integration concern — course activity, group linkage, member
  progress visibility — each independently disableable via a filter, because
  site owners will want two of the three.
- Any own storage (for example the course ↔ group link) in its own
  `odsi_bridge_*` table with its own migrator.
- Settings screen for enabling and disabling each integration.

### Tests

Integration tests proving:

- Completing a course produces exactly one activity item, with the right privacy.
- Deactivating either plugin leaves the site functional and the bridge dormant.
- Reactivating restores behaviour without duplicating past activity.
- Group membership changes do not silently change enrollment, and vice versa,
  unless the contract says they do.

## Definition of done

- [ ] The contract document lists every crossing event with its full signature.
- [ ] Every hook the bridge needs exists in the owning plugin and is documented
      there; none were added by editing the other plugin's internals.
- [ ] Grepping `plugins/odsi-lms/` and `plugins/odsi-social/` for the other's
      namespace still returns nothing.
- [ ] All three plugins activate in any order without error.
- [ ] The bridge's tests pass, including the deactivation and reactivation cases.

## Out of scope

- Leaderboards, points, badges and gamification — all Later.
- Course-scoped forums — v2.
- Migration tooling from LearnDash, BuddyPress or BuddyBoss.
