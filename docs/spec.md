# BetLedger — product spec

> Status: **agreed (v1)**. Scope, data model, settlement and statistics rules, and screens
> are settled. This file is the source of truth for implementation work.

## Purpose

Let a bettor keep an accurate personal ledger of the bets they place with bookmakers:
record each bet by hand, settle it once the outcome is known, and see statistics that show
how they are actually performing.

## Scope

### v1

- Accounts: registration, login, password reset, profile (from the starter kit), plus a
  **base currency** setting.
- **Bookmakers** with their own currency and a fixed exchange rate to the base currency.
- **Bets**: singles and accumulators (parlays), entered manually, optionally flagged as
  **free bets**.
- **Settlement** per selection: won, lost, void/push, half-won, half-lost. Reversible.
- **Classification**: sport, competition, teams/participants, market type, tipster/strategy,
  free tags, and notes.
- **Statistics**: totals, profit over time, breakdowns by every classification, odds
  ranges, and closing line value (CLV).
- Odds are entered and shown in **decimal** format.

### Later (not v1, but the model must not block them)

- Bankroll: deposits/withdrawals and balances per bookmaker.
- Cash out (full and partial), each-way, system bets.
- CSV export and import.
- Automatic exchange rates.
- Merging duplicate list entries (e.g. two spellings of the same team).

## Concepts

- A **bet** is what you place at a bookmaker: one stake, one bookmaker, placed at a time.
- A bet has one or more **selections** (legs). A single has exactly one; an accumulator
  has two or more. Selections carry the sport, competition, teams, market, odds and
  result.
