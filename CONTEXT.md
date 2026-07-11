# Nestegg

A savings/investing planning app. Users model potential financial paths as portfolios of accounts, project account growth forward over time, and work backwards from spending goals to required contributions.

## Language

**User**:
A person with login credentials who owns portfolios. Carries planning attributes (birth date, assumed death age) used to bound projections.

**Portfolio**:
One potential financial path a user might take — a named scenario containing many accounts. Portfolios are compared against each other, not combined.
_Avoid_: Plan, scenario

**Account**:
A single modeled investment or savings vehicle inside a portfolio (401k, Roth/Traditional IRA, 529, brokerage, cash savings). Owns its own assumptions (ROI, contributions, drawdown schedule) and account type.

**Account Type**:
The tax classification of an account (e.g. Traditional 401k/IRA, Roth 401k/IRA, taxable brokerage, 529, cash). Determines how contributions and drawdowns are taxed.

**Projection**:
The computed year-by-year (internally month-by-month) evolution of an account's balance under its assumptions. Always computed in nominal dollars; may be *displayed* in today's dollars.

**Nominal dollars**:
Actual future dollar amounts, not adjusted for inflation. The engine's internal currency.

**Today's dollars**:
Nominal amounts deflated by the inflation rate back to present-day purchasing power. A display option, never stored.
_Avoid_: Real dollars (in UI copy; fine in code/docs)

**Contribution window**:
The period during which money flows into an account: its own start and end, independent of drawdown (gaps and overlap with drawdown are both allowed). Contributions are monthly, with an optional annual escalation rate.

**Drawdown**:
A recurring withdrawal from an account (weekly or monthly), starting at a configurable point in the projection. Entered in today's dollars and indexed to inflation by default (per-account switch to disable).
_Avoid_: Withdrawal, distribution

**Drawdown window**:
The period during which drawdown runs: a required start and an optional explicit end. Absent an explicit end, drawdown continues until depletion or death age, whichever comes first.

**Gross / Net drawdown entry**:
The per-account toggle for how a drawdown amount is specified: gross (pre-tax withdrawal, UI shows resulting after-tax spending) or net (after-tax spending target, engine grosses up the withdrawal to deliver it).

**Depletion date**:
The month an account's balance reaches zero during drawdown. A first-class output, surfaced prominently on graphs and summaries.

**Death age**:
The user's assumed age at death. Bounds projections and is the default sustainability horizon for goal seek.

**Cost basis**:
Cumulative contributions to a taxable brokerage account. Withdrawals are split proportionally: the basis fraction is untaxed, the gains fraction is taxed at the capital-gains rate.

**Goal seek**:
The reverse-mode calculation: given a target (drawdown amount or total account value at a date) and a drawdown window, solve for the required monthly/yearly contribution. Drawdown-amount goals must be sustainable until the explicit end date or death age.
_Avoid_: Backwards mode, reverse projection

**Contribution escalation**:
An optional annual percentage growth applied to an account's contribution amount (modeling rising income). Employer match is not modeled separately; it is folded into the contribution amount.
