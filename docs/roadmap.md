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
- [x] **2.4 Lists.** `sports`, `competitions`, `teams`, `markets`, `tipsters`, `tags`
      tables, models, policies, factories. A "Lists" page to rename entries and delete
      unused ones. Isolation tests (including: a competition/team can't belong to another
      user's sport).
- [x] **2.5 Bets schema.** `bets`, `selections`, `bet_tag`, `selection_team` migrations,
      models, relationships, casts (integer money, string odds), policies, factories with
      states (single, accumulator, free, settled). Enforce "base currency can't change
      once the user has bets". No UI.

### Recording bets

- [x] **2.6 New bet form: singles.** Per `NewBet` mockup: essentials first, "More details"
      folded; inline creation of list entries by typing a new name; currency and rate
      copied from the bookmaker; live payout/profit preview via the 2.2 calculator;
      validation (odds > 1, stake > 0). Tests include: can't attach another user's
      bookmaker, team, tag, etc.
- [x] **2.7 Accumulators and editing.** Single/Accumulator switch, add/remove selections,
      total odds; the same form edits an existing bet (open bets fully; settled bets
      re-settle via 2.9 rules).
- [x] **2.8 Bets list.** Per `Bets` mockup: Open / Settled / All tabs, bookmaker and sport
      filters, sorting, pagination, single and accumulator cards. No settling yet.

### Settling

- [x] **2.9 Settle service.** Domain action that applies selection results to a bet:
      status, payout, profit, settled_at; un-settle and re-settle; manual payout override
      (`payout_is_manual`), discarded when any result changes. Unit and feature tests.
- [x] **2.10 Inline settling.** Result buttons on pending selections in the bets list,
      result banner with Undo (per `Bets` mockup).
- [x] **2.11 Bet detail page.** Per `BetDetail` mockup: result, profit in bet and base
      currency, CLV, result buttons, clear result, manual payout override with warning,
      facts, delete with confirmation.

### Statistics

- [x] **2.12 Statistics service (domain).** Filters; totals (turnover, profit, ROI,
      strike rate, average odds, CLV, open stake); conversion to base currency with each
      bet's own rate; breakdowns (accumulators under every value); odds bands; cumulative
      profit series by day/week/month. Tested against a fixed fixture set with
      hand-checked expected numbers.
- [x] **2.13 Statistics page.** Per `Statistics` mockup: date ranges, filter chips, free
      bets toggle, KPI tiles, breakdown table with dimension tabs (and the
      accumulator note), odds-range bars, CLV panel. No chart yet.
- [x] **2.14 Profit-over-time chart.** Add the cumulative profit chart to the statistics
      page (pick a small chart library and justify it in the PR, or plain SVG).
- [x] **2.15 Home overview.** Per `Main` mockup: month selector, month profit card with
      sparkline, KPI tiles, open bets preview linking to the bets list.

### Polish

- [x] **2.16 Dark mode and phone pass.** Dark theme across all pages; check 44 px touch
      targets, `inputmode="decimal"` on odds/stake, focus states and labels; fix anything
      the earlier tasks missed.

## Phase 3 — v1.1 fixes and polish

From the owner's review of v1.

- [x] **3.1 Postgres in CI.** Production runs Postgres, but CI only tests SQLite (2.8
      shipped a sort that broke on Postgres and was only caught by hand). Add a Postgres
      job to the CI workflow next to the SQLite one (this task is explicitly allowed to
      change CI). Make the database-constraint tests in `BetIsolationTest` and
      `BetSchemaTest` assert that the row is rejected (e.g. `QueryException`) instead of
      matching SQLite's "FOREIGN KEY constraint failed" text. Update `CLAUDE.md`'s Stack
      section to say tests run on both.
- [x] **3.2 Confirm dialogs and dropdowns.** Replace every `wire:confirm` (browser
      `confirm()` popup) with one shared confirmation modal in the app's style: title,
      message, cancel, and a confirm button (danger style for deletes). Replace the native
      `<select>`s (`filter-select` component, bet form, Bookmakers, base currency) with
      one custom dropdown component built with Alpine and the theme tokens: keyboard
      navigation, focus states, 44 px targets on phones, dark mode. Flux free edition
      only (no Flux Pro, no new package without justification). Feature tests still pass;
      add tests where confirm behaviour changes.
- [x] **3.3 Bet form: 24-hour time and "More details".** The "Placed" and "Starts"
      fields are `datetime-local`, which shows AM/PM in English (US) browsers and can't
      be forced to 24-hour. Replace them with a date input plus a 24-hour time input
      (`HH:MM`, 00–23), stored as before. "More details" currently opens extra fields
      inside each selection further up the form as well as a section below the button;
      make the fields open where the user clicked (e.g. a per-selection toggle for
      selection details and the bet-level details directly below its own toggle).
      Feature tests for the time parsing and validation.
- [x] **3.4 Settle from the edit form.** On the edit page, each selection gets the same
      result buttons as the bet detail page (won, lost, void, half-won, half-lost, and
      clear), replacing the read-only result label. Saving applies results through the
      2.9 settle service, so un-settle/re-settle and the manual-payout rules behave
      exactly as on the detail page. Tests: results saved from the form, clearing a
      result un-settles, isolation.
- [x] **3.5 Page widths and welcome page.** Home (`overview`) and the Bets list are
      capped at `max-w-2xl`, while Statistics uses the full width. On desktop, make Home
      use the same width as Statistics with a layout that fills it (e.g. KPI tiles in
      one row, open bets beside the profit card); keep the phone layout. Decide whether
      the Bets list should widen too and note it in the PR. Redirect the public `/` page
      (still Laravel's welcome page) to the dashboard, or to login for guests.

## Phase 4 — Features

From "Later" in `docs/spec.md`, in priority order. Each needs a short design pass
(spec section, data model) in its first PR; split into schema/domain and UI PRs when it
is too big for one review.

- [x] **4.1a Sportmarket import: parser and mapping (domain).** Per "Import from
      Sportmarket" in `docs/spec.md`: read the orders CSV by header names, group rows by
      `Order Id`, drop failed fills, and map each order to bet data (stake, averaged
      odds, list names, pick, tags, notes) plus its result and reported payout, or to a
      skip/error with its reason. Add the `bets.external_ref` column. No UI. Unit tests
      against `tests/Fixtures/sportmarket/orders.csv`, one per case listed in the spec
      (won, lost, half results, push, status void, failed and all-failed orders, several
      fills with different results, cents-off P&L, `fb_ht`/`fb_et`, each market code).
- [x] **4.1b Sportmarket import: page.** An Import page linked from Settings: choose the
      bookmaker, upload the CSV, preview every order (bet to create, skipped with the
      reason, or error), then confirm; nothing is saved before confirming. Saving goes
      through the existing record and settle services in one transaction. Feature tests:
      upload and confirm the fixture, re-importing it creates nothing, a file with
      another currency is rejected, and isolation (list entries and `external_ref`
      duplicates are per user).
- [x] **4.2 CSV export.** Download all of the user's bets (one row per selection, with
      the bet's fields repeated) from Settings. Money in major units with the currency
      code, odds as entered. Isolation test. Link the download from the delete-account
      dialog too ("Download your bets first"): it is the only backup a user has.
- [x] **4.3 Bankroll.** Deposits and withdrawals per bookmaker, and balances (deposits −
      withdrawals + settled profit − open cash stakes). Shown per bookmaker and in total.
      Per "Bankroll" in `docs/spec.md`: `bankroll_transactions`, `App\Domain\Bankroll`
      (reusing the statistics totals), the Bankroll page, and the bookmaker currency lock.
- [ ] **4.4 Cash out.** Full and partial cash out of an open bet, recorded as its own
      settlement outcome so statistics stay correct. Reversible like any settlement.
- [ ] **4.5 Merging list entries.** Merge duplicates (e.g. two spellings of a team) on
      the Lists page; bets move to the kept entry.
- [ ] **4.6 Import in BetLedger's own format.** Import the 4.2 export format, reusing the
      4.1 upload/preview/confirm flow.
- [ ] **4.7 Automatic exchange rates.** Fetch rates for bookmakers in other currencies;
      a bet still copies the rate when saved.
- [ ] **4.8 Each-way and system bets.**
- [x] **4.9 Probability and bet value (first version).** Asked for by the owner. Two
      optional bet fields: `probability` (a percentage) and `bet_value`, calculated as
      total odds × probability (`App\Domain\BetValue`) or entered directly when there is
      no probability. In the bet form's "More details", on the bet detail page, and on
      `POST /api/v1/bets` (contract 1.1.0). Void and half results are not taken into
      account yet; see "Probability and bet value" under "Open questions" in
      `docs/spec.md`. Unit tests for the calculation and validation; feature tests for
      the form (create, edit, recalculation), the detail page and the API.

## Phase 6 — From the November 2026 review

From a review of the app after 4.1b (code, views and tests were read; the app was not
run). Items are in priority order within the phase. They are independent of Phase 5.
Suggested order together with the open Phase 4 items, for the owner to confirm:
**6.1, 6.2, 4.2, 6.3, 6.4, 6.5, 6.6, 6.7, 4.5, 6.8, 6.9, 4.3**, then the rest. Related
owner decisions are under "Open questions" in `docs/spec.md` ("From the November 2026
review"); where a task says "default", build that unless the owner has answered.

Every task keeps the rules in `CLAUDE.md`: integer money, decimal-string odds,
calculations in `app/Domain/`, every query through the user's relations, an isolation
test for anything that reads or changes user data.

### Correct dates

- [x] **6.1 Statistics by event date.** Profit over time, the Home month card and the
      statistics date range put a settled bet in the period of its `settled_at`, which
      `SettleBet` stamps with `now()` (`app/Domain/Bets/SettleBet.php`). So a Saturday
      bet settled on Monday, or last month's bets entered today, land in the wrong
      period. Performance belongs to when the bet was decided, not to when its result
      was typed in. `settled_at` keeps its meaning (when the result was recorded) and
      keeps driving the bankroll (4.3), where a balance follows the money.
      - Schema: a new migration adds `bets.event_at` (datetime, indexed with
        `user_id`), fills it for existing bets by the rule below with the query
        builder (must hold on SQLite and Postgres), then makes it not null.
      - Rule, as one class in `app/Domain/Bets/`: the latest `event_starts_at` among
        the bet's selections (an accumulator is decided by its last leg), else
        `placed_at`; never before `placed_at`. Sportmarket imports have only a game
        date, so they use the same instant as their `settled_at` (the game date at
        23:59 UTC). Recompute whenever a bet is recorded, edited or imported (bet
        form, `POST /api/v1/bets`, Sportmarket import); settling doesn't change it.
      - Statistics: `StatisticsQuery` filters settled bets by `event_at` (open bets
        still by `placed_at`); profit over time, the Home month card and its month
        selector (`Overview\Index`) group by `event_at`. `BankrollQuery` and the
        "Recently settled" sort keep `settled_at`.
      - Page: one line under the statistics range picker: "Settled bets count on the
        date of their event."
      - The API's bet response is unchanged (`event_at` is internal); the CSV export
        (4.2) is unchanged.
      - Update `docs/spec.md`: the Statistics filters line ("by settlement date"), the
        profit-over-time note (2.12), the Home overview note (2.15) and the bet fields
        table; answer the open question below.
      - Tests: unit tests for the rule (single with and without a start, an
        accumulator, a start before `placed_at`, an import); feature tests that a bet
        settled after its event counts in the event's month on the statistics page and
        on Home, that changing a start time in the edit form moves it, that the
        bankroll still uses `settled_at`; isolation.
- [x] **6.2 Per-user time zone.** Everything is entered, shown and grouped in UTC
      (`config/app.php`), so a user in Denmark sees "now" one or two hours behind and a
      bet settled at 23:30 UTC counts on the wrong day. Answers the 2.6 open question.
      - Schema: `users.timezone` (IANA name, default `UTC`), validated with Laravel's
        `timezone` rule. Storage stays UTC; convert only at the edges.
      - Setting: a "Time zone" field in Settings (next to the base currency), using the
        3.2 dropdown. Registration pre-fills it from the browser
        (`Intl.DateTimeFormat().resolvedOptions().timeZone`), falling back to UTC.
      - One place for the conversion: a small class under `app/Domain/` that turns a
        UTC instant into the user's local date/time and a local date + time into UTC.
        Use it in the bet form (Placed, Starts, Settled; parsing and pre-filling), in
        every formatted date (bet cards, bet detail, import preview), in
        `StatisticsFilter` (a "from/to" day means that day in the user's zone), in the
        profit-over-time buckets and in the Home month selector. No `->format()` on a
        UTC date in a component or view after this task.
      - Sportmarket import: `settled_at` (and with it the 6.1 `event_at`) becomes the
        end of the game date in the user's zone instead of 23:59 UTC; `placed_at` in the file is already UTC.
      - Existing bets are not shifted (see the open question); say so in the PR.
      - Tests with `Europe/Copenhagen`: a bet on each side of local midnight, a day on
        each side of a daylight-saving change, date-range boundaries, and that two
        users with different zones each see their own days.

### Finding and entering bets

- [x] **6.3 Bets list: search and more filters.** `App\Livewire\Bets\Index` filters by
      bookmaker and sport only, 20 bets per page; after an import of ~600 bets a single
      bet can't be found.
      - Add a text search (event name, pick, team, competition and notes) and filters
        for tipster, tag, competition, market, bet type and a placed/settled date
        range, all as `#[Url]` properties so a filtered list can be linked to (6.7
        needs that).
      - Reuse the statistics conditions instead of writing them twice: move the `where`
        clauses of `StatisticsQuery::filtered()` into one query class under
        `app/Domain/Bets/` that both the statistics query and the bets list call with a
        `StatisticsFilter`-style value object; the list adds the text search and its
        Open/Settled/All tab.
      - Text search must behave the same on SQLite and Postgres: compare
        `LOWER(column) LIKE ?` with a lower-cased term and escaped `%`/`_`; no `ILIKE`.
      - UI: a search field above the tabs; the extra filters behind one "Filters"
        button (a sheet on phones) so the phone header stays two rows; active filters
        shown as removable chips like on the statistics page; "n bets" count and an
        empty state for "no bets match".
      - Tests: each filter, the search (case, wildcard characters), combined filters,
        pagination reset on change, and that neither search nor a filter id from
        another user reveals that user's bets.
- [x] **6.4 Duplicate a bet and "Save and add another".** `Bets\Create` pre-fills only
      the bookmaker and sport of the latest bet. The same event at two bookmakers, or
      an evening of similar bets, means retyping everything.
      - "Duplicate" on the bet detail page opens `bets/create?from={bet}`: every field
        copied except placed (now), results, payout, closing odds and `external_ref`.
        The source bet is loaded through `$user->bets()` and the policy, so another
        user's id is a 404.
      - A second submit button on the new-bet form, "Save and add another": saves,
        shows "Bet saved" and stays on the form with bookmaker, sport, competition,
        tipster, tags and placed date kept, and event, pick, odds and stake cleared.
      - Tests: the copied and the cleared fields, an accumulator copy, isolation.

### Statistics

- [x] **6.5 Risk figures (domain and page).** `Totals` has no measure of variance, so the
      page can't say whether a bad run is normal or how much bankroll the staking needs.
      - Domain, in `app/Domain/Statistics/`: over the settled bets in `event_at`
        order (6.1; ties by id), in base currency — **max drawdown** (largest fall of
        cumulative profit from an earlier peak, with the dates of the peak and the
        low), **current drawdown**, **longest winning and losing streak** and the
        current streak (pushes neither extend nor break a streak; use the existing
        `isWin`/`isLoss` of `BetFigures`), **biggest win and biggest loss** (with the
        bet, so the page can link to it), **average stake** (cash bets only, like
        turnover; integer division rounded half up once).
      - Page: a "Risk" panel on the statistics page under the KPI tiles, respecting
        the filters. Not on Home.
      - Define each figure in the Statistics section of `docs/spec.md`.
      - Unit tests against the 2.12 fixture set with hand-checked numbers, including:
        no settled bets, only wins, a drawdown that has recovered, free bets.
      - Depends on 6.1 (bets must be in event order for streaks to mean anything).
- [x] **6.6 Break-even strike rate.** A strike rate of 45 % says nothing without the
      odds, and "average odds" is an arithmetic mean of `total_odds`
      (`StatisticsCalculator`), which one accumulator at 50.0 among twenty bets at 2.0
      turns into 4.3.
      - Add **break-even strike rate** = mean of `1 ÷ total_odds` over the same bets
        the strike rate counts (wins and losses, no pushes), calculated with bcmath
        and rounded once for display. Show it under the strike-rate tile ("needs
        51.3 % to break even") and as a column in the breakdown table and odds bands.
      - Keep "average odds" as defined; add the median next to it only if it fits the
        tile.
      - Spec: add the definition. Unit tests with hand-checked values.
- [x] **6.7 From a statistics row to its bets.** Breakdown rows and odds bands are plain
      text; "Tennis −12 % ROI" can't be followed up.
      - Each breakdown row and odds band links to the bets list with the page's current
        filters plus that row's value (and the settled tab). The "no competition / no
        tipster / …" rows link with an explicit "none" filter value.
      - The count on the linked list must equal the row's count; assert that in a
        feature test per dimension. Odds bands need a total-odds range filter on the
        list (URL only, shown as a chip).
      - Depends on 6.3.
- [x] **6.8 Sortable breakdown; month and weekday.** Breakdown rows are always ordered by
      settled count, then label, so the best and worst rows can't be ranked, and there
      is no profit-per-month table.
      - Sorting: column headers on the breakdown table sort by bets, turnover, profit,
        ROI, strike rate or CLV, ascending/descending, kept in the URL. Sort in the
        calculator (it owns the rows), with label as the tie-breaker; rows without a
        value for the sorted column (no ROI because no turnover) go last.
      - Dimensions: add `Month` (settlement month, newest first by default) and
        `Weekday` (Monday–Sunday) to `Dimension`. They use the user's time zone once
        6.2 is in.
      - Unit tests for each sort and the two dimensions; a feature test for the URL
        state.

### Closing odds and import

- [ ] **6.9 Quick closing odds.** The Sportmarket export has no closing odds, so the CLV
      panel is empty for imported bets, and adding them is Edit → More details per
      selection.
      - On the bet detail page each selection gets "Add closing odds" (or the existing
        closing-odds row becomes editable): one decimal input, saved on its own, CLV
        shown at once. Same validation as the form (> 1, decimal comma accepted).
      - Saving goes through `RecordBet` or a small domain action next to it, not a
        direct model update in the component, and never touches results or payout.
      - On the bets list, a "No closing odds" filter (via 6.3 if it is in, else skip)
        so the owner can work through them.
      - Tests: add, change, clear; invalid values; isolation.
- [ ] **6.10 Import: warn about bets already entered by hand.** Duplicates are detected
      only by `external_ref`. Open orders are skipped by the import, so a user who logs
      them by hand gets the same bet again when the settled order is imported later.
      - In the mapping step, mark an order as a **possible duplicate** when the user
        has a bet without `external_ref` at the same bookmaker with the same stake,
        placed within a few minutes of the order, and the same event name
        (case-insensitive). Load the candidate bets once, not per order.
      - Preview: possible duplicates get their own group with a link to the existing
        bet and are **not imported by default**; a checkbox per order (or "import
        anyway") includes them. Never merge or change the existing bet.
      - Spec: extend "Import from Sportmarket". Tests: match, near misses (other
        stake, other bookmaker, outside the time window), opt-in import, isolation.
      - Only worth doing if the owner logs open Sportmarket bets by hand (open
        question).

### Safety

- [ ] **6.11 Recently deleted bets.** `Bets\Show::delete()` removes the bet and its
      selections for good; one wrong tap loses the result history.
      - Soft-delete bets (`deleted_at`, new migration; `SoftDeletes` on `Bet`).
        Selections stay attached to the trashed bet. Check that every query on bets
        goes through the model so trashed bets drop out of lists, statistics, Home and
        the "in use" checks on Lists and Bookmakers.
      - Settings → "Deleted bets": the user's trashed bets with Restore and "Delete for
        good"; a scheduled command prunes bets trashed more than 30 days ago
        (`Prunable`).
      - `external_ref`: a trashed imported bet still blocks a re-import, which is
        right (deleting an imported bet should not make the next import bring it
        back); say so in the spec. Deleting for good frees it.
      - The delete confirmation text changes from "can't be undone" to "You can
        restore it from Settings for 30 days".
      - Tests: trashed bets are absent from every page and total, restore, prune with
        frozen time, isolation on restore and force-delete, on both databases.
- [ ] **6.12 Email verification: decide and make it true.** `User` has
      `implements MustVerifyEmail` commented out, so the `verified` middleware on the
      app routes does nothing, while registration is open to anyone.
      - Default (if the owner hasn't answered): turn verification on. Implement
        `MustVerifyEmail` on `User`; existing users and the seeded demo accounts get
        `email_verified_at` set (migration for existing rows, seeder for demo
        accounts); confirm a mail driver is configured in production in `docs/SETUP.md`.
      - Alternative if the owner wants a private app: remove the registration routes
        and the link on the login page instead, and create users with an Artisan
        command.
      - Tests: an unverified user is redirected from every app route; the starter
        kit's verification tests are enabled.
- [ ] **6.13 Test deleting an account that has data.** `ProfileUpdateTest` deletes a user
      with no data. Bets reference bookmakers, tipsters and lists through composite
      foreign keys without cascade, so whether the delete succeeds depends on the
      order the database cascades in, and SQLite and Postgres may differ.
      - A feature test that creates a user with the full `DemoDataSeeder` data set
        (accumulators, tags, teams, settled and open bets), deletes the account
        through `DeleteUserForm`, and asserts every user-owned table is empty for that
        user and untouched for a second user. It must pass in both CI jobs.
      - If it fails on either database, fix it by deleting in dependency order inside
        a transaction in a domain action, not by loosening the foreign keys.

### Later, when needed

- [x] **6.14 Team and competition picker in the bet form.** The form uses native
      `<datalist>`s, which are awkward on phone keyboards, and renders every team of
      the sport into the page for each selection. `BetForm::sportSuggestions()` also
      runs two queries per selection on every render (40 for a 20-leg accumulator).
      - Replace the datalists with a combobox built on the 3.2 dropdown component:
        type to filter, pick with keys or touch, "Create ‘…’" as the last option;
        results fetched from the server on typing (debounced, capped at ~20), not
        rendered up front.
      - Load suggestions once per sport per render (memoise by sport id), not per
        selection.
      - Tests: existing bet-form tests keep passing; a query-count test for a 10-leg
        accumulator; isolation (suggestions never include another user's entries).
- [ ] **6.15 Bulk actions and undoing an import.** A wrong import of 500 bets can only be
      removed one bet at a time (the 4.1b note says so).
      - Bets list: a "Select" mode with checkboxes, "Select all n matching", and the
        actions Delete and Add/Remove tag. Every action re-queries the ids through
        `$user->bets()` so posted ids of another user are ignored.
      - Imports: store an `import_batch` id (new nullable column, same value for all
        bets of one confirm) and add an "imported in batch" filter, so "undo the last
        import" is: filter, select all, delete.
      - Depends on 6.3; much safer after 6.11 (bulk delete becomes recoverable).
- [ ] **6.16 Chart values and more date ranges.** The profit chart has no way to read a
      value (2.14 chose no tooltips), and the ranges are 30/90 days, this year, all
      time and custom.
      - Chart: tapping or hovering a point shows its date and cumulative profit in a
        small label (Alpine, no library); the same values are available to screen
        readers as a visually hidden table.
      - Ranges: add "This month", "Last month" and "Last year" to `DateRange`.
- [ ] **6.17 Statistics performance.** `StatisticsQuery::bets()` loads every matching bet
      with seven relations on each render, and Home runs it twice. Fine at ~600 bets;
      revisit when a page takes over ~300 ms or a user passes ~10,000 bets.
      - First, cheap: on Home, get the open stake with one aggregate query instead of
        a second full load; add indexes on `selections (user_id, sport_id)`,
        `(user_id, competition_id)`, `(user_id, market_id)` and
        `bets (user_id, status)` in a new migration.
      - Then, only if still slow: load plain rows (no models) for the calculator, or
        cache the `BetFigures` list per user and filter, invalidated when any of the
        user's bets changes. Do not move the calculations into SQL: they must stay in
        `app/Domain/` and unit-tested.
      - Add a query-count test on the statistics page so regressions show up.
- [ ] **6.18 Editable settlement date.** Since 6.1 statistics no longer depend on
      `settled_at`, it only matters for the bankroll balance by date (4.3) and the
      "Recently settled" sort. Build when the owner wants the bankroll to match a
      bookmaker statement to the day.
      - Domain: `SettleBet` takes an optional "settled at" for the moment a bet
        becomes settled (default now, as today) and gets a method that changes the date
        of an already settled bet. Un-settling still clears it, a bet that stays settled
        keeps it. Reject a date before `placed_at` or in the future.
      - UI: a "Settled" date + 24-hour time pair (the 3.3 inputs) on the edit form, shown
        only while the bet is settled; the bet detail's "Settled" row links to it.
      - Update the 2.9 and 3.4 notes in `docs/spec.md`.
      - Tests: unit tests for the validation; a feature test for changing the date and
        the bankroll moving with it; isolation.
- [x] **6.19 Reset account.** Asked for by the owner: delete all of one's bets and start
      over without deleting the account. Settings → Profile gets a "Reset Account"
      section above "Delete Account"; its dialog warns that the bets (with their
      selections, results and tags) are permanently deleted and that it cannot be
      undone, says what is kept, links to the CSV export as a backup and asks for the
      password. Design in "Reset account" in `docs/spec.md`.
      - Tests: bets, selections and tags deleted and everything else kept; a wrong
        password keeps the bets; another user's bets untouched.

### Considered and left out

Not planned; listed so they aren't proposed again without a reason. Lay/exchange bets
(liability, commission) need a change to the settlement rules in the spec and are only
worth it if the owner bets on exchanges (open question). Goals and limits, stake-band
analysis and "units": nothing in the owner's use asks for them. Dead heats, odds boosts
and payout caps are already covered by the manual payout.

## Phase 7 — API

Asked for by the owner: an authenticated JSON API, starting with recording bets. Design
in "API" in `docs/spec.md`.

- [x] **7.1 Create a bet through the API.** Laravel Sanctum personal access tokens;
      Settings → API tokens to create (shown once), list and revoke them.
      `POST /api/v1/bets` with every field of the New bet form (bookmaker by id or
      name, tipster, tags, notes, selections with teams, competition, market, start
      time and closing odds), saved through `RecordBet`. The contract for consumers is
      `docs/api/openapi.yaml`, checked by `OpenApiContractTest` (every route
      documented, examples valid and working, real requests and responses match).
      Feature tests: 401 without or with a revoked token, every field saved, validation
      messages, another user's bookmaker refused, list entries only ever the token
      user's, token page isolation.
