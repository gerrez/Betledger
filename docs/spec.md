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

### After v1

- **Bankroll** (4.3): deposits and withdrawals per bookmaker, and balances, per bookmaker
  and in total (see "Bankroll").

### Later (not v1, but the model must not block them)

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
| `timezone` | string | IANA name (`Europe/Copenhagen`), default `UTC`; one of PHP's current identifiers (Laravel's `timezone:all` rule), so legacy names like `Europe/Kiev` are refused. Times are entered and shown, and days and months grouped, in this zone; every stored datetime stays UTC (6.2). Changing it moves no stored time. |

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

The **currency** can't be changed once the bookmaker has bets or bankroll transactions
(both are amounts in its currency): the Bookmakers page shows it read-only with the reason,
and saving another currency is refused with a validation error. The rate stays editable.

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
| `event_at` | datetime | when the bet was decided, which statistics count a settled bet on (6.1): the latest `event_starts_at` of its selections (an accumulator counts on its last leg), else `placed_at`, never before `placed_at`. A Sportmarket import uses its game date (the same instant as its `settled_at`) and keeps it when edited, unless a selection gets a start time. Worked out whenever a bet is recorded, edited or imported; settling doesn't change it. Indexed with `user_id`. Internal: not in the API or the CSV export |
| `stake` | bigint | minor units, in the bet's `currency` |
| `currency` | char(3) | copied from the bookmaker when saved |
| `exchange_rate` | decimal(18,8) | **copied from the bookmaker when saved**, so updating a bookmaker's rate later does not rewrite history. Editable on the bet. |
| `is_free_bet` | bool | stake not returned (see settlement) |
| `total_odds` | decimal(14,4) | product of selection odds, rounded to 4 dp; stored for queries |
| `probability` | decimal(5,2), nullable | the user's estimate of the chance the bet wins, as a percentage (`55.50` = 55.5 %), above 0 and at most 100 |
| `bet_value` | decimal(11,2), nullable | the bet's value as a percentage; `total_odds × probability` when there is a probability, else as entered (see "Probability and bet value") |
| `status` | enum `open`, `settled` | |
| `settled_at` | datetime, nullable | when the result was recorded; drives the bankroll and the "Recently settled" sort, not statistics |
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

### `bankroll_transactions`

| Column | Type | Notes |
|---|---|---|
| `id` | pk | |
| `user_id` | fk users, cascade delete | |
| `bookmaker_id` | | composite fk `(bookmaker_id, user_id)` → `bookmakers(id, user_id)`, so the database refuses another user's bookmaker |
| `type` | enum `deposit`, `withdrawal` | `App\Domain\TransactionType` |
| `amount` | bigint | minor units of `currency`, > 0 |
| `currency` | char(3) | copied from the bookmaker when saved |
| `occurred_on` | date | a calendar date, not a datetime, so per-user time zones (6.2) don't move it |
| `note` | string(255), nullable | |
| timestamps | | |

Index `(user_id, bookmaker_id, occurred_on)`. No exchange rate is stored (see "Bankroll").

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

## Probability and bet value

Both optional, on the bet as a whole (an accumulator has one probability, for all of its
selections winning). Entered in the bet form's "More details" and through the API, shown
on the bet detail page.

- **Probability:** the user's estimate of the chance the bet wins, a percentage above 0
  and at most 100 with up to 2 decimals.
- **Bet value** (first version): `total_odds × probability`, rounded half up to 2
  decimals, as a percentage: 100 % is break-even, above 100 % a value bet. Odds 2.10 at
  55 % → 115.50 %. Calculated in `App\Domain\BetValue` with bcmath, never floats.
- With a probability, the bet value is always calculated: the form shows it read-only
  and the API rejects a `bet_value` sent together with a `probability`. It is
  recalculated whenever the bet is saved, so changing the odds updates it.
- Without a probability, the user may enter the bet value directly (e.g. from a tipster
  or another tool), or leave it empty.
- Void, half-won and half-lost outcomes are not taken into account yet; see the open
  question.

## Statistics

All statistics are in the user's **base currency**: each bet's amounts are converted with
the bet's own `exchange_rate` (rounded half up to minor units per bet).

**Filters** (apply to every view): date range (settled bets by their event date,
`event_at`; open bets by placed date), bookmaker, sport, competition, team, market, tipster, tag, bet type, and
**include/exclude free bets**.

