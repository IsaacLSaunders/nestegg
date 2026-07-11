# Time is calendar-month anchored; monthly engine granularity

All engine time is an absolute calendar month, with projections starting at the current month. Any time input (contribution window, drawdown window) may be entered as an age or a calendar year — converted via the user's birth date — and graphs show calendar years with age as a secondary label. We rejected relative "year 0..N" time because saved portfolios would silently shift meaning as real time passes, and age-only time because calendar-bound goals (a child's college start) don't map to the owner's age.

Engine granularity is monthly. Weekly drawdown is an input option converted internally (weekly × 52 / 12); intra-month timing differences are noise at multi-decade horizons.
