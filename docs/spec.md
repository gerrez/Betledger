# BetLedger — product spec

> Status: **in design**. Scope, data model, settlement and statistics rules are agreed; screens are
> next. This file is the source of truth for implementation work.

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
| timestamps | | |

`bet_tag` pivot: `bet_id`, `tag_id`.

### `selections`

| Column | Type | Notes |
|---|---|---|
| `id` | pk | |
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

`selection_team` pivot: `selection_id`, `team_id`.

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
- **Strike rate** = bets with profit > 0 ÷ settled bets with profit ≠ 0 (pushes excluded).
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

_None yet._
