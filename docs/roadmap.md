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
- [ ] **4.2 CSV export.** Download all of the user's bets (one row per selection, with
      the bet's fields repeated) from Settings. Money in major units with the currency
      code, odds as entered. Isolation test.
- [ ] **4.3 Bankroll.** Deposits and withdrawals per bookmaker, and balances (deposits −
      withdrawals + settled profit − open cash stakes). Shown per bookmaker and in total.
- [ ] **4.4 Cash out.** Full and partial cash out of an open bet, recorded as its own
      settlement outcome so statistics stay correct. Reversible like any settlement.
- [ ] **4.5 Merging list entries.** Merge duplicates (e.g. two spellings of a team) on
      the Lists page; bets move to the kept entry.
- [ ] **4.6 Import in BetLedger's own format.** Import the 4.2 export format, reusing the
      4.1 upload/preview/confirm flow.
- [ ] **4.7 Automatic exchange rates.** Fetch rates for bookmakers in other currencies;
      a bet still copies the rate when saved.
- [ ] **4.8 Each-way and system bets.**

## Phase 5 — Android app and push notifications

An experiment the owner asked for (ahead of the rest of Phase 4): an Android app that
shows the web app and receives push notifications from the server. Design in "Android
app and push notifications" in `docs/spec.md`. The app is a native Kotlin shell around a
WebView, so every screen comes from the web app; push (Firebase Cloud Messaging) is
native.

- [x] **5.1 Push devices and the FCM channel (server).** `push_devices` table, model,
      policy, factory; `POST /push-devices` for the app to register its token (the web
      page reads it from the app's JavaScript bridge); logging out forgets that phone.
      A Laravel notification channel that sends through the FCM HTTP v1 API with a
      service-account key (`FCM_CREDENTIALS`), no new package, and removes tokens FCM
      reports as unregistered. Settings → Notifications: list and remove devices, send a
      test notification message or data message. Tests with faked HTTP; isolation.
- [ ] **5.2 Android app shell.** An `android/` Gradle project (Kotlin, min SDK 26,
      package `com.betledger.app`, `google-services.json` git-ignored):
      full-screen WebView on `APP_URL` with the `BetLedgerAndroid` bridge
      (`getPushToken()`, `getDeviceName()`), Firebase Messaging service (`onNewToken`
      dispatches `betledger:push-token` in the page, `onMessageReceived` shows data
      messages), the `general` notification channel, the Android 13+
      `POST_NOTIFICATIONS` prompt, opening a tapped notification's `url` in the
      WebView, back button through WebView history, file chooser for the CSV import,
      external links in the browser. README for building it in Android Studio and
      running it on an emulator against a local server. Built by the owner, not CI.
- [ ] **5.3 Settle reminders.** A scheduled command (every 15 minutes) that notifies the
      user about pending bets whose last selection started more than a set time ago
      (once per bet), opening the bet. A per-user on/off setting on Settings →
      Notifications. Feature tests with frozen time; isolation.
- [ ] **5.4 Settle from the notification (bonus).** Won/Lost action buttons on the settle
      reminder that settle the bet without opening the app, through a token-authenticated
      endpoint (Laravel Sanctum, justified in the PR) and the 2.9 settle service.
