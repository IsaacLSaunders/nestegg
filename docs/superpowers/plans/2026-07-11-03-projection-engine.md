# Nestegg Plan 3/4: Projection Engine and Goal Seek Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A unit-tested pure-PHP projection engine (nominal-dollar monthly simulation with contributions, escalation, tax-aware drawdown, cost basis, depletion detection) exposed through stateless `POST /api/projection`, plus a bisection goal-seek solver exposed through stateless `POST /api/goal-seek`.

**Architecture:** `App\Projection` namespace holds pure value objects and math — zero Doctrine/HTTP dependencies (ADR-0001: all math server-side; ADR-0002: engine computes nominal dollars, today's-dollars is a display transform; ADR-0004: month granularity). Controllers map request DTOs (calendar dates) to engine inputs (month indices), run the engine, and serialize results with date strings and real-dollar values. Taxes go through a `TaxModel` interface with a flat-rate implementation (ADR-0003). Goal seek wraps the projector in a monotone bisection.

**Tech Stack:** PHP 8.5, PHPUnit (pure unit tests for the engine — no kernel, no DB), Symfony MapRequestPayload for the endpoints.

## Global Constraints

- Engine classes live under `backend/src/Projection/` (`App\Projection\...`) and MUST NOT import Doctrine, HttpFoundation, or entity classes. Value objects are `final readonly`.
- All rates are decimal fractions. Monthly compounding: `monthlyRate = (1+annual)^(1/12) - 1` — geometric, not annual/12.
- **Month-loop order of operations (fixed contract, documented in code):** (1) add contribution (basis += contribution for brokerage), (2) apply growth `balance *= 1+monthlyReturn`, (3) withdraw drawdown. Tests depend on this order.
- Contribution escalation compounds annually: in month `m` (0-based from the contribution window start), the contribution is `monthlyAmount * (1+escalation)^floor(monthsSinceContributionStart/12)`.
- Drawdown amount is entered in **today's dollars**; if `inflationIndexed`, the nominal target in month `m` is `amount * (1+monthlyInflation)^m` (indexing from projection start, not drawdown start). Weekly amounts are converted by the **controller** (×52/12) before reaching the engine — the engine only knows monthly.
- Drawdown is active in month m iff `m >= startMonthIndex` AND (`endMonthIndex` null or `m <= endMonthIndex`) AND (`deathMonthIndex` null or `m <= deathMonthIndex`) — **including when balance is 0** (the withdrawal is then 0 and depletion is recorded; this is what lets goal-seek reject the do-nothing solution). Division guards: gainsFraction and the basis reduction each require `balance > 0`.
- Brokerage: `gainsFraction = max(0, (balance-basis)/balance)` computed BEFORE withdrawal; tax = `gross * gainsFraction * capitalGainsRate`; basis reduction `basis -= gross * basis/balance` before `balance -= gross`. Traditional 401k/IRA: tax = `gross * ordinaryIncomeTaxRate`. Roth/529/cash: tax = 0.
- Net entry mode: gross is solved from the net target (`gross = net/(1-rate)` traditional, `gross = net/(1 - gainsFraction*capRate)` brokerage, `gross = net` untaxed). If balance can't cover the desired gross, withdraw everything that's left (partial month) and record depletion.
- Depletion month = first month where the post-withdrawal balance ≤ 0.005 (engine floors balance at 0.0 thereafter; contributions may still resume growth — depletion month stays the FIRST such month).
- Float comparisons in tests use `assertEqualsWithDelta` with delta 0.01 unless exact by construction.
- API: stateless — these endpoints persist nothing and read nothing from the DB; they are under the authenticated `/api` firewall. Responses use `apiJson()`. Validation errors 422.
- Commit trailer as in `git log`. Anything DB/kernel-bound runs in-container (`make test`); pure engine unit tests may also be run on the host for speed but the committed evidence is `make test`.

---

### Task 1: Engine value objects and accumulation-phase projector

**Files:**
- Create: `backend/src/Projection/ContributionSchedule.php`
- Create: `backend/src/Projection/DrawdownSchedule.php`
- Create: `backend/src/Projection/ProjectionAssumptions.php`
- Create: `backend/src/Projection/MonthState.php`
- Create: `backend/src/Projection/ProjectionSummary.php`
- Create: `backend/src/Projection/ProjectionResult.php`
- Create: `backend/src/Projection/Projector.php` (accumulation only — drawdown wired in Task 2)
- Test: `backend/tests/Projection/ProjectorAccumulationTest.php`

**Interfaces:**
- Consumes: `App\Enum\AccountType`, `App\Enum\DrawdownEntryMode` (existing).
- Produces (signatures Tasks 2-5 build on):
  - `new ContributionSchedule(float $monthlyAmount, float $annualEscalationRate = 0.0, ?int $startMonthIndex = null, ?int $endMonthIndex = null)`; `amountForMonth(int $m): float`.
  - `new DrawdownSchedule(float $monthlyAmountToday, DrawdownEntryMode $entryMode, int $startMonthIndex, ?int $endMonthIndex = null, bool $inflationIndexed = true)`.
  - `new ProjectionAssumptions(int $horizonMonths, AccountType $accountType, float $startingBalance, ?float $startingBasis, float $annualReturnRate, float $annualInflationRate, float $ordinaryIncomeTaxRate, float $capitalGainsTaxRate, ContributionSchedule $contribution, ?DrawdownSchedule $drawdown, ?int $deathMonthIndex)`.
  - `Projector::project(ProjectionAssumptions $a): ProjectionResult`; `ProjectionResult { /** @var list<MonthState> */ public array $months; public ProjectionSummary $summary; }`.
  - `MonthState { public int $index; public float $balance; public float $basis; public float $contribution; public float $grossWithdrawal; public float $netWithdrawal; public float $taxPaid; }`.
  - `ProjectionSummary { public float $endingBalance; public ?int $depletionMonthIndex; public float $totalContributions; public float $totalGrossWithdrawals; public float $totalNetWithdrawals; public float $totalTaxPaid; }`.

- [ ] **Step 1: Write the failing tests**

Create `backend/tests/Projection/ProjectorAccumulationTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Projection;

use App\Enum\AccountType;
use App\Projection\ContributionSchedule;
use App\Projection\ProjectionAssumptions;
use App\Projection\Projector;
use PHPUnit\Framework\TestCase;

final class ProjectorAccumulationTest extends TestCase
{
    private function assumptions(
        float $startingBalance = 0.0,
        float $annualReturnRate = 0.0,
        ContributionSchedule $contribution = new ContributionSchedule(0.0),
        int $horizonMonths = 12,
    ): ProjectionAssumptions {
        return new ProjectionAssumptions(
            horizonMonths: $horizonMonths,
            accountType: AccountType::Roth401k,
            startingBalance: $startingBalance,
            startingBasis: null,
            annualReturnRate: $annualReturnRate,
            annualInflationRate: 0.0,
            ordinaryIncomeTaxRate: 0.0,
            capitalGainsTaxRate: 0.0,
            contribution: $contribution,
            drawdown: null,
            deathMonthIndex: null,
        );
    }

    public function testZeroEverythingStaysZero(): void
    {
        $result = (new Projector())->project($this->assumptions());
        self::assertCount(12, $result->months);
        self::assertSame(0.0, $result->summary->endingBalance);
        self::assertNull($result->summary->depletionMonthIndex);
    }

    public function testFlatContributionsNoGrowth(): void
    {
        $result = (new Projector())->project($this->assumptions(
            contribution: new ContributionSchedule(100.0),
        ));
        self::assertEqualsWithDelta(1200.0, $result->summary->endingBalance, 0.01);
        self::assertEqualsWithDelta(1200.0, $result->summary->totalContributions, 0.01);
        self::assertEqualsWithDelta(100.0, $result->months[0]->balance, 0.01);
        self::assertEqualsWithDelta(100.0, $result->months[0]->contribution, 0.01);
    }

    public function testGrowthCompoundsMonthlyToAnnualRate(): void
    {
        // 10000 at 7% annual, no contributions: after 12 months exactly 10000*1.07.
        $result = (new Projector())->project($this->assumptions(
            startingBalance: 10000.0,
            annualReturnRate: 0.07,
        ));
        self::assertEqualsWithDelta(10700.0, $result->summary->endingBalance, 0.01);
    }

    public function testContributionThenGrowthOrderWithinMonth(): void
    {
        // One month, 12% annual (monthlyRate = 1.12^(1/12)-1), contribute 1000 at start:
        // balance = (0 + 1000) * 1.12^(1/12) = 1009.4888...
        $result = (new Projector())->project($this->assumptions(
            annualReturnRate: 0.12,
            contribution: new ContributionSchedule(1000.0),
            horizonMonths: 1,
        ));
        self::assertEqualsWithDelta(1000.0 * (1.12 ** (1 / 12)), $result->months[0]->balance, 0.01);
    }

    public function testContributionWindow(): void
    {
        // Contribute only months 3..5 inclusive (100/mo), no growth: total 300.
        $result = (new Projector())->project($this->assumptions(
            contribution: new ContributionSchedule(100.0, 0.0, 3, 5),
        ));
        self::assertEqualsWithDelta(300.0, $result->summary->endingBalance, 0.01);
        self::assertSame(0.0, $result->months[2]->contribution);
        self::assertEqualsWithDelta(100.0, $result->months[3]->contribution, 0.01);
        self::assertEqualsWithDelta(100.0, $result->months[5]->contribution, 0.01);
        self::assertSame(0.0, $result->months[6]->contribution);
    }

    public function testEscalationCompoundsAnnuallyFromWindowStart(): void
    {
        // 100/mo, 10% escalation, 25-month horizon, window starts month 0:
        // months 0-11 → 100, months 12-23 → 110, month 24 → 121.
        $result = (new Projector())->project($this->assumptions(
            contribution: new ContributionSchedule(100.0, 0.10),
            horizonMonths: 25,
        ));
        self::assertEqualsWithDelta(100.0, $result->months[11]->contribution, 0.001);
        self::assertEqualsWithDelta(110.0, $result->months[12]->contribution, 0.001);
        self::assertEqualsWithDelta(121.0, $result->months[24]->contribution, 0.001);
    }

    public function testBrokerageBasisTracksContributions(): void
    {
        $a = new ProjectionAssumptions(
            horizonMonths: 2,
            accountType: AccountType::Brokerage,
            startingBalance: 1000.0,
            startingBasis: 800.0,
            annualReturnRate: 0.0,
            annualInflationRate: 0.0,
            ordinaryIncomeTaxRate: 0.0,
            capitalGainsTaxRate: 0.0,
            contribution: new ContributionSchedule(50.0),
            drawdown: null,
            deathMonthIndex: null,
        );
        $result = (new Projector())->project($a);
        self::assertEqualsWithDelta(900.0, $result->months[1]->basis, 0.01);
        self::assertEqualsWithDelta(1100.0, $result->months[1]->balance, 0.01);
    }

    public function testNullStartingBasisDefaultsToStartingBalance(): void
    {
        $a = new ProjectionAssumptions(
            horizonMonths: 1,
            accountType: AccountType::Brokerage,
            startingBalance: 1000.0,
            startingBasis: null,
            annualReturnRate: 0.0,
            annualInflationRate: 0.0,
            ordinaryIncomeTaxRate: 0.0,
            capitalGainsTaxRate: 0.0,
            contribution: new ContributionSchedule(0.0),
            drawdown: null,
            deathMonthIndex: null,
        );
        $result = (new Projector())->project($a);
        self::assertEqualsWithDelta(1000.0, $result->months[0]->basis, 0.01);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && php bin/phpunit tests/Projection` (host OK for pure tests) — expected: FAIL, classes not found.

- [ ] **Step 3: Implement the value objects**

Create `backend/src/Projection/ContributionSchedule.php`:

```php
<?php

declare(strict_types=1);

namespace App\Projection;

final readonly class ContributionSchedule
{
    public function __construct(
        public float $monthlyAmount,
        public float $annualEscalationRate = 0.0,
        public ?int $startMonthIndex = null,
        public ?int $endMonthIndex = null,
    ) {
    }

    public function amountForMonth(int $m): float
    {
        $start = $this->startMonthIndex ?? 0;
        if ($m < $start || (null !== $this->endMonthIndex && $m > $this->endMonthIndex)) {
            return 0.0;
        }

        $yearsIn = intdiv($m - $start, 12);

        return $this->monthlyAmount * (1 + $this->annualEscalationRate) ** $yearsIn;
    }
}
```

Create `backend/src/Projection/DrawdownSchedule.php`:

```php
<?php

declare(strict_types=1);

namespace App\Projection;

use App\Enum\DrawdownEntryMode;

final readonly class DrawdownSchedule
{
    public function __construct(
        public float $monthlyAmountToday,
        public DrawdownEntryMode $entryMode,
        public int $startMonthIndex,
        public ?int $endMonthIndex = null,
        public bool $inflationIndexed = true,
    ) {
    }

    public function isActive(int $m, ?int $deathMonthIndex): bool
    {
        if ($m < $this->startMonthIndex) {
            return false;
        }
        if (null !== $this->endMonthIndex && $m > $this->endMonthIndex) {
            return false;
        }
        if (null !== $deathMonthIndex && $m > $deathMonthIndex) {
            return false;
        }

        return true;
    }
}
```

Create `backend/src/Projection/ProjectionAssumptions.php`:

```php
<?php

declare(strict_types=1);

namespace App\Projection;

use App\Enum\AccountType;

final readonly class ProjectionAssumptions
{
    public function __construct(
        public int $horizonMonths,
        public AccountType $accountType,
        public float $startingBalance,
        public ?float $startingBasis,
        public float $annualReturnRate,
        public float $annualInflationRate,
        public float $ordinaryIncomeTaxRate,
        public float $capitalGainsTaxRate,
        public ContributionSchedule $contribution,
        public ?DrawdownSchedule $drawdown,
        public ?int $deathMonthIndex,
    ) {
    }
}
```

Create `backend/src/Projection/MonthState.php`:

```php
<?php

declare(strict_types=1);

namespace App\Projection;

final readonly class MonthState
{
    public function __construct(
        public int $index,
        public float $balance,
        public float $basis,
        public float $contribution,
        public float $grossWithdrawal,
        public float $netWithdrawal,
        public float $taxPaid,
    ) {
    }
}
```

Create `backend/src/Projection/ProjectionSummary.php`:

```php
<?php

declare(strict_types=1);

namespace App\Projection;

final readonly class ProjectionSummary
{
    public function __construct(
        public float $endingBalance,
        public ?int $depletionMonthIndex,
        public float $totalContributions,
        public float $totalGrossWithdrawals,
        public float $totalNetWithdrawals,
        public float $totalTaxPaid,
    ) {
    }
}
```

Create `backend/src/Projection/ProjectionResult.php`:

```php
<?php

declare(strict_types=1);

namespace App\Projection;

final readonly class ProjectionResult
{
    /** @param list<MonthState> $months */
    public function __construct(
        public array $months,
        public ProjectionSummary $summary,
    ) {
    }
}
```

- [ ] **Step 4: Implement the accumulation-phase projector**

Create `backend/src/Projection/Projector.php`:

```php
<?php

declare(strict_types=1);

namespace App\Projection;

use App\Enum\AccountType;

/**
 * Nominal-dollar monthly simulation (ADR-0002, ADR-0004).
 *
 * Month contract: (1) contribution, (2) growth, (3) drawdown.
 */
final class Projector
{
    public function project(ProjectionAssumptions $a): ProjectionResult
    {
        $monthlyReturn = (1 + $a->annualReturnRate) ** (1 / 12) - 1;

        $balance = $a->startingBalance;
        $isBrokerage = AccountType::Brokerage === $a->accountType;
        $basis = $isBrokerage ? ($a->startingBasis ?? $a->startingBalance) : 0.0;

        $months = [];
        $totalContributions = 0.0;
        $depletionMonthIndex = null;

        for ($m = 0; $m < $a->horizonMonths; ++$m) {
            $contribution = $a->contribution->amountForMonth($m);
            $balance += $contribution;
            if ($isBrokerage) {
                $basis += $contribution;
            }
            $totalContributions += $contribution;

            $balance *= 1 + $monthlyReturn;

            // Drawdown wired in Task 2.
            $months[] = new MonthState(
                index: $m,
                balance: $balance,
                basis: $basis,
                contribution: $contribution,
                grossWithdrawal: 0.0,
                netWithdrawal: 0.0,
                taxPaid: 0.0,
            );
        }

        return new ProjectionResult($months, new ProjectionSummary(
            endingBalance: $balance,
            depletionMonthIndex: $depletionMonthIndex,
            totalContributions: $totalContributions,
            totalGrossWithdrawals: 0.0,
            totalNetWithdrawals: 0.0,
            totalTaxPaid: 0.0,
        ));
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd backend && php bin/phpunit tests/Projection`, then the full `make test` from the repo root.
Expected: all green, pristine.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Projection backend/tests/Projection
git commit -m "feat: projection engine value objects and accumulation-phase projector"
```

---

### Task 2: TaxModel and drawdown simulation

**Files:**
- Create: `backend/src/Projection/TaxModel.php` (interface)
- Create: `backend/src/Projection/FlatRateTaxModel.php`
- Modify: `backend/src/Projection/Projector.php` (wire drawdown into the month loop)
- Test: `backend/tests/Projection/FlatRateTaxModelTest.php`
- Test: `backend/tests/Projection/ProjectorDrawdownTest.php`

**Interfaces:**
- Consumes: Task 1 classes.
- Produces:
  - `interface TaxModel { public function netFromGross(AccountType $type, float $gross, float $gainsFraction): float; public function grossFromNet(AccountType $type, float $net, float $gainsFraction): float; }`
  - `new FlatRateTaxModel(float $ordinaryIncomeTaxRate, float $capitalGainsTaxRate)`.
  - `Projector::__construct()` stays parameterless; it builds `new FlatRateTaxModel($a->ordinaryIncomeTaxRate, $a->capitalGainsTaxRate)` per projection (rates travel in the assumptions; swapping models is a future constructor seam).
  - Drawdown fields of `MonthState`/`ProjectionSummary` now populated; depletion per Global Constraints.

- [ ] **Step 1: Write the failing tax-model tests**

Create `backend/tests/Projection/FlatRateTaxModelTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Projection;

use App\Enum\AccountType;
use App\Projection\FlatRateTaxModel;
use PHPUnit\Framework\TestCase;

final class FlatRateTaxModelTest extends TestCase
{
    private FlatRateTaxModel $model;

    protected function setUp(): void
    {
        $this->model = new FlatRateTaxModel(0.25, 0.15);
    }

    public function testTraditionalTaxedAtOrdinaryRate(): void
    {
        self::assertEqualsWithDelta(750.0, $this->model->netFromGross(AccountType::Traditional401k, 1000.0, 0.0), 0.001);
        self::assertEqualsWithDelta(1000.0, $this->model->grossFromNet(AccountType::TraditionalIra, 750.0, 0.0), 0.001);
    }

    public function testRothAnd529AndCashUntaxed(): void
    {
        foreach ([AccountType::Roth401k, AccountType::RothIra, AccountType::Plan529, AccountType::Cash] as $type) {
            self::assertSame(1000.0, $this->model->netFromGross($type, 1000.0, 0.5));
            self::assertSame(1000.0, $this->model->grossFromNet($type, 1000.0, 0.5));
        }
    }

    public function testBrokerageTaxesOnlyGainsFraction(): void
    {
        // 40% of the withdrawal is gains: tax = 1000 * 0.4 * 0.15 = 60.
        self::assertEqualsWithDelta(940.0, $this->model->netFromGross(AccountType::Brokerage, 1000.0, 0.4), 0.001);
        // Inverse: gross = 940 / (1 - 0.4*0.15) = 1000.
        self::assertEqualsWithDelta(1000.0, $this->model->grossFromNet(AccountType::Brokerage, 940.0, 0.4), 0.001);
    }
}
```

- [ ] **Step 2: Write the failing drawdown tests**

Create `backend/tests/Projection/ProjectorDrawdownTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Projection;

use App\Enum\AccountType;
use App\Enum\DrawdownEntryMode;
use App\Projection\ContributionSchedule;
use App\Projection\DrawdownSchedule;
use App\Projection\ProjectionAssumptions;
use App\Projection\Projector;
use PHPUnit\Framework\TestCase;

final class ProjectorDrawdownTest extends TestCase
{
    private function assumptions(
        AccountType $type,
        float $startingBalance,
        DrawdownSchedule $drawdown,
        float $ordinaryRate = 0.25,
        float $capGainsRate = 0.15,
        float $annualReturnRate = 0.0,
        float $annualInflationRate = 0.0,
        ?float $startingBasis = null,
        ?int $deathMonthIndex = null,
        int $horizonMonths = 12,
    ): ProjectionAssumptions {
        return new ProjectionAssumptions(
            horizonMonths: $horizonMonths,
            accountType: $type,
            startingBalance: $startingBalance,
            startingBasis: $startingBasis,
            annualReturnRate: $annualReturnRate,
            annualInflationRate: $annualInflationRate,
            ordinaryIncomeTaxRate: $ordinaryRate,
            capitalGainsTaxRate: $capGainsRate,
            contribution: new ContributionSchedule(0.0),
            drawdown: $drawdown,
            deathMonthIndex: $deathMonthIndex,
        );
    }

    public function testGrossDrawdownFromTraditional(): void
    {
        // 12000 balance, withdraw 1000 gross monthly, 25% ordinary: net 750/mo, depletes exactly at month 11.
        $result = (new Projector())->project($this->assumptions(
            AccountType::Traditional401k,
            12000.0,
            new DrawdownSchedule(1000.0, DrawdownEntryMode::Gross, 0),
        ));
        self::assertEqualsWithDelta(1000.0, $result->months[0]->grossWithdrawal, 0.01);
        self::assertEqualsWithDelta(750.0, $result->months[0]->netWithdrawal, 0.01);
        self::assertEqualsWithDelta(250.0, $result->months[0]->taxPaid, 0.01);
        self::assertSame(11, $result->summary->depletionMonthIndex);
        self::assertEqualsWithDelta(0.0, $result->summary->endingBalance, 0.01);
        self::assertEqualsWithDelta(12000.0, $result->summary->totalGrossWithdrawals, 0.01);
        self::assertEqualsWithDelta(9000.0, $result->summary->totalNetWithdrawals, 0.01);
        self::assertEqualsWithDelta(3000.0, $result->summary->totalTaxPaid, 0.01);
    }

    public function testNetEntryModeGrossesUp(): void
    {
        // Want 750 net from traditional at 25%: gross withdrawal must be 1000.
        $result = (new Projector())->project($this->assumptions(
            AccountType::Traditional401k,
            12000.0,
            new DrawdownSchedule(750.0, DrawdownEntryMode::Net, 0),
        ));
        self::assertEqualsWithDelta(1000.0, $result->months[0]->grossWithdrawal, 0.01);
        self::assertEqualsWithDelta(750.0, $result->months[0]->netWithdrawal, 0.01);
    }

    public function testRothDrawdownUntaxed(): void
    {
        $result = (new Projector())->project($this->assumptions(
            AccountType::RothIra,
            1200.0,
            new DrawdownSchedule(100.0, DrawdownEntryMode::Net, 0),
        ));
        self::assertEqualsWithDelta(100.0, $result->months[0]->grossWithdrawal, 0.01);
        self::assertSame(0.0, $result->months[0]->taxPaid);
    }

    public function testBrokerageBasisProportionalReduction(): void
    {
        // Balance 1000, basis 600 → gainsFraction 0.4. Withdraw 100 gross:
        // tax = 100*0.4*0.15 = 6, net 94; basis -= 100*600/1000 = 60 → 540; balance 900.
        $result = (new Projector())->project($this->assumptions(
            AccountType::Brokerage,
            1000.0,
            new DrawdownSchedule(100.0, DrawdownEntryMode::Gross, 0),
            startingBasis: 600.0,
            horizonMonths: 1,
        ));
        self::assertEqualsWithDelta(94.0, $result->months[0]->netWithdrawal, 0.01);
        self::assertEqualsWithDelta(6.0, $result->months[0]->taxPaid, 0.01);
        self::assertEqualsWithDelta(540.0, $result->months[0]->basis, 0.01);
        self::assertEqualsWithDelta(900.0, $result->months[0]->balance, 0.01);
    }

    public function testBrokerageAllBasisNoTax(): void
    {
        // basis == balance → gainsFraction 0 → no tax.
        $result = (new Projector())->project($this->assumptions(
            AccountType::Brokerage,
            1000.0,
            new DrawdownSchedule(100.0, DrawdownEntryMode::Gross, 0),
            startingBasis: 1000.0,
            horizonMonths: 1,
        ));
        self::assertSame(0.0, $result->months[0]->taxPaid);
    }

    public function testInflationIndexedDrawdownGrows(): void
    {
        // 3% inflation, indexed: month 12 target = 100 * (1.03)^(12/12) = 103.
        $result = (new Projector())->project($this->assumptions(
            AccountType::RothIra,
            100000.0,
            new DrawdownSchedule(100.0, DrawdownEntryMode::Gross, 0),
            annualInflationRate: 0.03,
            horizonMonths: 13,
        ));
        self::assertEqualsWithDelta(100.0, $result->months[0]->grossWithdrawal, 0.01);
        self::assertEqualsWithDelta(103.0, $result->months[12]->grossWithdrawal, 0.01);
    }

    public function testUnindexedDrawdownStaysFlat(): void
    {
        $result = (new Projector())->project($this->assumptions(
            AccountType::RothIra,
            100000.0,
            new DrawdownSchedule(100.0, DrawdownEntryMode::Gross, 0, null, false),
            annualInflationRate: 0.03,
            horizonMonths: 13,
        ));
        self::assertEqualsWithDelta(100.0, $result->months[12]->grossWithdrawal, 0.01);
    }

    public function testDrawdownWindowAndDeathBound(): void
    {
        // Start month 2, explicit end month 4; death at month 3 cuts it earlier.
        $result = (new Projector())->project($this->assumptions(
            AccountType::RothIra,
            10000.0,
            new DrawdownSchedule(100.0, DrawdownEntryMode::Gross, 2, 4),
            deathMonthIndex: 3,
        ));
        self::assertSame(0.0, $result->months[1]->grossWithdrawal);
        self::assertEqualsWithDelta(100.0, $result->months[2]->grossWithdrawal, 0.01);
        self::assertEqualsWithDelta(100.0, $result->months[3]->grossWithdrawal, 0.01);
        self::assertSame(0.0, $result->months[4]->grossWithdrawal);
    }

    public function testPartialFinalWithdrawalMarksDepletion(): void
    {
        // 250 balance, 100/mo gross: months 0,1 full; month 2 partial (50); depletion at month 2.
        $result = (new Projector())->project($this->assumptions(
            AccountType::RothIra,
            250.0,
            new DrawdownSchedule(100.0, DrawdownEntryMode::Gross, 0),
        ));
        self::assertEqualsWithDelta(50.0, $result->months[2]->grossWithdrawal, 0.01);
        self::assertSame(2, $result->summary->depletionMonthIndex);
        self::assertSame(0.0, $result->months[3]->grossWithdrawal);
        self::assertEqualsWithDelta(0.0, $result->summary->endingBalance, 0.01);
    }
}
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `cd backend && php bin/phpunit tests/Projection` — FAIL (TaxModel missing; drawdown fields all zero).

- [ ] **Step 4: Implement TaxModel and FlatRateTaxModel**

Create `backend/src/Projection/TaxModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Projection;

use App\Enum\AccountType;

interface TaxModel
{
    public function netFromGross(AccountType $type, float $gross, float $gainsFraction): float;

    public function grossFromNet(AccountType $type, float $net, float $gainsFraction): float;
}
```

Create `backend/src/Projection/FlatRateTaxModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Projection;

use App\Enum\AccountType;

final readonly class FlatRateTaxModel implements TaxModel
{
    public function __construct(
        private float $ordinaryIncomeTaxRate,
        private float $capitalGainsTaxRate,
    ) {
    }

    public function netFromGross(AccountType $type, float $gross, float $gainsFraction): float
    {
        return $gross * (1 - $this->effectiveRate($type, $gainsFraction));
    }

    public function grossFromNet(AccountType $type, float $net, float $gainsFraction): float
    {
        $rate = $this->effectiveRate($type, $gainsFraction);
        if ($rate >= 1.0) {
            return \INF;
        }

        return $net / (1 - $rate);
    }

    private function effectiveRate(AccountType $type, float $gainsFraction): float
    {
        return match ($type) {
            AccountType::Traditional401k, AccountType::TraditionalIra => $this->ordinaryIncomeTaxRate,
            AccountType::Brokerage => $gainsFraction * $this->capitalGainsTaxRate,
            AccountType::Roth401k, AccountType::RothIra, AccountType::Plan529, AccountType::Cash => 0.0,
        };
    }
}
```

- [ ] **Step 5: Wire drawdown into the Projector month loop**

In `backend/src/Projection/Projector.php`, replace the loop body after the growth line (and the "Drawdown wired in Task 2" comment) with:

```php
            $grossWithdrawal = 0.0;
            $netWithdrawal = 0.0;
            $taxPaid = 0.0;

            $d = $a->drawdown;
            if (null !== $d && $balance > 0.0 && $d->isActive($m, $a->deathMonthIndex)) {
                $target = $d->monthlyAmountToday;
                if ($d->inflationIndexed) {
                    $target *= (1 + $monthlyInflation) ** $m;
                }

                $gainsFraction = $isBrokerage && $balance > 0.0
                    ? max(0.0, ($balance - $basis) / $balance)
                    : 0.0;

                $desiredGross = DrawdownEntryMode::Gross === $d->entryMode
                    ? $target
                    : $taxModel->grossFromNet($a->accountType, $target, $gainsFraction);

                $grossWithdrawal = min($desiredGross, $balance);
                $netWithdrawal = $taxModel->netFromGross($a->accountType, $grossWithdrawal, $gainsFraction);
                $taxPaid = $grossWithdrawal - $netWithdrawal;

                if ($isBrokerage) {
                    $basis -= $grossWithdrawal * ($basis / $balance);
                }
                $balance -= $grossWithdrawal;

                if ($balance <= 0.005) {
                    $balance = 0.0;
                    $depletionMonthIndex ??= $m;
                }

                $totalGross += $grossWithdrawal;
                $totalNet += $netWithdrawal;
                $totalTax += $taxPaid;
            }
```

Supporting changes in `project()`:
- add `use App\Enum\DrawdownEntryMode;`
- before the loop: `$monthlyInflation = (1 + $a->annualInflationRate) ** (1 / 12) - 1;`, `$taxModel = new FlatRateTaxModel($a->ordinaryIncomeTaxRate, $a->capitalGainsTaxRate);`, `$totalGross = 0.0; $totalNet = 0.0; $totalTax = 0.0;`
- the `MonthState` constructor now receives the real `$grossWithdrawal`/`$netWithdrawal`/`$taxPaid`
- the summary receives `$totalGross`, `$totalNet`, `$totalTax`.

- [ ] **Step 6: Run tests to verify they pass**

Run: `cd backend && php bin/phpunit tests/Projection`, then full `make test`. Expected: green, pristine.

- [ ] **Step 7: Commit**

```bash
git add backend/src/Projection backend/tests/Projection
git commit -m "feat: flat-rate tax model and tax-aware drawdown simulation"
```

---

### Task 3: Stateless projection endpoint

**Files:**
- Create: `backend/src/Dto/ProjectionRequest.php`
- Create: `backend/src/Projection/MonthIndexMapper.php`
- Create: `backend/src/Controller/ProjectionController.php`
- Test: `backend/tests/Projection/MonthIndexMapperTest.php`
- Test: `backend/tests/Controller/ProjectionEndpointTest.php`

**Interfaces:**
- Consumes: engine (Tasks 1-2), `AccountInput`/`ContributionInput`/`DrawdownInput` DTOs (reused verbatim as the account payload), `ApiController::apiJson`.
- Produces: `POST /api/projection` with body
  `{"account": <AccountInput shape>, "taxes": {"ordinaryIncomeTaxRate","capitalGainsTaxRate"}, "birthDate": "YYYY-MM-DD"|null, "deathAge": int|null, "startsOn": "YYYY-MM-DD"|null}`
  → 200 `{"months":[{"index","date":"YYYY-MM","balance","realBalance","basis","contribution","grossWithdrawal","netWithdrawal","taxPaid"}...], "summary":{"endingBalance","endingRealBalance","depletionDate":"YYYY-MM"|null,"totalContributions","totalGrossWithdrawals","totalNetWithdrawals","totalTaxPaid"}}`.
  `startsOn` defaults to the first day of the current month. `realBalance = balance / (1+monthlyInflation)^(index+1)`.
  `MonthIndexMapper::indexOf(\DateTimeImmutable $start, \DateTimeImmutable $date): int` (whole months between the months containing each date; may be negative — the controller clamps schedule indices to ≥0 and rejects horizons ≤ 0). Weekly drawdown conversion (×52/12) happens here, in the controller mapping.
- Plan 4's projection view calls exactly this endpoint on every debounced slider change.

- [ ] **Step 1: Write the failing mapper test**

Create `backend/tests/Projection/MonthIndexMapperTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Projection;

use App\Projection\MonthIndexMapper;
use PHPUnit\Framework\TestCase;

final class MonthIndexMapperTest extends TestCase
{
    public function testSameMonthIsZero(): void
    {
        self::assertSame(0, MonthIndexMapper::indexOf(
            new \DateTimeImmutable('2026-07-01'),
            new \DateTimeImmutable('2026-07-31'),
        ));
    }

    public function testDayOfMonthIgnored(): void
    {
        self::assertSame(1, MonthIndexMapper::indexOf(
            new \DateTimeImmutable('2026-07-15'),
            new \DateTimeImmutable('2026-08-01'),
        ));
    }

    public function testYearsSpan(): void
    {
        self::assertSame(180, MonthIndexMapper::indexOf(
            new \DateTimeImmutable('2026-07-01'),
            new \DateTimeImmutable('2041-07-01'),
        ));
    }

    public function testPastDateIsNegative(): void
    {
        self::assertSame(-1, MonthIndexMapper::indexOf(
            new \DateTimeImmutable('2026-07-01'),
            new \DateTimeImmutable('2026-06-30'),
        ));
    }
}
```

- [ ] **Step 2: Write the failing endpoint test**

Create `backend/tests/Controller/ProjectionEndpointTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\ApiTestCase;

final class ProjectionEndpointTest extends ApiTestCase
{
    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'startsOn' => '2026-07-01',
            'birthDate' => '1990-06-15',
            'deathAge' => 90,
            'taxes' => ['ordinaryIncomeTaxRate' => 0.25, 'capitalGainsTaxRate' => 0.15],
            'account' => [
                'name' => 'preview',
                'type' => 'traditional_401k',
                'startingBalance' => 12000.0,
                'annualReturnRate' => 0.0,
                'inflationRate' => 0.0,
                'horizonYears' => 1,
                'contribution' => ['monthlyAmount' => 0.0],
                'drawdown' => [
                    'amount' => 1000.0,
                    'frequency' => 'monthly',
                    'entryMode' => 'gross',
                    'startsOn' => '2026-07-01',
                    'inflationIndexed' => false,
                ],
            ],
        ];
    }

    public function testProjectionMatchesEngineSemantics(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->jsonRequest('POST', '/api/projection', $this->payload());

        self::assertResponseIsSuccessful();
        $data = $this->json($client);
        self::assertCount(12, $data['months']);
        self::assertSame('2026-07', $data['months'][0]['date']);
        self::assertEqualsWithDelta(1000.0, $data['months'][0]['grossWithdrawal'], 0.01);
        self::assertEqualsWithDelta(750.0, $data['months'][0]['netWithdrawal'], 0.01);
        self::assertSame('2027-06', $data['summary']['depletionDate']);
        self::assertEqualsWithDelta(0.0, $data['summary']['endingBalance'], 0.01);
    }

    public function testWeeklyDrawdownConverted(): void
    {
        $payload = $this->payload();
        $payload['account']['drawdown']['amount'] = 230.77; // ~1000.04/mo at x52/12
        $payload['account']['drawdown']['frequency'] = 'weekly';
        $client = $this->createAuthenticatedClient('weekly@example.com');
        $client->jsonRequest('POST', '/api/projection', $payload);

        self::assertResponseIsSuccessful();
        $data = $this->json($client);
        self::assertEqualsWithDelta(230.77 * 52 / 12, $data['months'][0]['grossWithdrawal'], 0.01);
    }

    public function testRealBalanceDeflated(): void
    {
        $payload = $this->payload();
        $payload['account']['drawdown'] = ['amount' => null];
        $payload['account']['inflationRate'] = 0.03;
        $payload['account']['annualReturnRate'] = 0.03;
        // Growth exactly offsets inflation: real balance stays ~12000 every month.
        $client = $this->createAuthenticatedClient('real@example.com');
        $client->jsonRequest('POST', '/api/projection', $payload);

        self::assertResponseIsSuccessful();
        $data = $this->json($client);
        self::assertEqualsWithDelta(12000.0, $data['months'][11]['realBalance'], 0.5);
        self::assertGreaterThan(12300.0, $data['months'][11]['balance']);
    }

    public function testDeathAgeBoundsDrawdown(): void
    {
        // Death age reached during the horizon stops withdrawals after that month.
        $payload = $this->payload();
        $payload['birthDate'] = '1937-01-15'; // ~89.5 years old at startsOn; death age 90 hits mid-horizon
        $client = $this->createAuthenticatedClient('death@example.com');
        $client->jsonRequest('POST', '/api/projection', $payload);

        self::assertResponseIsSuccessful();
        $data = $this->json($client);
        $last = $data['months'][11];
        self::assertSame(0.0, $last['grossWithdrawal']);
    }

    public function testRequiresAuth(): void
    {
        $client = self::createClient();
        $client->jsonRequest('POST', '/api/projection', $this->payload());
        self::assertResponseStatusCodeSame(401);
    }

    public function testValidationErrors(): void
    {
        $payload = $this->payload();
        $payload['account']['horizonYears'] = 0;
        $client = $this->createAuthenticatedClient('invalid@example.com');
        $client->jsonRequest('POST', '/api/projection', $payload);
        self::assertResponseStatusCodeSame(422);
    }
}
```

- [ ] **Step 3: Run tests to verify they fail**

`make test` — FAIL (mapper missing, route 404).

- [ ] **Step 4: Implement mapper, DTO, controller**

Create `backend/src/Projection/MonthIndexMapper.php`:

```php
<?php

declare(strict_types=1);

namespace App\Projection;

final class MonthIndexMapper
{
    public static function indexOf(\DateTimeImmutable $start, \DateTimeImmutable $date): int
    {
        return ((int) $date->format('Y') - (int) $start->format('Y')) * 12
            + ((int) $date->format('n') - (int) $start->format('n'));
    }
}
```

Create `backend/src/Dto/ProjectionRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class TaxesInput
{
    public function __construct(
        #[Assert\Range(min: 0, max: 1)]
        public float $ordinaryIncomeTaxRate = 0.22,
        #[Assert\Range(min: 0, max: 1)]
        public float $capitalGainsTaxRate = 0.15,
    ) {
    }
}
```

Put `TaxesInput` in its own file `backend/src/Dto/TaxesInput.php`, then create `backend/src/Dto/ProjectionRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ProjectionRequest
{
    public function __construct(
        #[Assert\Valid]
        public AccountInput $account,
        #[Assert\Valid]
        public TaxesInput $taxes = new TaxesInput(),
        #[Assert\Date]
        public ?string $birthDate = null,
        #[Assert\Range(min: 1, max: 120)]
        public ?int $deathAge = null,
        #[Assert\Date]
        public ?string $startsOn = null,
    ) {
    }
}
```

Create `backend/src/Controller/ProjectionController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\AccountInput;
use App\Dto\ProjectionRequest;
use App\Enum\DrawdownFrequency;
use App\Projection\ContributionSchedule;
use App\Projection\DrawdownSchedule;
use App\Projection\MonthIndexMapper;
use App\Projection\ProjectionAssumptions;
use App\Projection\ProjectionResult;
use App\Projection\Projector;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final class ProjectionController extends ApiController
{
    #[Route('/api/projection', name: 'api_projection', methods: ['POST'])]
    public function project(#[MapRequestPayload] ProjectionRequest $request): JsonResponse
    {
        $start = self::firstOfMonth(
            null !== $request->startsOn ? new \DateTimeImmutable($request->startsOn) : new \DateTimeImmutable('now'),
        );

        $assumptions = self::buildAssumptions($request, $start);
        $result = (new Projector())->project($assumptions);

        return $this->apiJson(self::serialize($result, $start, $assumptions->annualInflationRate));
    }

    public static function firstOfMonth(\DateTimeImmutable $d): \DateTimeImmutable
    {
        return $d->modify('first day of this month')->setTime(0, 0);
    }

    public static function buildAssumptions(ProjectionRequest $request, \DateTimeImmutable $start): ProjectionAssumptions
    {
        $account = $request->account;
        $horizonMonths = $account->horizonYears * 12;

        $toIndex = static fn (?string $date): ?int => null === $date
            ? null
            : MonthIndexMapper::indexOf($start, new \DateTimeImmutable($date));

        $clamp = static fn (?int $i): ?int => null === $i ? null : max(0, $i);

        $contribution = new ContributionSchedule(
            monthlyAmount: $account->contribution->monthlyAmount,
            annualEscalationRate: $account->contribution->escalationRate,
            startMonthIndex: $clamp($toIndex($account->contribution->startsOn)),
            endMonthIndex: $toIndex($account->contribution->endsOn),
        );

        $drawdown = null;
        if (null !== $account->drawdown->amount && null !== $account->drawdown->startsOn) {
            $monthlyAmount = DrawdownFrequency::Weekly === $account->drawdown->frequency
                ? $account->drawdown->amount * 52 / 12
                : $account->drawdown->amount;
            $drawdown = new DrawdownSchedule(
                monthlyAmountToday: $monthlyAmount,
                entryMode: $account->drawdown->entryMode,
                startMonthIndex: max(0, (int) $toIndex($account->drawdown->startsOn)),
                endMonthIndex: $toIndex($account->drawdown->endsOn),
                inflationIndexed: $account->drawdown->inflationIndexed,
            );
        }

        $deathMonthIndex = null;
        if (null !== $request->birthDate && null !== $request->deathAge) {
            $deathDate = (new \DateTimeImmutable($request->birthDate))->modify(sprintf('+%d years', $request->deathAge));
            $deathMonthIndex = MonthIndexMapper::indexOf($start, $deathDate);
        }

        return new ProjectionAssumptions(
            horizonMonths: $horizonMonths,
            accountType: $account->type,
            startingBalance: $account->startingBalance,
            startingBasis: $account->startingBasis,
            annualReturnRate: $account->annualReturnRate,
            annualInflationRate: $account->inflationRate,
            ordinaryIncomeTaxRate: $request->taxes->ordinaryIncomeTaxRate,
            capitalGainsTaxRate: $request->taxes->capitalGainsTaxRate,
            contribution: $contribution,
            drawdown: $drawdown,
            deathMonthIndex: $deathMonthIndex,
        );
    }

    /** @return array<string, mixed> */
    public static function serialize(ProjectionResult $result, \DateTimeImmutable $start, float $annualInflationRate): array
    {
        $monthlyInflation = (1 + $annualInflationRate) ** (1 / 12) - 1;
        $months = [];
        foreach ($result->months as $m) {
            $months[] = [
                'index' => $m->index,
                'date' => $start->modify(sprintf('+%d months', $m->index))->format('Y-m'),
                'balance' => round($m->balance, 2),
                'realBalance' => round($m->balance / (1 + $monthlyInflation) ** ($m->index + 1), 2),
                'basis' => round($m->basis, 2),
                'contribution' => round($m->contribution, 2),
                'grossWithdrawal' => round($m->grossWithdrawal, 2),
                'netWithdrawal' => round($m->netWithdrawal, 2),
                'taxPaid' => round($m->taxPaid, 2),
            ];
        }

        $s = $result->summary;
        $lastIndex = [] === $months ? 0 : $result->months[array_key_last($result->months)]->index;

        return [
            'months' => $months,
            'summary' => [
                'endingBalance' => round($s->endingBalance, 2),
                'endingRealBalance' => round($s->endingBalance / (1 + $monthlyInflation) ** ($lastIndex + 1), 2),
                'depletionDate' => null === $s->depletionMonthIndex
                    ? null
                    : $start->modify(sprintf('+%d months', $s->depletionMonthIndex))->format('Y-m'),
                'totalContributions' => round($s->totalContributions, 2),
                'totalGrossWithdrawals' => round($s->totalGrossWithdrawals, 2),
                'totalNetWithdrawals' => round($s->totalNetWithdrawals, 2),
                'totalTaxPaid' => round($s->totalTaxPaid, 2),
            ],
        ];
    }
}
```

(`buildAssumptions`/`serialize`/`firstOfMonth` are public static so Task 5's goal-seek controller reuses them — that reuse is the reason they aren't private.)

- [ ] **Step 5: Run tests to verify they pass**

`make test` — all green, pristine. Debug against actual response bodies if the endpoint tests disagree with the engine tests.

- [ ] **Step 6: Commit**

```bash
git add backend/src backend/tests
git commit -m "feat: stateless POST /api/projection endpoint"
```

---

### Task 4: Goal-seek solver

**Files:**
- Create: `backend/src/Projection/Goal/DrawdownGoal.php`
- Create: `backend/src/Projection/Goal/TargetValueGoal.php`
- Create: `backend/src/Projection/Goal/Goal.php` (interface)
- Create: `backend/src/Projection/GoalSeeker.php`
- Create: `backend/src/Projection/GoalSeekResult.php`
- Test: `backend/tests/Projection/GoalSeekerTest.php`

**Interfaces:**
- Consumes: Projector + assumptions (Tasks 1-2).
- Produces:
  - `interface Goal { public function isSatisfiedBy(ProjectionResult $result): bool; }`
  - `new DrawdownGoal(int $mustSurviveThroughMonthIndex)` — satisfied iff `depletionMonthIndex === null || depletionMonthIndex > $mustSurviveThroughMonthIndex` (strict: depletion AT the survive-through month may mean the final withdrawal was a near-total shortfall, which depletionMonthIndex alone cannot distinguish — so the solver requires surviving past it, over-requiring by at most ~one cent of monthly contribution).
  - `new TargetValueGoal(float $nominalTarget, int $atMonthIndex)` — satisfied iff `months[atMonthIndex].balance >= nominalTarget`.
  - `GoalSeeker::solve(ProjectionAssumptions $base, Goal $goal): GoalSeekResult` — finds the minimum contribution `monthlyAmount` (replacing `$base->contribution->monthlyAmount`, keeping window/escalation) that satisfies the goal.
  - `GoalSeekResult { public bool $attainable; public float $requiredMonthlyContribution; public ProjectionResult $projection; }` — when unattainable (cap 10,000,000/mo), `requiredMonthlyContribution` is `0.0` and `projection` is the base run.

- [ ] **Step 1: Write the failing tests**

Create `backend/tests/Projection/GoalSeekerTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Projection;

use App\Enum\AccountType;
use App\Enum\DrawdownEntryMode;
use App\Projection\ContributionSchedule;
use App\Projection\DrawdownSchedule;
use App\Projection\Goal\DrawdownGoal;
use App\Projection\Goal\TargetValueGoal;
use App\Projection\GoalSeeker;
use App\Projection\ProjectionAssumptions;
use App\Projection\Projector;
use PHPUnit\Framework\TestCase;

final class GoalSeekerTest extends TestCase
{
    private function base(?DrawdownSchedule $drawdown, int $horizonMonths, float $annualReturnRate = 0.0): ProjectionAssumptions
    {
        return new ProjectionAssumptions(
            horizonMonths: $horizonMonths,
            accountType: AccountType::RothIra,
            startingBalance: 0.0,
            startingBasis: null,
            annualReturnRate: $annualReturnRate,
            annualInflationRate: 0.0,
            ordinaryIncomeTaxRate: 0.25,
            capitalGainsTaxRate: 0.15,
            contribution: new ContributionSchedule(0.0, 0.0, 0, null),
            drawdown: $drawdown,
            deathMonthIndex: null,
        );
    }

    public function testTargetValueNoGrowthIsExactDivision(): void
    {
        // Reach 12000 at month 11 with no growth: 1000/mo.
        $result = (new GoalSeeker())->solve(
            $this->base(null, 12),
            new TargetValueGoal(12000.0, 11),
        );
        self::assertTrue($result->attainable);
        self::assertEqualsWithDelta(1000.0, $result->requiredMonthlyContribution, 0.5);
    }

    public function testSolvedContributionActuallySatisfiesGoal(): void
    {
        $goal = new TargetValueGoal(500000.0, 179);
        $solved = (new GoalSeeker())->solve($this->base(null, 180, 0.07), $goal);
        self::assertTrue($solved->attainable);
        self::assertTrue($goal->isSatisfiedBy($solved->projection));

        // And meaningfully minimal: 1% less contribution must fail.
        $less = $this->base(null, 180, 0.07);
        $lessResult = (new Projector())->project(new ProjectionAssumptions(
            horizonMonths: $less->horizonMonths,
            accountType: $less->accountType,
            startingBalance: $less->startingBalance,
            startingBasis: $less->startingBasis,
            annualReturnRate: $less->annualReturnRate,
            annualInflationRate: $less->annualInflationRate,
            ordinaryIncomeTaxRate: $less->ordinaryIncomeTaxRate,
            capitalGainsTaxRate: $less->capitalGainsTaxRate,
            contribution: new ContributionSchedule($solved->requiredMonthlyContribution * 0.99, 0.0, 0, null),
            drawdown: null,
            deathMonthIndex: null,
        ));
        self::assertFalse($goal->isSatisfiedBy($lessResult));
    }

    public function testDrawdownGoalSurvivesThroughEnd(): void
    {
        // Contribute months 0..119, draw 1000/mo gross months 120..179 (Roth, no tax, no growth):
        // need 60000 saved by month 120 → 500/mo.
        $drawdown = new DrawdownSchedule(1000.0, DrawdownEntryMode::Gross, 120, 179, false);
        $base = new ProjectionAssumptions(
            horizonMonths: 180,
            accountType: AccountType::RothIra,
            startingBalance: 0.0,
            startingBasis: null,
            annualReturnRate: 0.0,
            annualInflationRate: 0.0,
            ordinaryIncomeTaxRate: 0.25,
            capitalGainsTaxRate: 0.15,
            contribution: new ContributionSchedule(0.0, 0.0, 0, 119),
            drawdown: $drawdown,
            deathMonthIndex: null,
        );
        $result = (new GoalSeeker())->solve($base, new DrawdownGoal(179));
        self::assertTrue($result->attainable);
        self::assertEqualsWithDelta(500.0, $result->requiredMonthlyContribution, 1.0);
        self::assertTrue((new DrawdownGoal(179))->isSatisfiedBy($result->projection));
    }

    public function testUnattainableGoalReported(): void
    {
        // 1-month horizon, no growth, need 20M at month 0 — above the 10M/mo cap.
        $result = (new GoalSeeker())->solve(
            $this->base(null, 1),
            new TargetValueGoal(20000000.0, 0),
        );
        self::assertFalse($result->attainable);
        self::assertSame(0.0, $result->requiredMonthlyContribution);
    }

    public function testZeroContributionSufficientWhenGoalAlreadyMet(): void
    {
        $base = new ProjectionAssumptions(
            horizonMonths: 12,
            accountType: AccountType::RothIra,
            startingBalance: 50000.0,
            startingBasis: null,
            annualReturnRate: 0.0,
            annualInflationRate: 0.0,
            ordinaryIncomeTaxRate: 0.25,
            capitalGainsTaxRate: 0.15,
            contribution: new ContributionSchedule(0.0),
            drawdown: null,
            deathMonthIndex: null,
        );
        $result = (new GoalSeeker())->solve($base, new TargetValueGoal(10000.0, 11));
        self::assertTrue($result->attainable);
        self::assertEqualsWithDelta(0.0, $result->requiredMonthlyContribution, 0.01);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

`cd backend && php bin/phpunit tests/Projection/GoalSeekerTest.php` — FAIL, classes missing.

- [ ] **Step 3: Implement goals and solver**

Create `backend/src/Projection/Goal/Goal.php`:

```php
<?php

declare(strict_types=1);

namespace App\Projection\Goal;

use App\Projection\ProjectionResult;

interface Goal
{
    public function isSatisfiedBy(ProjectionResult $result): bool;
}
```

Create `backend/src/Projection/Goal/DrawdownGoal.php`:

```php
<?php

declare(strict_types=1);

namespace App\Projection\Goal;

use App\Projection\ProjectionResult;

final readonly class DrawdownGoal implements Goal
{
    public function __construct(public int $mustSurviveThroughMonthIndex)
    {
    }

    public function isSatisfiedBy(ProjectionResult $result): bool
    {
        $depletion = $result->summary->depletionMonthIndex;

        return null === $depletion || $depletion > $this->mustSurviveThroughMonthIndex;
    }
}
```

Create `backend/src/Projection/Goal/TargetValueGoal.php`:

```php
<?php

declare(strict_types=1);

namespace App\Projection\Goal;

use App\Projection\ProjectionResult;

final readonly class TargetValueGoal implements Goal
{
    public function __construct(
        public float $nominalTarget,
        public int $atMonthIndex,
    ) {
    }

    public function isSatisfiedBy(ProjectionResult $result): bool
    {
        if (!isset($result->months[$this->atMonthIndex])) {
            return false;
        }

        return $result->months[$this->atMonthIndex]->balance >= $this->nominalTarget;
    }
}
```

Create `backend/src/Projection/GoalSeekResult.php`:

```php
<?php

declare(strict_types=1);

namespace App\Projection;

final readonly class GoalSeekResult
{
    public function __construct(
        public bool $attainable,
        public float $requiredMonthlyContribution,
        public ProjectionResult $projection,
    ) {
    }
}
```

Create `backend/src/Projection/GoalSeeker.php`:

```php
<?php

declare(strict_types=1);

namespace App\Projection;

use App\Projection\Goal\Goal;

/**
 * Bisection over the (monotone) contribution → outcome relationship.
 */
final class GoalSeeker
{
    private const CONTRIBUTION_CAP = 10_000_000.0;
    private const TOLERANCE = 0.01;

    public function __construct(private readonly Projector $projector = new Projector())
    {
    }

    public function solve(ProjectionAssumptions $base, Goal $goal): GoalSeekResult
    {
        $run = fn (float $monthly): ProjectionResult => $this->projector->project($this->withContribution($base, $monthly));

        $zeroResult = $run(0.0);
        if ($goal->isSatisfiedBy($zeroResult)) {
            return new GoalSeekResult(true, 0.0, $zeroResult);
        }

        $lo = 0.0;
        $hi = 100.0;
        while (!$goal->isSatisfiedBy($run($hi))) {
            $hi *= 2;
            if ($hi > self::CONTRIBUTION_CAP) {
                return new GoalSeekResult(false, 0.0, $zeroResult);
            }
        }

        while ($hi - $lo > self::TOLERANCE) {
            $mid = ($lo + $hi) / 2;
            if ($goal->isSatisfiedBy($run($mid))) {
                $hi = $mid;
            } else {
                $lo = $mid;
            }
        }

        return new GoalSeekResult(true, round($hi, 2), $run(round($hi, 2)));
    }

    private function withContribution(ProjectionAssumptions $a, float $monthlyAmount): ProjectionAssumptions
    {
        return new ProjectionAssumptions(
            horizonMonths: $a->horizonMonths,
            accountType: $a->accountType,
            startingBalance: $a->startingBalance,
            startingBasis: $a->startingBasis,
            annualReturnRate: $a->annualReturnRate,
            annualInflationRate: $a->annualInflationRate,
            ordinaryIncomeTaxRate: $a->ordinaryIncomeTaxRate,
            capitalGainsTaxRate: $a->capitalGainsTaxRate,
            contribution: new ContributionSchedule(
                monthlyAmount: $monthlyAmount,
                annualEscalationRate: $a->contribution->annualEscalationRate,
                startMonthIndex: $a->contribution->startMonthIndex,
                endMonthIndex: $a->contribution->endMonthIndex,
            ),
            drawdown: $a->drawdown,
            deathMonthIndex: $a->deathMonthIndex,
        );
    }
}
```

Note: `round($hi, 2)` before the final run can land a hair under the true threshold; if `testSolvedContributionActuallySatisfiesGoal` flakes on that, use `$final = ceil($hi * 100) / 100;` (round UP to the cent) — that is the correct fix, not loosening the test.

- [ ] **Step 4: Run tests to verify they pass**

`cd backend && php bin/phpunit tests/Projection/GoalSeekerTest.php`, then full `make test`. Green, pristine.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Projection backend/tests/Projection
git commit -m "feat: bisection goal-seek solver with drawdown and target-value goals"
```

---

### Task 5: Stateless goal-seek endpoint and push

**Files:**
- Create: `backend/src/Dto/GoalSeekRequest.php`
- Create: `backend/src/Controller/GoalSeekController.php`
- Test: `backend/tests/Controller/GoalSeekEndpointTest.php`

**Interfaces:**
- Consumes: `GoalSeeker`, `ProjectionController::{buildAssumptions,serialize,firstOfMonth}`, DTOs.
- Produces: `POST /api/goal-seek` with body
  `{"account": <AccountInput shape — contribution.monthlyAmount ignored>, "taxes": {...}, "birthDate", "deathAge", "startsOn", "goal": {"kind": "drawdown"} | {"kind": "target_value", "amount": float, "atDate": "YYYY-MM-DD", "amountInTodaysDollars": bool}}`
  → 200 `{"attainable": bool, "requiredMonthlyContribution": float, "requiredYearlyContribution": float, "projection": <same shape as /api/projection response>}`.
  - `kind=drawdown`: requires the account payload to carry a drawdown with amount+startsOn; survival horizon = explicit drawdown `endsOn`, else death (birthDate+deathAge), else projection horizon end. 422 if the account has no drawdown amount/start.
  - `kind=target_value`: `amount` at `atDate`; if `amountInTodaysDollars`, the controller converts to nominal via `amount * (1+monthlyInflation)^(index+1)` before building `TargetValueGoal`. 422 if `atDate` is outside the horizon.
- Plan 4's goal-seek view calls exactly this endpoint.

- [ ] **Step 1: Write the failing tests**

Create `backend/tests/Controller/GoalSeekEndpointTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\ApiTestCase;

final class GoalSeekEndpointTest extends ApiTestCase
{
    /** @return array<string, mixed> */
    private function payload(array $goal): array
    {
        return [
            'startsOn' => '2026-07-01',
            'birthDate' => '1990-06-15',
            'deathAge' => 90,
            'taxes' => ['ordinaryIncomeTaxRate' => 0.25, 'capitalGainsTaxRate' => 0.15],
            'goal' => $goal,
            'account' => [
                'name' => 'goal',
                'type' => 'roth_ira',
                'startingBalance' => 0.0,
                'annualReturnRate' => 0.0,
                'inflationRate' => 0.0,
                'horizonYears' => 15,
                'contribution' => ['monthlyAmount' => 0.0, 'startsOn' => '2026-07-01', 'endsOn' => '2036-06-01'],
                'drawdown' => [
                    'amount' => 1000.0,
                    'frequency' => 'monthly',
                    'entryMode' => 'gross',
                    'startsOn' => '2036-07-01',
                    'endsOn' => '2041-06-01',
                    'inflationIndexed' => false,
                ],
            ],
        ];
    }

    public function testDrawdownGoalSolvesContribution(): void
    {
        // Save months 0..119, draw 1000/mo months 120..179 (Roth, no growth): need 500/mo.
        $client = $this->createAuthenticatedClient();
        $client->jsonRequest('POST', '/api/goal-seek', $this->payload(['kind' => 'drawdown']));

        self::assertResponseIsSuccessful();
        $data = $this->json($client);
        self::assertTrue($data['attainable']);
        self::assertEqualsWithDelta(500.0, $data['requiredMonthlyContribution'], 1.0);
        self::assertEqualsWithDelta($data['requiredMonthlyContribution'] * 12, $data['requiredYearlyContribution'], 0.01);
        // Exactly-solved contribution may deplete precisely at the drawdown end month — both outcomes satisfy the goal.
        self::assertContains($data['projection']['summary']['depletionDate'], [null, '2041-06']);
        self::assertCount(180, $data['projection']['months']);
    }

    public function testTargetValueGoal(): void
    {
        $payload = $this->payload([
            'kind' => 'target_value',
            'amount' => 60000.0,
            'atDate' => '2036-06-01',
            'amountInTodaysDollars' => false,
        ]);
        $payload['account']['drawdown'] = ['amount' => null];

        $client = $this->createAuthenticatedClient('target@example.com');
        $client->jsonRequest('POST', '/api/goal-seek', $payload);

        self::assertResponseIsSuccessful();
        $data = $this->json($client);
        self::assertTrue($data['attainable']);
        // Window is months 0..119 inclusive = 120 payments; 60000/120 = 500/mo.
        self::assertEqualsWithDelta(500.0, $data['requiredMonthlyContribution'], 1.0);
    }

    public function testDrawdownGoalWithoutDrawdownRejected(): void
    {
        $payload = $this->payload(['kind' => 'drawdown']);
        $payload['account']['drawdown'] = ['amount' => null];
        $client = $this->createAuthenticatedClient('nodraw@example.com');
        $client->jsonRequest('POST', '/api/goal-seek', $payload);
        self::assertResponseStatusCodeSame(422);
    }

    public function testTargetDateOutsideHorizonRejected(): void
    {
        $payload = $this->payload([
            'kind' => 'target_value',
            'amount' => 1000.0,
            'atDate' => '2099-01-01',
            'amountInTodaysDollars' => false,
        ]);
        $client = $this->createAuthenticatedClient('outside@example.com');
        $client->jsonRequest('POST', '/api/goal-seek', $payload);
        self::assertResponseStatusCodeSame(422);
    }

    public function testRequiresAuth(): void
    {
        $client = self::createClient();
        $client->jsonRequest('POST', '/api/goal-seek', $this->payload(['kind' => 'drawdown']));
        self::assertResponseStatusCodeSame(401);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

`make test` — FAIL, route 404.

- [ ] **Step 3: Implement DTO and controller**

Create `backend/src/Dto/GoalSeekRequest.php` (two files — `GoalInput` in `backend/src/Dto/GoalInput.php`):

```php
<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class GoalInput
{
    public function __construct(
        #[Assert\Choice(['drawdown', 'target_value'])]
        public string $kind,
        #[Assert\Positive]
        public ?float $amount = null,
        #[Assert\Date]
        public ?string $atDate = null,
        public bool $amountInTodaysDollars = true,
    ) {
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class GoalSeekRequest
{
    public function __construct(
        #[Assert\Valid]
        public AccountInput $account,
        #[Assert\Valid]
        public GoalInput $goal,
        #[Assert\Valid]
        public TaxesInput $taxes = new TaxesInput(),
        #[Assert\Date]
        public ?string $birthDate = null,
        #[Assert\Range(min: 1, max: 120)]
        public ?int $deathAge = null,
        #[Assert\Date]
        public ?string $startsOn = null,
    ) {
    }
}
```

Create `backend/src/Controller/GoalSeekController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\GoalSeekRequest;
use App\Dto\ProjectionRequest;
use App\Projection\Goal\DrawdownGoal;
use App\Projection\Goal\Goal;
use App\Projection\Goal\TargetValueGoal;
use App\Projection\GoalSeeker;
use App\Projection\MonthIndexMapper;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class GoalSeekController extends ApiController
{
    #[Route('/api/goal-seek', name: 'api_goal_seek', methods: ['POST'])]
    public function solve(#[MapRequestPayload] GoalSeekRequest $request): JsonResponse
    {
        $start = ProjectionController::firstOfMonth(
            null !== $request->startsOn ? new \DateTimeImmutable($request->startsOn) : new \DateTimeImmutable('now'),
        );

        $projectionRequest = new ProjectionRequest(
            account: $request->account,
            taxes: $request->taxes,
            birthDate: $request->birthDate,
            deathAge: $request->deathAge,
            startsOn: $request->startsOn,
        );
        $assumptions = ProjectionController::buildAssumptions($projectionRequest, $start);

        $goal = $this->buildGoal($request, $assumptions->horizonMonths, $assumptions->deathMonthIndex, $assumptions->annualInflationRate, $start, $assumptions->drawdown?->startMonthIndex, $assumptions->drawdown?->endMonthIndex, null !== $assumptions->drawdown);

        $result = (new GoalSeeker())->solve($assumptions, $goal);

        return $this->apiJson([
            'attainable' => $result->attainable,
            'requiredMonthlyContribution' => $result->requiredMonthlyContribution,
            'requiredYearlyContribution' => round($result->requiredMonthlyContribution * 12, 2),
            'projection' => ProjectionController::serialize($result->projection, $start, $assumptions->annualInflationRate),
        ]);
    }

    private function buildGoal(
        GoalSeekRequest $request,
        int $horizonMonths,
        ?int $deathMonthIndex,
        float $annualInflationRate,
        \DateTimeImmutable $start,
        ?int $drawdownStart,
        ?int $drawdownEnd,
        bool $hasDrawdown,
    ): Goal {
        if ('drawdown' === $request->goal->kind) {
            if (!$hasDrawdown) {
                throw new UnprocessableEntityHttpException('A drawdown goal requires the account to define a drawdown amount and start.');
            }
            $surviveThrough = $drawdownEnd ?? $deathMonthIndex ?? ($horizonMonths - 1);

            return new DrawdownGoal(min($surviveThrough, $horizonMonths - 1));
        }

        if (null === $request->goal->amount || null === $request->goal->atDate) {
            throw new UnprocessableEntityHttpException('A target_value goal requires amount and atDate.');
        }
        $atIndex = MonthIndexMapper::indexOf($start, new \DateTimeImmutable($request->goal->atDate));
        if ($atIndex < 0 || $atIndex >= $horizonMonths) {
            throw new UnprocessableEntityHttpException('atDate is outside the projection horizon.');
        }

        $nominal = $request->goal->amount;
        if ($request->goal->amountInTodaysDollars) {
            $monthlyInflation = (1 + $annualInflationRate) ** (1 / 12) - 1;
            $nominal *= (1 + $monthlyInflation) ** ($atIndex + 1);
        }

        return new TargetValueGoal($nominal, $atIndex);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

`make test` — full suite green, pristine.

- [ ] **Step 5: Commit and push**

```bash
git add backend/src backend/tests
git commit -m "feat: stateless POST /api/goal-seek endpoint"
git push origin main
```
