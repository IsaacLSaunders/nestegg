# Backend-only projection engine

Live slider-driven graphs could tempt a duplicate TypeScript engine in the frontend, but tax, cost-basis, inflation-indexing, and goal-seek math implemented twice will drift. All projection and goal-seek computation lives in one unit-tested PHP engine; Vue sends the full assumption set to a stateless compute endpoint (debounced ~200ms) and renders the returned series. A 40-year monthly projection is a few thousand arithmetic ops — latency is imperceptible on localhost and acceptable deployed.

## Consequences

- The compute endpoint is stateless and persists nothing; saving is a separate explicit action.
- Frontend contains zero financial math — not even "trivial" conversions like gross↔net, which come back from the API.
