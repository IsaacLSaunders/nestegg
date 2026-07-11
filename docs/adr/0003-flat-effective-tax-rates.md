# Flat effective tax rates behind a pluggable TaxModel interface

v1 taxes use two per-portfolio effective rates: ordinary income (applied to Traditional 401k/IRA drawdowns) and capital gains (applied to the gains fraction of brokerage withdrawals, tracked via proportional cost basis). Roth, qualified 529, and cash drawdowns are untaxed. Annual dividend tax drag on brokerage accounts is deliberately ignored — approximate it by shaving the ROI input.

We rejected progressive federal bracket simulation for v1: it is a large sub-project (bracket data maintenance, filing status, deduction modeling, bracket inflation-indexing) that creates an illusion of precision over 30-year horizons. The engine takes taxes through a TaxModel interface so bracket-based models can be added without rework.