**Totals:** number of bets (settled/open), turnover, profit, ROI, strike rate,
break-even strike rate, average and median odds, average CLV, open stake.

- **Turnover** = sum of stakes of settled **cash** bets (free bet stakes aren't money risked).
- **ROI** = profit ÷ turnover. Free bet profit is included in profit when free bets are
  included, so ROI can be shown with and without them.
- **Strike rate** = winning bets ÷ (winning + losing bets). A bet wins when its profit is
  > 0 and loses when its profit is < 0, or when it is a free bet whose bet factor is below
  1 (lost or ½ lost: it pays 0, profit 0, but it lost). Pushes (void) are excluded
  (decided).
- **Break-even strike rate** (6.6) = mean of `1 ÷ total_odds` over the bets the strike
  rate counts (wins and losses; no pushes), worked out with bcmath (each `1 ÷ total_odds`
  to 10 places) and rounded half up once, for display. The strike rate those bets need
  to make neither a profit nor a loss at level stakes: a strike rate above it means a
  profit.
- **Average odds** = mean `total_odds` of settled bets; an arithmetic mean, so one long
  shot pulls it up. **Median odds** = the middle `total_odds` of settled bets (the mean
  of the middle two for an even number).
- **CLV** per bet = `total_odds ÷ product(closing_odds) − 1`, only for bets where every
  selection has closing odds. Average CLV = mean over those bets; show how many bets it
  covers.

**Risk figures** (6.5) are worked out over the settled bets that pass the filters, taken
in event order (`event_at`, ties by bet id), free bets included when they are:

- **Max drawdown** = the largest fall of cumulative profit from an earlier peak, with the
  event dates of the peak and the low. Cumulative profit starts at 0, so a run of losses
  from the first bet falls from "the start". The peak is the last bet that reached it.
  0 when cumulative profit never fell; on a tie, the first such fall.
- **Current drawdown** = the highest cumulative profit so far minus the cumulative
  profit after the last bet, with the date of that peak; 0 at the peak.
- **Longest winning / losing streak** = most wins or losses in a row, by the strike
  rate's win and loss (so a lost free bet is a loss); pushes neither extend nor break a
  streak. **Current streak** = the streak the last win or loss belongs to.
- **Biggest win / biggest loss** = the bet with the highest profit above 0 / lowest
  profit below 0 (a lost free bet loses no money, so it is never the biggest loss);
  the earliest on a tie. The page links to it.
- **Average stake** = turnover ÷ the number of settled cash bets, rounded half up to
  minor units once.

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

## Bankroll

The Bankroll page (`/bankroll`) shows, per bookmaker and in total: **number of bets**,
**in open bets**, **turnover**, **ROI**, **profit** and **balance**. Per bookmaker it also
shows the sums deposited and withdrawn, and lists its transactions (newest first by date,
then by id), which can be added, edited and deleted.

**Definitions.** All figures are all-time (no date filter) and free bets are always
included. The bet figures are the ones Statistics uses (`StatisticsCalculator::totals()`,
reused in `App\Domain\Bankroll\BankrollCalculator`), so changes to how profit is
calculated (e.g. cash out, 4.4) carry over.

- **Number of bets** = settled + open bets, shown with the open count ("42 · 3 open").
- **In open bets** (open stake) = stakes of open **cash** bets; an open free bet's stake
  isn't the user's money.
- **Turnover** = stakes of settled cash bets.
- **Profit** = the stored `bets.profit` of settled bets, free bets included (it already
  reflects manual payouts and half results).
- **ROI** = profit ÷ turnover; "—" when turnover is 0.
- **Balance** = deposits − withdrawals + profit − open stake. It may be negative (a
  withdrawal is never blocked); a negative balance is shown with `−` in the loss colour.

**Currencies.**

- **Per bookmaker:** every figure is in the bookmaker's own currency, unconverted, so the
  balance can be compared with the user's account at that bookmaker. Transactions are
  always in the bookmaker's currency, which is why a bookmaker's currency is locked once it
  has bets or transactions.
- **A bookmaker with bets in another currency** (possible before the lock): its row is
  shown in the base currency with "Bets in more than one currency, shown in EUR". Bets
  are converted with their own rate, deposits and withdrawals with the bookmaker's
  current rate (each sum rounded once), and its balance counts as is in the total.
- **Total:** in the base currency. Bets, turnover, profit, ROI and open stake are
  converted per bet with the bet's own `exchange_rate`, exactly as Statistics does, so the
  total turnover, profit and ROI equal the Statistics page's all-time figures with free
  bets included.
- **Total balance** = the sum of each bookmaker's balance converted with the bookmaker's
  **current** `exchange_rate` (`Money::convert`, rounded once per bookmaker, negatives
  half away from zero), since it is money held now. So when rates have changed, the total
  balance need not equal total deposits − withdrawals + profit − open stake.
- Transactions store **no exchange rate**, and the base currency lock (bets only) is
  unchanged.

**Which bookmakers are listed:** active ones always (so the user can deposit), inactive
ones only when they have bets or transactions, marked "(inactive)"; active first, then by
name. New transactions can only be added at active bookmakers; existing ones stay
editable after a bookmaker is deactivated.

**Transactions:** type deposit or withdrawal; amount above 0 with the same limits as a
stake (`Money::isValidStake`, decimal comma accepted); date required and not after today
in the user's time zone (default today there); optional note up to 255 characters.

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
**manual payout**. `settled_at` is the game date at 23:59 in the user's time zone (6.2; the export has no
settlement time), but never before `placed_at`. On the owner's full export (588 orders)
this gives 331 won, 157 lost, 54 void, 30 half-won and 10 half-lost, with no order close
to two results; 211 bets get a manual payout, at most €1.14 from the calculated one.
An order whose reported loss is larger than its stake (payout below 0, which a bet
can't have) is imported as **lost** with payout 0, and its notes and the preview say
"Sportmarket reported a P&L of −150.02 on a stake of 150.00; recorded as lost."

**Rows that are skipped**, each listed with its reason in the preview:
- every row of the order failed;
- any non-failed row is not `settled` or `void` (the order isn't settled yet; import it
  again later);
- the order was imported before (`bets.external_ref` = `sportmarket:<Order Id>`, a new
  nullable column, unique per user).

## Export

Settings → Export downloads all of the user's bets as one CSV file,
`betledger-bets-YYYY-MM-DD.csv` (the date is today in UTC). The delete-account dialog
links to the same download ("Download your bets first"), since it is the only backup a
user has. Built in roadmap 4.2; 4.6 will import this exact format, so the columns are a
contract: add columns only at the end, never rename or reorder them.

**File.** UTF-8 with a byte order mark (so Excel on Windows shows æøå), comma separated,
RFC 4180 quoting: a field containing a comma, a double quote or a line break is wrapped in
double quotes and its double quotes are doubled; backslashes are not escape characters.
Rows end in CRLF; line breaks inside notes are kept as they are. The first row holds the
column names. A user without bets gets only that row.

**Rows.** One row per selection. The bet's columns are repeated on every row of the bet,
and `bet_id` groups the rows of an accumulator. Bets are ordered by `placed_at`, then
`bet_id`; a bet's rows by `selection_position`.

**Conventions.**
- *Empty cell* = no value (null).
- *Money:* amounts in major units with a decimal point and two decimals, no thousands
  separator, a leading `-` when negative (`12.50`, `-0.05`, `0.00`), in the bet's
  `currency`. Converted from minor units with integer arithmetic only.
- *Decimals* (odds, total odds, exchange rate, probability, bet value): plain decimal
  form with a point, **trailing zeros dropped** (`1.850` → `1.85`, `2.000` → `2`, rate
  `1.00000000` → `1`). Odds are decimal odds as stored, never converted to another
  format. Dropping the zeros makes SQLite and Postgres produce the same text.
- *Dates and times:* ISO 8601 in UTC with a `Z` offset, to the second
  (`2026-11-23T14:05:00Z`). Still UTC once per-user time zones (6.2) exist: the file is
  a backup, and UTC is what is stored.
- *Booleans:* `yes` or `no`.
- *Enums:* the stored values: `type` `single`/`accumulator`, `status` `open`/`settled`,
  `result` `won`/`lost`/`void`/`half_won`/`half_lost` (empty = pending).
- *Lists of names* (tags, teams): names joined with ` | ` (space, bar, space), sorted by
  byte value. List entries are exported by name, never by internal id. List names can't
  contain `|`, so splitting on ` | ` gives the names back.

**Columns**, in order:

| Column | From | Notes |
|---|---|---|
| `bet_id` | `bets.id` | groups an accumulator's rows; only meaningful within one export |
| `external_ref` | `bets.external_ref` | e.g. `sportmarket:2384015187` |
| `type` | `bets.type` | |
| `placed_at` | `bets.placed_at` | UTC |
| `bookmaker` | bookmaker name | |
| `currency` | `bets.currency` | ISO 4217; the currency of `stake`, `payout` and `profit` |
| `base_currency` | the user's base currency | what `exchange_rate` converts to; the same on every row |
| `exchange_rate` | `bets.exchange_rate` | 1 `currency` = this many `base_currency` |
| `stake` | `bets.stake` | money |
| `is_free_bet` | `bets.is_free_bet` | `yes`/`no` |
| `total_odds` | `bets.total_odds` | |
| `probability` | `bets.probability` | percentage (`55.5` = 55.5 %) |
| `bet_value` | `bets.bet_value` | percentage |
| `status` | `bets.status` | |
| `settled_at` | `bets.settled_at` | UTC; empty while open |
| `payout` | `bets.payout` | money; empty while open |
| `payout_is_manual` | `bets.payout_is_manual` | `yes`/`no` |
| `profit` | `bets.profit` | money; empty while open |
| `tipster` | tipster name | |
| `tags` | tag names | ` \| `-separated |
| `notes` | `bets.notes` | |
| `selection_position` | `selections.position` | 1, 2, … within the bet |
| `sport` | sport name | |
| `competition` | competition name | within the sport |
| `market` | market name | |
| `teams` | team names | ` \| `-separated, within the sport |
| `event_name` | `selections.event_name` | |
| `event_starts_at` | `selections.event_starts_at` | UTC |
| `pick` | `selections.pick` | |
| `odds` | `selections.odds` | |
| `closing_odds` | `selections.closing_odds` | |
| `result` | `selections.result` | empty = pending |

The rows are built by `App\Domain\Export\BetsCsv` and written by
`App\Http\Controllers\Settings\ExportBetsController`, which reads the bets through the
user's own relation in chunks with every relation eager loaded.

## Reset account

Settings → Profile has a "Reset Account" section above "Delete Account" (roadmap 6.19).
It permanently deletes all of the user's bets, with their selections, selection teams,
tag links and results, and keeps the account itself, bookmakers, list entries (sports,
teams, tags, …), bankroll deposits and withdrawals, settings and API tokens. Statistics
are then empty and bankroll balances only include deposits and withdrawals.

The button opens a dialog with a danger callout: how many bets will be deleted, what is
kept, and that it cannot be undone. Like the delete-account dialog, it links to the CSV
export ("Download your bets first") as the only backup and asks for the password. The
button is disabled while the user has no bets. Built by
`App\Livewire\Settings\ResetAccountForm`, which deletes through the user's own `bets()`
relation in one transaction; the cascading foreign keys remove the rest.

## API

A JSON API under `/api/v1` for scripts and apps that record bets on the user's account.

**The contract is `docs/api/openapi.yaml`** (OpenAPI 3.0): endpoints, fields, rules,
errors, examples, the versioning policy and a changelog. It is the only thing projects
that use the API rely on; they copy it from `main`. Anything a consumer needs to know
goes there, not here. `tests/Feature/Api/OpenApiContractTest.php` keeps it true: every
API route must be in it, its examples must be valid and work, and real requests and
responses are checked against it.

**Changing the API.** Change the code and `openapi.yaml` in the same PR, bump
`info.version` and add a changelog line. Within v1 only additive changes (new
endpoints, new optional request fields, new response fields); anything that breaks a
consumer needs `/api/v2`, agreed with the consumers first.

**Authentication.** Personal access tokens (Laravel Sanctum). The user creates a token
on **Settings → API tokens** (named after what uses it; the value is shown once, only a
hash is stored), sees when each token was last used, and revokes tokens there. A token
acts as its user and only ever reads or writes that user's data. Tokens don't expire
and have no separate abilities (see the open question). 60 requests per minute per
user.

**`POST /api/v1/bets`** records a new bet with the same fields and rules as the New bet
form, through the same `RecordBet` service: list entries are matched by name or added,
the currency and default rate come from the bookmaker, and the bet is always open.

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
| Bankroll | not mocked | Total card (balance, then bets, in open bets, turnover, ROI, profit tiles); one card per bookmaker with the same figures in its currency plus deposited/withdrawn, Add deposit / Add withdrawal, expandable transaction list with Edit and Delete |

Not mocked (build plainly in the same style): bookmakers management, lists management,
settings (base currency), bankroll.

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
  placed now. **Answered in 6.2:** users have a time zone (Settings → Currency and time
  zone), and Placed and Starts are entered and shown in it ("now" is the user's local
  now); they are stored as UTC instants.
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
  sport. "Open · n" counts the open bets that pass the filters (and, since 6.3, the
  search). Each tab has its own
  orders, the first being its default: Open — starts soonest (the next pending
  selection's start; bets without a start time last), newest, oldest placed; Settled —
  recently settled, newest, oldest placed; All — newest, oldest placed. Changing tab
  goes back to that tab's default order. 20 bets per page. A card shows the event's start
  for a single (or "placed …" when it has none) and "placed …" for an accumulator, in
  the user's time zone, comparing local days (6.2) ("today 18:30", "Sat 11:05" within six days either way, else
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
  one value (one bookmaker, one sport, …); dates are whole days in the user's time zone
  (6.2; from local midnight to local midnight, so 23 or 25 hours on daylight-saving
  days), both included. Stake and profit are each converted to the base currency with the
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
  **Profit over time** counts profit on the event date (`event_at`, since 6.1) as a local day in the user's zone (6.2), by day for ranges up to 31
  days, by week (from Monday) up to 182 days, else by month, with a point for every
  period, empty ones included, from the start of the range (or the first settled bet,
  for "All time") to its end (or the last settled bet).
- **Statistics page (assumed in 2.13).** `/statistics` opens on the last 90 days (as in
  the mockup). "30 days" and "90 days" are today (the user's today, 6.2) and the 29 or 89 days before, "This
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
  in leads. The month selector lists every month from the user's first bet (by placed
  or event date) to the current one, newest first; the month is kept in the URL, and an
  unknown or future month shows the current one. The profit card, its ROI badge and the
  strike rate, average odds and closing line tiles cover the settled bets **whose event
  date is in that month** (since 6.1; free bets included, in the base currency as on the statistics page; the
  current month up to today). Months and days are the user's, in their time zone (6.2),
  and so are the month selector's months. "Open stake" and the open bets preview always show the bets
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
  23:59 in the user's time zone (23:59 UTC until 6.2; bets imported before 6.2 keep it).
  Statistics count an imported bet on that same instant (its `event_at`, 6.1). In-play bets get an "In-play" tag. The market names for Sportmarket's codes
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
- **Sportmarket import page (assumed in 4.1b).** Settings → Import (`/settings/import`)
  offers the user's active bookmakers (the one named "Sportmarket", ignoring case, is
  pre-selected, else the first by name); without one it points to the Bookmakers page.
  The upload must be a `.csv` file of at most 10 MB. "Preview" lists errors first, then
  the bets to import (event, pick @ odds, placed, stake, result and profit, "payout as
  reported" when it is kept as a manual payout), then skipped orders, each with its
  reason. Changing the bookmaker or file leaves the preview. "Import n bets" reads the
  file again (so an order imported in the meantime is skipped, not duplicated), saves
  every bet in one transaction, and goes to the Settled tab of the bets list with
  "Imported n bets."; orders with errors are left out. A file with nothing to import
  (everything skipped or an error, e.g. a file in another currency than the bookmaker)
  has no Import button. Imported bets get the bookmaker's current rate, as in the bet
  form. There is no undo for a whole import: imported bets are deleted one by one like
  any other bet.
- **Losses larger than the stake (owner, in 4.1b).** Some orders in the owner's export
  report a P&L below −stake. Until the owner knows why, they are imported as lost with
  payout 0 (profit −stake) instead of being refused, and the reported P&L is kept in the
  notes. Owner: what causes these, and should the extra loss count in the profit?
- **From the November 2026 review (owner).** Questions behind the Phase 6 tasks in
  `docs/roadmap.md`; each task builds its stated default until these are answered.
  - *Which date statistics use (6.1).* **Resolved and built in 6.1.** Owner: statistics
    follow the event date, not the settlement date, and an accumulator counts on its last
    leg's start (confirmed). Built as `bets.event_at` (see the bets table): a bet without
    start times counts on its placed date; open bets are still filtered by placed date;
    the bankroll and the "Recently settled" sort keep the settlement date; the API and
    the CSV export are unchanged. Assumed in 6.1: an imported Sportmarket bet keeps its
    game date as its event date when it is edited, un-settled or settled again, because
    the export has no start time and `settled_at` changes when it is settled again; it
    only moves if the user gives a selection a start time (then the latest start
    counts). If an edit moves its placed date past the game date, the event date
    becomes the placed date and stays there.
  - *Existing times after a time zone setting (6.2).* Bets entered by hand so far were
    typed as local time but stored as UTC, so they are one or two hours off once a time
    zone is set. Shift them once, or leave them? Default: leave them (built in 6.2:
    nothing is shifted, so they now show one or two hours later than typed).
  - *Per-user time zone (assumed in 6.2).*
    - *Settings page.* The time zone sits on the base currency's page, renamed
      "Currency and time zone" (same route, `settings.currency`), with its own form and
      Save button; it stays editable after the base currency is locked by bets. The
      dropdown lists every zone PHP knows (~420), shown with spaces for underscores.
    - *Registration.* The zone comes from the browser
      (`Intl.DateTimeFormat().resolvedOptions().timeZone`) without a visible field. A
      missing or unknown zone becomes UTC without an error.
    - *Legacy zone names.* Validation uses `timezone:all`, not `all_with_bc`, so the list
      and the rule agree exactly and only current names are stored. A browser reporting a
      legacy name (e.g. `Europe/Kiev` for `Europe/Kyiv`) registers with UTC and the user
      picks the zone in Settings.
    - *Daylight saving.* A typed time that doesn't exist (the spring-forward gap, e.g.
      02:30 on 29 March in Copenhagen) moves forward by the gap (03:30). A typed time that
      happens twice (the autumn fold, 02:30 on 25 October) is the first of the two, still
      in summer time. So a bet placed in the second 02:30–03:00 shows as 02:30 and moves
      an hour earlier if its edit form is saved; the hour is rare enough to accept.
    - *Not converted.* The bankroll's `occurred_on`, statistics dates and profit-over-time
      periods are calendar dates and are not moved; they mean a day in the user's zone.
      The CSV export and its file name stay UTC; `settled_at` is still stamped as an
      instant when a result is set.
  - *Registration (6.12).* Is registration meant to be open to anyone? Email
    verification is currently off (`User` doesn't implement `MustVerifyEmail`).
    Default: turn verification on and keep registration open.
  - *Open Sportmarket orders (6.10).* Do you enter open Sportmarket bets by hand before
    they are settled and imported? If not, 6.10 can be dropped.
  - *Deleted bets (6.11).* Should a deleted bet be recoverable, and for how long?
    Default: 30 days.
  - *Exchange and lay bets.* Do you place lay bets? Sportmarket picks like "AGAINST
    Under 2.0" are stored as ordinary bets and the export's `Exchange Role` is ignored.
    Real lay bets (liability, commission) are not planned.
  - *Duplicate a bet and "Save and add another" (assumed in 6.4).*
    - *Duplicate.* "Duplicate bet" on the bet page opens the New bet form as a copy:
      every field, including the bet's own exchange rate (not the bookmaker's current
      one) and the selections' start times, except placed (now), results and closing
      odds. If the bookmaker has been deactivated since, the copy starts at the first
      active bookmaker and its rate. A copy is always open, never imported.
    - *Save and add another.* Keeps the bookmaker and its rate, the placed date **and
      time** (the task names only the date; keeping the time suits entering an
      evening's or a past day's bets), tipster, tags and each selection's sport and
      competition. An accumulator stays an accumulator with the same number of
      selections. Everything else is cleared, including market, teams, start, free bet,
      notes, probability and bet value. Only the New bet form has the button.
  - *Risk figures (assumed in 6.5).* Average stake is over **settled** cash bets, like
    turnover (open stakes are already the "Open" tile). Drawdowns are amounts in the
    base currency, not percentages of a peak (a peak can be 0 or negative). Drawdowns and
    streaks run over the bets in the chosen range and filters only, as everything else
    on the page does, so "All time" gives the lifetime figures. The panel has eight
    tiles: max and current drawdown, longest winning and losing streak, current streak,
    biggest win and loss (each with a "View bet" link and its event date) and average
    stake. While no bet in the range is settled it shows a line instead.
  - *Break-even strike rate (assumed in 6.6).* It covers exactly the bets the strike
    rate counts, so a lost free bet is in it at its odds, although a free bet (stake not
    returned) needs no strike rate to break even; exclude free bets with the toggle for
    the cash-bet figure. On the statistics page it replaces the strike-rate tile's
    "pushes excluded" line ("needs 51.3% to break even"; that line comes back while no
    bet is a win or a loss), is a "Break-even" column in the breakdown table and a
    "needs …" line under each odds range's strike rate. The median odds fit the
    average-odds tile as its second line ("median 2.05"), in place of "settled bets".
    Home's tiles are unchanged.
  - *Roadmap order.* 4.5 (merging list entries) is suggested ahead of 4.4 (cash out),
    since the import creates differently spelled entries; 4.7 (automatic exchange
    rates) and 4.8 (each-way and system bets) are suggested last, and 4.7 could be
    dropped because every bet already keeps its own editable rate.
- **API (assumed when the API was added).** The owner asked for an authenticated API that
  creates a bet with every field of the New bet form. Assumed:
  - *Tokens.* Personal access tokens from Settings, without expiry or per-token
    abilities: every token can do everything the API offers. Add abilities (e.g.
    read-only) when the API grows beyond creating bets.
  - *Results.* A bet created through the API is open, like on the form, which only
    offers results when editing. Settling through the API would be a separate endpoint.
  - *Time zone.* A date-time without an offset is taken as UTC. Per-user time zones (6.2)
    did not change this: consumers only see `docs/api/openapi.yaml` (v1.2.0), which
    promises UTC, and changing what an existing field means is a breaking change that
    needs a new `/api/vN` (`CLAUDE.md`). Sending an offset gives the exact instant.
    Owner: keep v1 on UTC, or add a `/api/v2` that reads offset-less times in the
    user's zone?
  - *Bookmakers by name.* The API accepts the bookmaker's name as well as its id, since
    the app shows no bookmaker ids. Bookmakers are not created through the API (they need
    a currency and rate).
- **Probability and bet value (first version, assumed when they were added).** The owner
  asked for an optional probability and a bet value calculated from it as
  `odds × probability`, kept simple for now. To decide in a later iteration:
  - *Void, half won and half lost.* One probability treats every outcome other than a
    win as a full loss, which understates the value of lines that can push or end half
    won/lost (Asian handicaps, quarter-goal totals). Using the settlement factors, the
    value would be `P(won) × odds + P(half_won) × (odds + 1) / 2 + P(void) × 1 +
    P(half_lost) × 0.5`. That needs a probability per outcome (or at least
    win / void / lose) instead of one number. Which outcomes should the form ask for, and
    only for markets that can have them?
  - *Accumulators.* The probability is for the whole bet (every leg winning) and the
    value uses the total odds. Should each selection get its own probability instead,
    with the bet's probability their product (and void legs dropping out)?
  - *Free bets.* The stake is not returned, so a free bet's expected return is
    `probability × (odds − 1)`, not `probability × odds`. Free bets use the same formula
    as cash bets for now.
  - *How value is shown.* Assumed: as the expected return in percent (105 % = 5 % edge),
    as asked. The other common form is the edge itself (`odds × probability − 1`,
    shown as +5 %), like CLV. Which reads better?
  - *Both sent through the API.* Assumed: sending both `probability` and `bet_value` is
    rejected (422) rather than silently ignoring one, since the value is calculated from
    the probability.
  - *Statistics.* Probability and bet value are stored and shown on the bet but not used
    in statistics yet (e.g. average bet value, or results by value band like the odds
    bands). Sportmarket imports have neither.
- **CSV export (assumed in 4.2).** See "Export". Assumed:
  - *Comma, BOM, CRLF.* Comma separated with a UTF-8 byte order mark, so Excel on Windows
    shows æøå. Excel set to a Danish locale expects semicolons and may put each row in one
    cell; use Data → From Text/CSV there. A semicolon option could be added later.
  - *UTC dates.* Every date and time is UTC with a `Z`, also after per-user time zones
    (6.2), so the file means the same everywhere and re-imports exactly.
  - *Trailing zeros dropped* from odds, rates and percentages (`1.850` → `1.85`), so the
    text is the same on SQLite and Postgres. The stored precision is never exceeded, so
    nothing is lost.
  - *`yes`/`no` booleans* rather than `1`/`0`, for people reading the file.
  - *`base_currency` column* added to the suggested columns: without it the exchange rate
    can't be interpreted when the file is imported into an account with another base
    currency.
  - *Names joined with ` | `.* Answered by the owner: list names (sports, competitions,
    teams, markets, tipsters, tags) can't contain `|` (`App\Domain\ListName`), so tags and
    teams split unambiguously on import (4.6). The bet form, the Lists page and the API
    refuse it (API contract 1.2.0, kept in v1 by agreement with the owner), and a
    Sportmarket order with `|` in a name is an error. Entries saved before this keep
    their names; renaming one on the Lists page requires removing the `|`.
  - *No email verification needed.* The page and the download are in the `auth` group,
    not `auth` + `verified`, because the delete-account dialog (also only `auth`) links to
    the download and the backup must always work.
  - *No CSV formula escaping.* Cells starting with `=`, `+`, `-` or `@` are not prefixed
    with `'`: the data is the user's own, negative amounts start with `-`, and escaping
    would change the values a 4.6 re-import reads.
  - *`bet_id`* is the database id, only meaningful within one export; a re-import should
    use it to group rows, not to match existing bets (`external_ref` does that for
    imported bets).
- **Bankroll (assumed in 4.3).** See "Bankroll"; the definitions, currency rules and
  scope were decided by the owner. Assumed while building it:
  - *Navigation.* Desktop sidebar after Bookmakers; on phones it is linked from the
    Settings page next to Bookmakers and Lists (as in 2.1), with the Settings tab
    highlighted. The Bookmakers page links to it.
  - *Cards on desktop too.* Bookmakers are cards in a two-column grid at ≥ 1024 px rather
    than a table, at the full content width (as in 3.5), so one layout serves both.
  - *A transaction keeps its bookmaker.* Editing changes type, amount, date and note; the
    bookmaker is shown read-only. To move one, delete it and add it at the other
    bookmaker. This keeps the currency lock simple (a transaction's currency is always its
    bookmaker's).
  - *Inactive bookmakers*: an inactive bookmaker's card has no "Add deposit/withdrawal"
    buttons; its transactions are still listed with Edit and Delete.
  - *Mixed-currency rows*: a row falls back to the base currency when any of its bets is in
    a currency other than the bookmaker's (also if all its bets are in one other currency).
    Its deposits and withdrawals are each converted with the bookmaker's current rate.
  - *Confirmation* after a change is an inline success callout on the page ("Deposit added
    at Bet365."), as on the bet pages, kept until the next change.
  - *Possible follow-ups (out of scope for 4.3):* transfers between bookmakers as their own
    type; bonuses or adjustments as their own type (for now a deposit with a note);
    transactions in the CSV export (that format is a contract) and in the API.
- **Bets list search and filters (assumed in 6.3).** The list's filters are the
  statistics page's conditions (one query class, `App\Domain\Bets\BetFilterQuery`), so a
  statistics row can link to exactly its bets (6.7). Assumed:
  - *Date range.* "A placed/settled date range" is the statistics rule: settled bets by
    their event date (`event_at`), open bets by the day they were placed, days in the
    user's time zone. There is no choice between placed and settled date; it could be
    added later. Dates entered the wrong way round are swapped, as on statistics.
  - *Which filters.* Bookmaker, sport, competition, market, tipster, tag, bet type and the
    date range, as the task lists. Team and "include free bets" (statistics has both)
    are not on the list yet; 6.7 can add them when its links need them.
  - *Layout.* Search field and a "Filters" button on the first row, the tabs on the
    second, then one sideways-scrolling row with the order, the active-filter chips and
    "Clear …". Bookmaker and sport moved from that row into the Filters sheet with the
    others (a flyout from the right on desktop, full width on phones). Filters apply as
    they are chosen; the sheet's button says how many bets match ("Show 12 bets").
  - *Search.* Matches the notes and any selection's event name, pick, team or competition
    containing the text, ignoring case; `%`, `_` and `\` are literal. At most 100
    characters are used. SQLite (local and tests) only folds ASCII letters, so "ø" and
    "Ø" only match each other on Postgres (production).
  - *"n bets".* Shown above the cards only while a search or filter is in use. The empty
    state says what is in use ("No open bets match this search and these filters").
    "Clear filters" in the sheet keeps the search; the button on the page clears both.
  - *Another user's id* in the URL still filters, so no bets match, and its chip reads
    "Tipster: unknown" (the name is never looked up outside the user's own lists).
- **Reset account (assumed in 6.19).** See "Reset account". The request was "all bets
  will be deleted", so only bets go: bookmakers, list entries and bankroll deposits and
  withdrawals are kept (a user who wants those gone too can delete them on their pages,
  or delete the account). The reset asks for the password, as deleting the account does,
  so a stray click can't wipe the ledger. A "delete everything but the login" option can
  be added later if the owner wants it.
