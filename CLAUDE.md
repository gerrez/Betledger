# BetLedger

A betting ledger web app. Users manually record the bets they place, settle them when
the event is decided, and see statistics (profit/loss, ROI, strike rate, etc.) based on
the results.

It starts with a single real user, but it is **multi-user from day one**: every piece of
user data belongs to a user, and no user may ever see or change another user's data.

- Product spec: `docs/spec.md`
- Work backlog: `docs/roadmap.md`
- Owner's one-time setup notes: `docs/SETUP.md`

## Stack

- Laravel 12, PHP 8.4+ (Composer platform is pinned to 8.4 — keep it that way so CI and
  cloud sessions can install the lock file)
- Livewire 4 + Volt (single-file components in `resources/views/livewire/`), Flux UI
  (free edition only — no Flux Pro components), Tailwind CSS 4, Vite
- SQLite for local dev and tests (tests use an in-memory database, see `phpunit.xml`)
- PHPUnit (class-based tests, not Pest), Laravel Pint, Larastan (PHPStan level 6)
- Auth comes from the Livewire starter kit (login, register, password reset, email
  verification, profile settings). Build on it; don't replace it.

Non-obvious: Livewire 4 defaults to a `layouts::app` page layout, but the starter kit's
layout lives at `resources/views/components/layouts/app.blade.php`. `config/livewire.php`
sets `component_layout` to `components.layouts.app` to bridge that. Don't revert it.

## Commands

```bash
composer install && npm ci          # dependencies
php artisan migrate                  # apply migrations to database/database.sqlite
php artisan test                     # full test suite
vendor/bin/pint                      # format (CI runs `pint --test`)
vendor/bin/phpstan analyse --memory-limit=1G
npm run build                        # build assets (feature tests need public/build)
composer run dev                     # local dev server + queue + logs + vite
```

## Before you call a task done

All of these must pass. CI runs the same checks and a PR can't merge without them.

1. `vendor/bin/pint --test`
2. `vendor/bin/phpstan analyse --memory-limit=1G` — fix the cause; never add
   `@phpstan-ignore`, baseline entries or casts just to silence it
3. `php artisan test`
4. New behaviour has tests: a feature test per user-facing flow, unit tests for
   calculations (settlement, P/L, statistics)

## Domain rules (non-negotiable)

- **Money is never a float.** Store amounts as integers in minor units (cents/øre) with a
  currency code; do arithmetic on integers. Odds are stored as decimal strings/`decimal`
  columns, never PHP floats. Round only at the final step and test the rounding.
- **Settlement and statistics logic lives in plain PHP classes** (e.g. `app/Domain/...`
  or `app/Actions/...`), not in Livewire components or Blade, so it can be unit-tested.
- **Every query on user data is scoped to the authenticated user.** Use policies for
  authorization and a relationship (`$user->bets()`) rather than global lookups. Every
  feature that exposes user data gets a test proving user B cannot read or modify user A's
  records.
- Settling a bet must be reversible (re-settle/un-settle) — users make mistakes.

## Working style (especially cloud sessions)

- One roadmap task per session. Pick the next unchecked item in `docs/roadmap.md` unless
  told otherwise. If a task is too big for one reviewable PR, split it and say so.
- Work on a feature branch, never on `main`. Finish with a PR that describes what changed,
  how it was tested, and anything you were unsure about.
- Tick off the task in `docs/roadmap.md` in the same PR.
- If the spec is ambiguous, choose the simplest reasonable option, write the assumption
  in the PR description, and add it to the "Open questions" section of `docs/spec.md`.
  Don't silently invent product behaviour.
- Migrations: add new migrations; never edit a migration that has been merged to `main`.
- Keep dependencies lean. Adding a Composer/npm package needs a one-line justification in
  the PR description.
- Don't modify CI workflows, `.claude/` settings or `phpstan.neon` (e.g. lowering the
  level) unless the task is explicitly about that.
- Files use LF line endings (enforced by `.gitattributes`); the owner develops on Windows,
  cloud sessions run on Linux.
