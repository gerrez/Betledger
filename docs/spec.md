# BetLedger — product spec

> Status: **draft**. Only the fixed points are filled in. The design phase fills in the
> data model, screens and statistics, then this file is the source of truth for
> implementation work.

## Purpose

Let a bettor keep an accurate personal ledger of the bets they place with bookmakers:
record each bet by hand, settle it once the outcome is known, and see statistics that show
how they are actually performing.

## Fixed decisions

- Web app, Laravel + Livewire (see `CLAUDE.md` for the stack).
- Single user to begin with, but full multi-user support with accounts from the start:
  registration, login, password reset, and strict per-user data isolation.
- Bets are entered manually. No bookmaker integrations or odds feeds (for now).
- Money is stored as integer minor units with a currency code; odds as exact decimals.

## Core flows (to be detailed in design)

1. **Record a bet** — when, where (bookmaker), what (event/selection/market), stake, odds.
2. **View bets** — open (pending) bets and settled history, with filtering.
3. **Settle a bet** — mark the outcome; return and profit are calculated. Can be corrected.
4. **Statistics** — profit/loss, ROI, strike rate, average odds, broken down over time and
   by dimensions such as bookmaker, sport and bet type.

## To decide in the design phase

- Bet types: singles only, or also accumulators/parlays, system bets, each-way?
- Settlement outcomes: won, lost, void/refunded, push, half-won/half-lost (Asian lines),
  cash-out with custom amount?
- Odds formats shown to the user: decimal only, or also fractional/American?
- One currency per user, or per bet/bookmaker with conversion?
- Which classification fields (sport, league, market, tipster, tags) and whether they're
  free text or user-managed lists.
- Bankroll tracking (deposits/withdrawals per bookmaker) — in scope or later?
- Free bets/bonuses: does the stake count toward turnover and P/L?
- Statistics set, time ranges, and charts.
- Import/export (CSV).

## Open questions

<!-- Claude sessions add assumptions they had to make here. -->
