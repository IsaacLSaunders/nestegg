# Nestegg — v1 Specification

Agreed 2026-07-11 via grilling session. Vocabulary: see [CONTEXT.md](../CONTEXT.md). Load-bearing decisions: see [docs/adr/](./adr/).

## What it is

A savings/investing planning web app. A user models potential financial paths as **portfolios**, each containing many **accounts** (401k, Roth/Traditional IRA, 529, taxable brokerage, cash savings). Two core views:

1. **Projection view (forward):** adjust assumptions, watch an account's balance evolve on a graph.
2. **Goal-seek view (reverse):** mirrored UI — set the target and solve for the required contribution.

## Hierarchy & data

- **User** → many **Portfolios** → many **Accounts**.
- User attributes: email + password (Symfony form-login; multi-user schema from day one, no verification/reset flows yet), birth date, assumed **death age**.
- Portfolio attributes: name, effective ordinary-income tax rate, effective capital-gains tax rate. Portfolios are alternative paths — compared, never combined. **Duplication** of a portfolio is the scenario-forking mechanism.
- Account attributes: name, account type, starting balance (plus starting cost basis for brokerage), ROI (manual annual %), inflation rate, projection horizon (years, adjustable), contribution settings, drawdown settings.
- Persistence: Postgres 16. Explicit **Save** with dirty indicator; slider fiddling never writes until saved.

## Projection engine (v1 semantics)

- Lives **backend-only** in PHP; stateless compute endpoint; Vue debounces slider changes (~200ms) and renders returned series. No financial math in the frontend. (ADR-0001)
- **Nominal-dollar engine**; UI toggle deflates displays to today's dollars. (ADR-0002)
- **Calendar-month anchored**, monthly granularity; time inputs entered as age or year, interconverted via birth date. Graph x-axis: calendar years, age as secondary label. (ADR-0004)
- **Contribution window:** monthly amount with its own start/end, independent of drawdown (gaps and overlap allowed). Optional annual escalation % (default 0). Employer match folded into the amount.
- **Drawdown window:** weekly or monthly amount (weekly converted ×52/12 internally); required start, optional explicit end; otherwise runs until depletion or death age. Entered in today's dollars, inflation-indexed by default (per-account switch to disable).
- **Gross/net toggle:** drawdown entered pre-tax (shows resulting net spending) or post-tax (engine grosses up withdrawals).
- **Depletion date** is a first-class output, marked prominently on graph and summary.
- Balance floors at zero; no negative balances.

## Taxes (v1)

Flat effective rates per portfolio behind a pluggable TaxModel interface (ADR-0003):

| Account type | Contribution | Drawdown taxation |
|---|---|---|
| Traditional 401k / IRA | pre-tax | fully taxed at ordinary-income rate |
| Roth 401k / IRA | post-tax | untaxed |
| Taxable brokerage | post-tax | gains fraction taxed at capital-gains rate (proportional cost basis) |
| 529 | post-tax | untaxed (assumed qualified) |
| Cash savings | post-tax | untaxed |

No annual dividend tax drag in v1 (approximate by shaving ROI).

## Goal-seek view

Mirrored UI of the projection view. Inputs: same assumption set, plus a target — either a drawdown amount (weekly/monthly/yearly, gross or net) or a total account value — paired with the drawdown start. Output: required monthly/yearly contribution.

- Drawdown-amount goals must be sustainable until the explicit drawdown end if set, else death age (account depletes to ~zero at horizon).
- Total-value-at-date goals are fully determined (covers one-time expenses like a college fund).
- Solver runs server-side (iterative over the same engine).

## Views & graphs

- **Account view:** interactive assumption panel + projection graph for one account.
- **Portfolio view:** one line per account plus a bold portfolio-total line, spanning the longest account horizon; stacked-area toggle. Drawdown phases visually marked.
- Charting: ECharts.

## Stack & infrastructure

- Backend: PHP / Symfony (pure JSON API). Frontend: Vue 3 + Vite SPA. DB: Postgres 16.
- Docker Compose services: FrankenPHP (Symfony), Node (Vite dev server with HMR, proxying `/api`), Postgres (named volume).
- Makefile targets: `up`, `down`, `logs`, `shell`, `test`, `migrate`, `fixtures` (and friends).
- Hosted locally for now; public deploy later should require hardening auth, not schema rework. Future deploy shape: `vite build` output served by the FrankenPHP container — one artifact.
- Repo: public, `nestegg`, pushed to the user's GitHub account.

## Explicitly deferred (v2+)

- Allocation-derived ROI (stock/bond/cash aggressiveness sliders pre-filling the ROI field)
- One-time lump-sum deposit/withdrawal events
- Progressive tax brackets, state tax, filing status
- Annual dividend tax drag
- Explicit employer-match modeling
- Password reset / email verification
