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