- **Lists** (sports, competitions, teams, markets, tipsters, tags) belong to the user and
  are created on the fly while entering a bet (type a new name → it's added). There are
  no global/shared lists, which keeps users fully isolated.
- **Teams** means the participants of the event: football clubs, or players in sports
  like tennis. A selection lists the participants of its event (usually two). Statistics
  "by team" cover all bets on events in which that team took part.

## Data model

All amounts are integers in minor units (cents/øre); v1 supports currencies with 2
decimal places only (EUR, DKK, GBP, NOK, SEK, USD, …). All odds and rates are exact
decimals (`decimal` columns, handled as strings/bcmath in PHP). Every table holding user
data has a `user_id` (directly, or via its parent bet) and is scoped by policies.

### `users` (existing) — add

| Column | Type | Notes |
|---|---|---|
| `base_currency` | char(3) | ISO 4217, default `EUR`. Can only be changed while the user has no bets (v1). |

### `bookmakers`

| Column | Type | Notes |
|---|---|---|
| `id` | pk | |
| `user_id` | fk users | |
| `name` | string | unique per user |
| `currency` | char(3) | ISO 4217 |
| `exchange_rate` | decimal(18,8) | 1 unit of `currency` = this many units of base currency; `1` when same as base |
| `is_active` | bool | inactive bookmakers are hidden from the bet form but keep their history |
| timestamps | | |

### Lists: `sports`, `competitions`, `teams`, `markets`, `tipsters`, `tags`

| Table | Columns (besides `id`, `user_id`, timestamps) | Unique per user |
|---|---|---|
| `sports` | `name` | `name` |
| `competitions` | `sport_id`, `name` | `sport_id + name` |
| `teams` | `sport_id`, `name` | `sport_id + name` |
| `markets` | `name` (e.g. 1X2, Asian handicap, Over/Under) | `name` |
| `tipsters` | `name` (tipster, model or strategy) | `name` |
| `tags` | `name` | `name` |

### `bets`

| Column | Type | Notes |
|---|---|---|
| `id` | pk | |
| `user_id` | fk users | |
| `bookmaker_id` | fk bookmakers | |
| `tipster_id` | fk tipsters, nullable | |
| `type` | enum `single`, `accumulator` | must match the number of selections |
| `placed_at` | datetime | |
| `stake` | bigint | minor units, in the bet's `currency` |
| `currency` | char(3) | copied from the bookmaker when saved |
| `exchange_rate` | decimal(18,8) | **copied from the bookmaker when saved**, so updating a bookmaker's rate later does not rewrite history. Editable on the bet. |
| `is_free_bet` | bool | stake not returned (see settlement) |
| `total_odds` | decimal(14,4) | product of selection odds, rounded to 4 dp; stored for queries |
| `status` | enum `open`, `settled` | |
| `settled_at` | datetime, nullable | |
| `payout` | bigint, nullable | minor units, set on settlement |
| `payout_is_manual` | bool | true when the user replaced the calculated payout with the amount actually paid |
| `profit` | bigint, nullable | `payout − stake` (free bets: `payout`) |
| `notes` | text, nullable | |
| `external_ref` | string, nullable | where an imported bet came from, e.g. `sportmarket:2384015187`; unique per user, so an import never creates a bet twice |
| timestamps | | |

`bet_tag` pivot: `bet_id`, `tag_id`, `user_id` (the bet's owner).

### `selections`

| Column | Type | Notes |
|---|---|---|
| `id` | pk | |
| `user_id` | fk users | the bet's owner |
| `bet_id` | fk bets, cascade delete | |
| `position` | smallint | order within the bet |
| `sport_id` | fk sports | |
| `competition_id` | fk competitions, nullable | |
| `market_id` | fk markets, nullable | |
| `event_name` | string | e.g. "Arsenal v Chelsea"; pre-filled from teams |
| `event_starts_at` | datetime, nullable | |
| `pick` | string | what was backed, e.g. "Arsenal −0.25" |
| `odds` | decimal(10,3) | > 1.000 |
| `closing_odds` | decimal(10,3), nullable | for CLV |
| `result` | enum `won`, `lost`, `void`, `half_won`, `half_lost`, nullable | null = pending |
| timestamps | | |

`selection_team` pivot: `selection_id`, `team_id`, `user_id` (the selection's owner).

## Settlement rules

Each selection with a result has a **factor**:

| Result | Factor |
|---|---|
| won | `odds` |
| lost | `0` |
| void / push | `1` |
| half_won | `(odds + 1) / 2` |
| half_lost | `0.5` |

The bet's factor is the **product** of its selection factors (a single has one).

- **When a bet is settled:** as soon as any selection is `lost` (the bet is lost, even if
  other selections are still pending), or when every selection has a result.
- **Cash bet:** `payout = stake × factor`, `profit = payout − stake`.
- **Free bet** (stake not returned): `payout = stake × max(factor − 1, 0)`,
  `profit = payout`. A voided free bet has profit 0.
- **Rounding:** compute with bcmath at scale ≥ 10, round **half up** to whole minor units
  once, at the end.
- **Reversing:** clearing or changing a selection result recalculates the bet. If it is
  no longer settleable it goes back to `open` and `payout`, `profit` and `settled_at` are
  cleared.
- **Manual payout:** on a settled bet the user may replace the calculated payout with the
  amount the bookmaker actually paid (rounding, max-payout caps); `profit` follows from
  it with the same formulas. Any later change to a selection result discards the manual
  payout (`payout_is_manual = false`) and recalculates; the UI warns before doing so.

Worked examples (stake 100.00 = 10000 minor units):

| Bet | Factor | Payout | Profit |
|---|---|---|---|
| Single @ 2.10, won | 2.10 | 21000 | +11000 |
| Single @ 1.95, half_won | 1.475 | 14750 | +4750 |
| Single @ 1.95, half_lost | 0.5 | 5000 | −5000 |
| Acca 1.50 × 2.00 × 1.80, middle leg void | 1.50 × 1 × 1.80 = 2.70 | 27000 | +17000 |
| Free bet single @ 3.00, won | 3.00 | 20000 | +20000 |
| Free bet acca, one leg void, rest lost | 0 | 0 | 0 |

## Statistics

All statistics are in the user's **base currency**: each bet's amounts are converted with
the bet's own `exchange_rate` (rounded half up to minor units per bet).

**Filters** (apply to every view): date range (by settlement date; open bets by placed
date), bookmaker, sport, competition, team, market, tipster, tag, bet type, and
**include/exclude free bets**.

**Totals:** number of bets (settled/open), turnover, profit, ROI, strike rate, average
odds, average CLV, open stake.

- **Turnover** = sum of stakes of settled **cash** bets (free bet stakes aren't money risked).
- **ROI** = profit ÷ turnover. Free bet profit is included in profit when free bets are
  included, so ROI can be shown with and without them.
- **Strike rate** = winning bets ÷ (winning + losing bets). A bet wins when its profit is
  > 0 and loses when its profit is < 0, or when it is a free bet whose bet factor is below
  1 (lost or ½ lost: it pays 0, profit 0, but it lost). Pushes (void) are excluded
  (decided).
- **Average odds** = mean `total_odds` of settled bets.
- **CLV** per bet = `total_odds ÷ product(closing_odds) − 1`, only for bets where every
  selection has closing odds. Average CLV = mean over those bets; show how many bets it
  covers.

**Views:**

1. **Profit over time:** cumulative profit line, grouped by day/week/month (automatic by
   range).
2. **Breakdowns:** the totals table split by bookmaker, sport, competition, team, market,
   tipster, tag and bet type. Selection-level dimensions (sport, competition, team,
   market): an accumulator counts under **every distinct value its selections have**, so
   breakdown rows can add up to more than the overall total (decided). The UI says so.
3. **Odds ranges:** totals grouped by `total_odds` bands: 1.01–1.50, 1.51–2.00, 2.01–3.00,
   3.01–5.00, 5.01–10.00, 10.01+.
4. **CLV:** average CLV, share of bets that beat the closing line, and CLV by breakdown.

## Import from Sportmarket

Sportmarket is a betting broker: one **order** is filled by one or more bets at
different bookies. Its orders export (`Orders` → CSV) has one row per fill. A trimmed,
anonymised copy of the owner's export is in `tests/Fixtures/sportmarket/orders.csv`
(17 orders, 48 rows); every case below appears in it.

**Columns:** `Order Id, Order Placed, Order Placer, Bet ID, Competition, Game Date,
Sport, Home, Away, Bet Type, Bet Status, Bookie, Username, User Currency, Stake (User
Currency), Price, Order Stake, Order Price, Reconciled P&L (User Currency), In Running,
Selection, Market, Country, Exchange Role, Event Name`. Find columns by header name, not
position.

**One BetLedger bet per `Order Id`**, made from that order's rows whose `Bet Status` is
not `failed` (a failed fill was never placed and has no P&L). Every bet is a single with
one selection.

| BetLedger | From the order's non-failed rows |
|---|---|
| bookmaker | chosen by the user on the import page (one whose currency equals `User Currency`; a bookmaker named "Sportmarket" is pre-selected) |
| `placed_at` | earliest `Order Placed` (UTC, microseconds dropped) |
| `stake` | sum of `Stake (User Currency)`, in minor units. Use this, not `Order Stake`, which can be 1 cent off |
| `currency`, `exchange_rate` | from the bookmaker, like the bet form; a row whose `User Currency` differs is an error |
| odds | stake-weighted average of `Price`, rounded half up to 3 dp (equals `Order Price`) |
| sport | `Sport`: `fb`, `fb_ht`, `fb_et` → "Football"; any other code is used as the name |
| competition | `Competition` |
| teams, `event_name` | `Home`, `Away`; event "Home v Away" |
| `event_starts_at` | empty: `Game Date` has no time |
| market | `Market`: `ahou` "Asian total goals", `ou` "Total goals", `ah` "Asian handicap", `1x2` "Match result", `ml` "Draw no bet", `extot` "Exact total goals"; any other code is used as the name. `fb_ht` adds " – 1st half", `fb_et` " – extra time" |
| `pick` | `Bet Type` of the fill with the largest stake, as written (e.g. "AGAINST Under 2.0 (Asian)", "Over 2.0 (Asian, 0-0)": the score is the score when the bet was placed in-play) |
| tags | "In-play" when any fill has `In Running` = `Yes` |
| `notes` | "Sportmarket order 2384015187 · 6 fills: mbook ×4, molly, fair" |

`Order Placer`, `Username`, `Country`, `Selection`, `Exchange Role` and `Event Name`
(always empty) are not used. List entries match the user's existing ones by name,
case-insensitively, before new ones are created.

**Result.** The export has no result column, only P&L. Settled orders get their result
from the payout Sportmarket reports: payout = stake + sum of `Reconciled P&L`. Compare it
with the calculated payout of each result (won, lost, void, half-won, half-lost) at the
bet's odds and stake, and take the nearest. The bet is settled through the settle service
with that result; when the reported payout differs from the calculated one (rounding of
the 3-dp odds, Sportmarket's cents, or fills with different results), it is kept as a
**manual payout**. `settled_at` is the game date at 23:59 UTC (the export has no
settlement time), but never before `placed_at`. On the owner's full export (588 orders)
this gives 331 won, 157 lost, 54 void, 30 half-won and 10 half-lost, with no order close
to two results; 211 bets get a manual payout, at most €1.14 from the calculated one.

**Rows that are skipped**, each listed with its reason in the preview:
- every row of the order failed;
- any non-failed row is not `settled` or `void` (the order isn't settled yet; import it
  again later);
- the order was imported before (`bets.external_ref` = `sportmarket:<Order Id>`, a new
  nullable column, unique per user).

## Screens and visual design

Mockups: `docs/design/project/*.dc.html` (also published as a private canvas for the owner).
They are the reference for layout and copy; sample numbers in them are illustrative.

| Screen | Mockup | Notes |
|---|---|---|
| Home / overview | `Main.dc.html` | Month profit card with sparkline, 4 KPI tiles, open bets preview |
| New / edit bet | `NewBet.dc.html` | Single/Accumulator switch; essentials first, "More details" folds competition, market, closing odds, tipster, tags, notes, exchange rate; live payout preview; sticky Save |
| Bets list | `Bets.dc.html` | Open/Settled/All tabs, filter chips; **inline settle buttons** per pending selection (Won, Lost, Void, ½ Won, ½ Lost) with Undo |
| Bet detail | `BetDetail.dc.html` | Result, profit in bet + base currency, CLV, result buttons, clear result, **manual payout override**, facts, delete |
| Statistics | `Statistics.dc.html` | Date range, filter chips, include-free-bets toggle, KPI tiles, cumulative profit chart, breakdown table by dimension, odds-range ROI bars, CLV panel |

Not mocked (build plainly in the same style): bookmakers management, lists management,
settings (base currency).

**Layout:** phone first for everything except statistics. Phone: bottom tab bar (Home,
Bets, centre "+" New bet, Stats, Settings). Desktop (≥ 1024 px): left sidebar with the
same items. Touch targets ≥ 44 px.

**Style: "calm fintech".**

| Token | Light | Dark |
|---|---|---|
| Background | `#F7F7FB` | `#111118` |
| Surface (cards) | `#FFFFFF`, 16–20 px radius, soft shadow or `#EEEDF4` border | `#1A1A23` |
| Text / muted | `#16151F` / `#5B5970` | `#ECEBF3` / `#A3A1B5` |
| Accent | `#6C5CE7` (soft: `#EFEDFD`, text on soft: `#3F2FB8`) | `#8B7FF0` |
| Profit text / fill | `#067647` / `#12B76A` (soft bg `#E7F8EF`) | `#4ADE80` / `#12B76A` |
| Loss text / fill | `#B42318` / `#F04438` (soft bg `#FEECEB`) | `#F97066` / `#F04438` |

- Font: **Geist** (Google Fonts), `font-variant-numeric: tabular-nums` everywhere numbers
  appear.
- Profit/loss is never shown by colour alone: always a `+`/`−` sign.
- Implement with Flux (free) components + Tailwind 4 theme tokens; map the tokens above
  into the Tailwind theme rather than hard-coding hex values in views.

## Open questions

<!-- Owner decisions still pending, plus assumptions Claude sessions had to make. -->

- **Postgres version in CI (assumed in 3.1).** The docs don't say which Postgres version
  production runs. CI uses the `postgres:17` image; change it in `.github/workflows/ci.yml`
  if production is on another major version.
- **Bookmakers and Lists on phones (assumed in 2.1).** The phone tab bar has five slots
  (Home, Bets, New bet, Stats, Settings) and the mockups don't show where Bookmakers and
  Lists go on phones. They are linked from the Settings page on phones (and from the
  sidebar on desktop), and the Settings tab stays highlighted on those pages. Log out is
  also on the Settings page on phones.
- **Dark-mode tokens not in the style table (assumed in 2.1).** The table gives no dark
  values for the card border, the soft accent/profit/loss backgrounds or text on the
  accent colour. Chosen: border `#2A2935`, accent soft `#26233F` (text `#C4BDFA`), profit
  soft `#12261D`, loss soft `#2E1717`, and dark text (`#111118`) on the `#8B7FF0` accent
  for contrast. Revisited in 2.16: kept. Every text/background pair in both themes is
  at least WCAG AA (4.5:1), e.g. muted on dark surface 6.8:1, dark text on the dark
  accent 5.7:1.
- **Page widths on desktop (assumed in 3.5).** Home and Statistics use the full content
  width (up to 1120 px). On desktop, Home shows the KPI tiles in one row above the profit
  card and the open bets side by side; phones keep the stacked layout. The Bets list
  stays a single column capped at 672 px (`max-w-2xl`): its cards put the title and the
  amounts on opposite edges, which drift apart and get harder to scan when stretched,
  and a multi-column grid would break the list's top-to-bottom sort order. The bet form
  and bet detail stay narrow and centred. The public `/` page redirects to Home, or to
  the login page for guests.
- **Touch targets on desktop (assumed in 2.16).** The ≥ 44 px rule is applied below the
  desktop breakpoint (1024 px), where the phone layout is used. On desktop, Flux's
  compact controls (40 px inputs, 32 px small buttons) stay as they are, since they're
  used with a mouse. Small controls that keep their look on phones (the free-bet switch,
  checkboxes, the × on team/tag chips) get an invisible 44 px hit area instead.
- **Rounding negative amounts (assumed in 2.2).** "Round half up" is only ambiguous for
  negative values, which settlement never rounds (payouts are ≥ 0) but base-currency
  conversion of a loss does. Chosen: negatives round half **away from zero** (−2.5 → −3),
  so a loss converts to the same magnitude as an equal profit.
- **Supported currencies (assumed in 2.3).** v1 offers a fixed list of two-decimal
  currencies: AUD, CAD, CHF, CZK, DKK, EUR, GBP, NOK, NZD, PLN, SEK, USD
  (`App\Domain\Currency`). Adding one is a one-line change.
- **Changing the base currency (assumed in 2.3).** Base currency is set on its own
  Settings → Currency page. Changing it sets the rate of bookmakers in the *new* base
  currency to 1 and leaves every other rate as it was; the page tells the user to check
  their bookmakers' rates. Rates are not converted automatically (a bookmaker in the old
  base currency keeps its rate of 1 until the user edits it).
- **Reactivating bookmakers (assumed in 2.3).** Deactivation is reversible: an inactive
  bookmaker has a Reactivate button. Bookmakers can't be deleted (they will hold bets).
- **Decimal comma (assumed in 2.3, revised in 2.6).** Phone keyboards in many locales
  only offer a comma on the decimal keypad, so odds, closing odds, stake and exchange
  rates (bet form and Bookmakers page) accept one decimal comma (`1,95` = `1.95`).
  Thousands separators are not accepted (`1,000.50` is rejected), so amounts are never
  ambiguous.
- **Deleting list entries (assumed in 2.4).** The Lists page deletes an entry only while
  nothing uses it; otherwise it shows "In use" instead of Delete. A sport counts as in use
  while it has competitions or teams (deleting it would delete them too); from 2.5 on, an
  entry used by a bet or selection is in use. Renaming to a name that already exists in
  the list (or, for competitions and teams, in the same sport) is refused — merging is a
  "Later" feature. Competitions and teams can't be moved to another sport.
- **Case in list names (assumed in 2.4, revised in 2.6).** The Lists page keeps names
  unique as typed, but the bet form matches a typed name to an existing entry ignoring
  case ("arsenal" uses "Arsenal"), so it never creates near-duplicates. Competitions and
  teams are matched within the selection's sport: "Brazil" in volleyball is a different
  team from "Brazil" in football.
- **Owner on selections and pivots (assumed in 2.5).** `selections`, `bet_tag` and
  `selection_team` also store `user_id` (always the bet's owner). Bets and selections
  reference bookmakers, list entries, bets and selections together with the owner
  (`(x_id, user_id)` foreign keys), so the database itself refuses linking another user's
  records, as for competitions and teams in 2.4. `type`, `status` and `result` are string
  columns backed by PHP enums rather than database enums.
- **What 2.5 leaves to the bet form (assumed in 2.5).** The database does not check that a
  selection's competition and teams belong to the selection's sport, that `type` matches
  the number of selections, or that `total_odds` matches them; saving a bet (2.6/2.7) and
  settling it (2.9) keep these consistent.
- **Base currency with bets (assumed in 2.5).** Once a user has a bet, the Currency page
  shows the base currency without a Save button and explains why; the model refuses the
  change too. Deleting every bet unlocks it again.
- **Bet form details (assumed in 2.6).** List entries are typed with suggestions from the
  user's own lists (a plain `<datalist>`, since Flux's combobox is a Pro component); a
  new name is added to the list when the bet is saved. Teams and tags are added as chips
  ("Add" or Enter; a name still in the box when saving is added too). The mockup has no
  event field, but `event_name` is required, so the form has an "Event" field that fills
  in from the teams ("Arsenal v Chelsea") until the user edits it; a bet without teams
  (e.g. an outright) needs it typed. The sport is a field in the selection card.
- **Bet form defaults (assumed in 2.6).** A new bet starts at the bookmaker and sport of
  the user's latest bet (else the first active bookmaker alphabetically, and no sport),
  placed now. Times are entered and stored in the app's time zone (UTC); there is no
  per-user time zone yet, so a user in Denmark sees "now" one or two hours behind.
  Owner: should users get a time zone setting?
- **Limits on entry (assumed in 2.6).** Stakes go up to 999,999,999.99 and odds up to
  9,999,999.999 with at most three decimals (the column's precision), so payouts always
  fit in an integer. Saving a bet goes to the bets list (a placeholder until 2.8) with a
  "Bet saved" message.
- **Accumulators in the bet form (assumed in 2.7).** Switching to Accumulator adds a
  second selection (in the sport of the first); switching back to Single keeps only the
  first selection, after a confirmation. An accumulator keeps at least two selections
  (Remove appears from three on) and has at most 20. Its total odds may not exceed
  9,999,999.999, the limit for a single selection, so payouts still fit in an integer.
- **Editing bets (assumed in 2.7).** Every bet, open or settled, is edited with the same
  form at `/bets/{id}/edit` (linked from the bets list and bet detail pages once they
  exist, 2.8/2.11); saving goes to the bet detail page (the bets list until 2.11) with
  "Bet updated". The form doesn't change results (until 3.4, see "Settling from the
  edit form"): each kept selection keeps its result, a removed selection takes its
  result with it, and an added selection is pending. When the stake, the free bet flag or the selections' odds or results change, the bet is settled again by the settlement
  rules: it can go from settled to open (e.g. a pending selection was added) or from open
  to settled (e.g. the last pending selection was removed; `settled_at` is then the time
  of the edit), a bet that stays settled keeps its `settled_at`, and a manual payout is
  replaced by the calculated one (the form warns about this). Other edits (notes, tags,
  dates, bookmaker, rate, …) leave the settlement, including a manual payout, alone. A
  bet's own bookmaker stays selectable after it is deactivated, marked "(inactive)".
- **Bets list (assumed in 2.8).** The bookmaker filter lists every bookmaker, inactive
  ones included; the sport filter shows a bet when any of its selections is in that
  sport. "Open · n" counts the open bets that pass the filters. Each tab has its own
  orders, the first being its default: Open — starts soonest (the next pending
  selection's start; bets without a start time last), newest, oldest placed; Settled —
  recently settled, newest, oldest placed; All — newest, oldest placed. Changing tab
  goes back to that tab's default order. 20 bets per page. A card shows the event's start
  for a single (or "placed …" when it has none) and "placed …" for an accumulator, in
  the app's time zone ("today 18:30", "Sat 11:05" within six days either way, else
  "14 Oct 18:30"). Total odds show all four stored decimals ("@ 5.4054"), not the
  mockup's rounded "5.41". A settled single's banner names its result ("Settled: ½ Won");
  an accumulator's just says "Settled", with payout and profit. A card opens the bet
  detail page (the edit form until 2.11).
- **Settle service (assumed in 2.9).** Results are set one selection at a time
  (`App\Domain\Bets\SettleBet`), and "Clear result" clears every selection's result,
  making the bet open again. A bet that becomes settled is settled at the time of the
  change; one that stays settled keeps its settlement date, so an accumulator settled
  as lost by one leg keeps that date when its other legs get results later. Setting the
  result a selection already has is no change and keeps a manual payout. A manual
  payout can only be entered on a settled bet and can't be negative (the bet detail
  form, 2.11, sets an upper limit); it can be discarded to go back to the calculated
  payout.
- **Inline settling (assumed in 2.10).** Open bets in the bets list (Open and All tabs)
  have result buttons for one pending selection at a time: a single's ("How did it
  go?"), or an accumulator's pending selection that starts soonest (those without a
  start time last, then in entry order), "Settle the next selection, …" or "Settle the
  last selection, …". Settled bets have no buttons, not even an accumulator lost by one
  leg with others still pending; their remaining results are set on the bet detail page
  (2.11). The latest result set on each card can be undone: a settled bet's banner has
  Undo ("Settled: Won" for a single, "Final leg won · bet settled" or "Leg lost · bet
  settled" for an accumulator), and a result that leaves an accumulator open shows as
  "Over 2.5 goals: Won" with Undo. Undo makes that selection pending again. Bets keep
  their place on the page while results are set or undone (a settled bet stays on the
  Open tab, and the "starts soonest" order doesn't move a card), until the tab, a
  filter, the order or the page changes; Undo is offered only until then. A result
  changed elsewhere in the meantime is neither overwritten nor undone.
- **Bet detail page (assumed in 2.11).** `/bets/{id}`. Every selection has result buttons
  (also on a settled bet, so an accumulator lost by one leg can get its other results)
  and, once it has a result, a "Clear result" button that makes that selection pending
  again ("Clear result (back to open)" on a single). The result card's badge is the
  single's result, "Settled" for an accumulator, or "Open"; the mockup shows no open bet,
  so an open bet shows what it pays if every pending selection wins (results already set
  count). Profit is also shown in the base currency (with the bet's own rate) when the
  bet is in another currency. CLV is shown per selection with closing odds ("Beat /
  Missed / Matched the close", one decimal), and for an accumulator also as a "CLV" fact
  when every selection has closing odds. The amount actually paid can be 0 up to
  999,999,999,999.99 (two decimals, decimal comma accepted), also more than the
  calculated payout (boosts). While the payout was entered by hand, every result change
  asks for confirmation first. Deleting asks for confirmation and goes to the bets list
  with "Bet deleted.". The edit form's close button and saving now go back to the bet
  detail page (not the bets list, as in 2.7). The Bets tab stays highlighted on the
  detail and edit pages.
- **Statistics service (assumed in 2.12).** `App\Domain\Statistics`: `StatisticsQuery`
  loads the user's filtered bets, `StatisticsCalculator` does the sums. Each filter takes
  one value (one bookmaker, one sport, …); dates are whole days in the app's time zone
  (UTC), both included. Stake and profit are each converted to the base currency with the
  bet's rate and rounded per bet; profit is the stored one, so a manual payout counts.
  **Open stake** counts open cash bets only (a free bet's stake isn't at risk, as for
  turnover), while the open bet count includes free bets. **Average odds** cover every
  settled bet, free bets included. **CLV** and "beat the close" cover settled bets only
  (as in the mockup: "47 settled bets have no closing odds"); a bet beats the close when
  its CLV is above 0, so matching it doesn't count. A cash bet whose payout was
  entered by hand as exactly its stake (profit 0) is neither a win nor a loss in the
  strike rate, and so is a free bet that won but was paid 0 by hand. **Breakdowns:** a bet with several
  tags counts under each tag, like an accumulator under each sport; bets without a value
  (no tipster, tags, competition, market or teams) form a "none" row, always last; the
  other rows come by number of settled bets, most first, then by name, and a value with
  only open bets still gets a row. **Odds bands** run up to and including their upper
  bound at the stored four decimals: 1.5000 is in 1.01–1.50, 1.5050 in 1.51–2.00.
  **Profit over time** counts profit on the settlement date, by day for ranges up to 31
  days, by week (from Monday) up to 182 days, else by month, with a point for every
  period, empty ones included, from the start of the range (or the first settled bet,
  for "All time") to its end (or the last settled bet).
- **Statistics page (assumed in 2.13).** `/statistics` opens on the last 90 days (as in
  the mockup). "30 days" and "90 days" are today and the 29 or 89 days before, "This
  year" runs from 1 January to today, and "Custom…" shows From and To dates, starting
  from the range shown until then; either can be left empty (no limit), and dates
  entered the wrong way round are read the right way round. The range, filters, free
  bets toggle and breakdown dimension are kept in the URL. Each filter is a dropdown with
  one value (as in the service); competitions and teams are grouped by sport, since
  names repeat across sports. Amounts are shown without the currency symbol (the
  heading names the base currency; KPI tiles show the symbol), ratios with one decimal,
  "Beat the close" as a whole percentage, and "—" where there is nothing to divide by.
  The breakdown has tabs for sport, bookmaker, tipster and bet type, and "More" for
  competition, team, market and tag. Its "Bets" column counts settled bets, with "+n
  open" below when the row has open bets. The note under the table explains that
  accumulators count under every value (sport, competition, team, market), or that a bet
  counts under each of its tags. Odds-range bars are full width at ±25 % ROI, as in the
  mockup. A user without bets sees "No statistics yet" with a New bet button; a range or
  filters that match no bets say so (with Clear filters).
- **Profit-over-time chart (assumed in 2.14).** Plain SVG, no chart library. The line
  starts at 0 before the first period and has one point per period at its cumulative
  profit, with the area between the line and 0 shaded. The value axis always includes 0
  and has about four round steps (1, 2 or 5 whole units, then 10, 20, 25 or 50 times a
  power of ten), labelled in whole units without the symbol. Up to five periods spread
  from the first to the last are labelled under their points ("13 Oct" by day or week,
  the Monday for a week; "Oct 2026" by month), three on phones. Without settled bets in
  the range the card says the chart shows once bets are settled. No tooltips: the chart
  has a text description for screen readers (periods and the final cumulative profit).
- **Home overview (assumed in 2.15).** The home page stays at `/dashboard`, where logging
  in leads. The month selector lists every month from the user's first bet (placed or
  settled) to the current one, newest first; the month is kept in the URL, and an
  unknown or future month shows the current one. The profit card, its ROI badge and the
  strike rate, average odds and closing line tiles cover the bets **settled in that
  month** (free bets included, in the base currency as on the statistics page; the
  current month up to today). "Open stake" and the open bets preview always show the bets
  open **now**, whichever month is chosen (the mockup's "3 bets pending" and "Open bets ·
  3" agree). The sparkline is the month's cumulative profit by day, drawn like the
  statistics chart without axes, and is left out when nothing was settled that month.
  Numbers are formatted as on the statistics page, so the mockup's "55%" shows as
  "55.0%", and ROI keeps its sign ("ROI +6.2%"). The preview lists the three open bets
  that start soonest (the bets list's Open order), each opening its bet detail page;
  "Settle →", and "All n open bets" when there are more than three, go to the bets list.
  An accumulator's line says "2 of 3 won" while every result so far is a win, else
  "2 of 3 settled", and has no count before its first result; a free bet says "free
  bet". A user without bets sees "No bets yet" with a New bet button.
- **Dates and times in the bet form (assumed in 3.3).** "Placed" and "Starts" are a
  date input plus a 24-hour time typed as text (`HH:MM`), since a browser time input
  shows AM/PM in some locales. The date picker still shows the browser's date format
  (e.g. mm/dd/yyyy in English (US)); only the time is forced to 24 hours. Phone number
  pads often have no colon, so the time also accepts a point or comma ("18.30") or no
  separator ("1830", "905" = 09:05) and is written back as "18:30". A start needs both a
  date and a time, or neither: a date-only start would have to invent a time.
- **"More details" in the bet form (assumed in 3.3).** Each selection has its own toggle
  for competition, market and closing odds, at the bottom of its card, and the bet's
  toggle (tipster, exchange rate, tags, notes) only opens the bet's details below it.
  Editing a bet opens a selection's details when it has any of them, and the bet's
  details when it has a tipster, tags or notes. A failed field opens the details that
  hold it.
- **Settling from the edit form (assumed in 3.4).** Every selection in the edit form,
  added ones included, has the detail page's result buttons and, once it has a result,
  "Clear result". A result chosen in the form is saved with the rest of the form, not
  on the click, so Cancel (the close button) leaves the bet as it was. Saving settles
  the bet through the settle service like the other changes in "Editing bets": a bet
  that becomes settled is settled at the time of saving, one that stays settled keeps
  its settlement date, and choosing the result a selection already has is no change and
  keeps a manual payout. While the payout was entered by hand, the form's warning lists
  results among the changes that replace it; there is no extra confirmation, as for the
  form's other changes. The payout preview follows the chosen results: "Payout" once
  they settle the bet, "Payout if every open selection wins" while some are set, else
  "Payout if it wins". The New bet form has no result buttons.
- **Sportmarket import (owner, before 4.1).** One bet per Sportmarket order, built from
  the order's non-failed rows. Orders that aren't fully settled are skipped and picked up
  by a later import, so the import never has to update a bet it created. The owner's
  export had no open orders, so the status Sportmarket uses for them is unknown; any
  status other than `settled`, `void` or `failed` counts as not settled.
- **Sportmarket import details (assumed before 4.1).** The game's kick-off time isn't in
  the export, so `event_starts_at` stays empty and `settled_at` is the game date at
  23:59 UTC. In-play bets get an "In-play" tag. The market names for Sportmarket's codes
  are listed in "Import from Sportmarket".
- **Sportmarket import mapping (assumed in 4.1a).** `App\Domain\Import\Sportmarket`
  reads the export and maps each order; nothing is saved (that's 4.1b). The fill with
  the largest stake (the first of them on a tie) gives the bet its pick, and also its
  market, competition, sport and teams, since fills of one order can be on different
  markets (`ou` and `ahou` in order 2384015187). `placed_at`, the In-play tag and the
  currency check only look at fills that didn't fail. The notes list the bookies by
  number of fills, then by stake, most first ("mbook ×4, molly, fair"); a single fill
  says "1 fill: pin88". When the reported payout is exactly as near to two results, the
  first of won, lost, void, half-won, half-lost is taken (none is in the owner's
  export). A file that isn't an orders export (missing columns, a row with the wrong
  number of columns or without an Order Id) is refused as a whole; an order in another
  currency, or with a value that can't be read (an amount, price or date), is an error
  for that order only, naming the line, and the other orders are still imported. A
  missing Market or Competition leaves it empty; a missing Home or Away uses the other
  team as the event; no teams, Sport or Bet Type is an error.
