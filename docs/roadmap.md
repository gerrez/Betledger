# Roadmap

Each unchecked item is meant to be **one cloud session → one PR**. Work top to bottom
unless the owner says otherwise. Tick the box in the same PR that completes the task.

## Phase 0 — Harness ✅

- [x] Laravel + Livewire starter kit (auth, profile settings)
- [x] Larastan, Pint, CI workflow, PR template
- [x] `CLAUDE.md`, `.claude/settings.json`, cloud session-start hook
- [ ] **Harness smoke test (first cloud session).** Add a `check` script to
      `composer.json` that runs `pint --test`, `phpstan analyse --memory-limit=1G` and
      `php artisan test` in sequence, and mention `composer run check` in `CLAUDE.md`'s
      Commands section. In the PR description, report whether the session-start hook ran
      cleanly (and how long it took). Proves the cloud setup, permissions and CI work end
      to end.

## Phase 1 — Design (with the owner, not a cloud task)

- [ ] Resolve the "To decide" list in `docs/spec.md`
- [ ] Data model (tables, columns, money/odds types)
- [ ] Screen designs: bet entry, bet list, settlement, statistics dashboard
- [ ] Break the build into Phase 2 tasks below

## Phase 2 — Build

_Filled in after the design phase._
