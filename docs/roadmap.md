# Roadmap

Each unchecked item is meant to be **one cloud session → one PR**. Work top to bottom
unless the owner says otherwise: later tasks build on earlier ones. Tick the box in the
same PR that completes the task.

Every task: follow `CLAUDE.md` (checks, isolation tests, money rules), the relevant
sections of `docs/spec.md`, and the named mockup in `docs/design/project/`.

## Phase 0 — Harness

- [x] Laravel + Livewire starter kit (auth, profile settings)
- [x] Larastan, Pint, CI workflow, PR template
- [x] `CLAUDE.md`, `.claude/settings.json`, cloud session-start hook
- [x] **Harness smoke test (first cloud session).** Add a `check` script to
      `composer.json` that runs `pint --test`, `phpstan analyse --memory-limit=1G` and
      `php artisan test` in sequence, and mention `composer run check` in `CLAUDE.md`'s
      Commands section. In the PR description, report whether the session-start hook ran
      cleanly (and how long it took). Proves the cloud setup, permissions and CI work end
      to end.

## Phase 1 — Design (with the owner, not a cloud task) ✅

- [x] Resolve the open scope decisions (see `docs/spec.md`)
- [x] Data model (tables, columns, money/odds types)
- [x] Screen designs: bet entry, bet list, settlement, statistics dashboard
- [x] Break the build into Phase 2 tasks below

## Phase 2 — Build v1

### Foundation

- [x] **2.1 App shell and theme.** Load Geist; add the spec's colour tokens (light and
      dark) to the Tailwind 4 theme; app layout with the bottom tab bar on phones and the
      sidebar on desktop (Home, Bets, New bet, Stats, Settings), replacing the starter
      kit's nav. Placeholder pages for routes that don't exist yet. Feature tests: every
      app route requires auth. Mockups: `Main`, `Statistics` (sidebar).
- [x] **2.2 Settlement calculator (domain only, no DB).** In `app/Domain/`: odds and
      money handling with bcmath (add `ext-bcmath` to `composer.json`), selection factors,
      bet factor, cash vs free-bet payout/profit, round half up once, and the "when is a
      bet settled" rule (any `lost`, or all decided). Unit tests for every worked example
      in the spec plus edge cases (free bet void, half results in accumulators).
- [x] **2.3 Base currency and bookmakers.** `users.base_currency` + `bookmakers` table,
      model, policy, factory. Settings: choose base currency. Bookmakers page: list,
      create, edit (name, currency, exchange rate; rate fixed at 1 when currency = base),
      deactivate. Isolation tests.
- [ ] **2.4 Lists.** `sports`, `competitions`, `teams`, `markets`, `tipsters`, `tags`
      tables, models, policies, factories. A "Lists" page to rename entries and delete
      unused ones. Isolation tests (including: a competition/team can't belong to another
      user's sport).
- [ ] **2.5 Bets schema.** `bets`, `selections`, `bet_tag`, `selection_team` migrations,
      models, relationships, casts (integer money, string odds), policies, factories with
      states (single, accumulator, free, settled). Enforce "base currency can't change
      once the user has bets". No UI.

### Recording bets

- [ ] **2.6 New bet form: singles.** Per `NewBet` mockup: essentials first, "More details"
      folded; inline creation of list entries by typing a new name; currency and rate
      copied from the bookmaker; live payout/profit preview via the 2.2 calculator;
      validation (odds > 1, stake > 0). Tests include: can't attach another user's
      bookmaker, team, tag, etc.
- [ ] **2.7 Accumulators and editing.** Single/Accumulator switch, add/remove selections,
      total odds; the same form edits an existing bet (open bets fully; settled bets
      re-settle via 2.9 rules).
- [ ] **2.8 Bets list.** Per `Bets` mockup: Open / Settled / All tabs, bookmaker and sport
      filters, sorting, pagination, single and accumulator cards. No settling yet.

### Settling

- [ ] **2.9 Settle service.** Domain action that applies selection results to a bet:
      status, payout, profit, settled_at; un-settle and re-settle; manual payout override
      (`payout_is_manual`), discarded when any result changes. Unit and feature tests.
- [ ] **2.10 Inline settling.** Result buttons on pending selections in the bets list,
      result banner with Undo (per `Bets` mockup).
- [ ] **2.11 Bet detail page.** Per `BetDetail` mockup: result, profit in bet and base
      currency, CLV, result buttons, clear result, manual payout override with warning,
      facts, delete with confirmation.

### Statistics

- [ ] **2.12 Statistics service (domain).** Filters; totals (turnover, profit, ROI,
      strike rate, average odds, CLV, open stake); conversion to base currency with each
      bet's own rate; breakdowns (accumulators under every value); odds bands; cumulative
      profit series by day/week/month. Tested against a fixed fixture set with
      hand-checked expected numbers.
- [ ] **2.13 Statistics page.** Per `Statistics` mockup: date ranges, filter chips, free
      bets toggle, KPI tiles, breakdown table with dimension tabs (and the
      accumulator note), odds-range bars, CLV panel. No chart yet.
- [ ] **2.14 Profit-over-time chart.** Add the cumulative profit chart to the statistics
      page (pick a small chart library and justify it in the PR, or plain SVG).
- [ ] **2.15 Home overview.** Per `Main` mockup: month selector, month profit card with
      sparkline, KPI tiles, open bets preview linking to the bets list.

### Polish

- [ ] **2.16 Dark mode and phone pass.** Dark theme across all pages; check 44 px touch
      targets, `inputmode="decimal"` on odds/stake, focus states and labels; fix anything
      the earlier tasks missed.

## Later (after v1)

Not scheduled yet; see "Later" in `docs/spec.md`: bankroll (deposits/withdrawals),
cash out, each-way, system bets, CSV import/export, automatic exchange rates, merging
list entries.
