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

    public function testAttainableGoalBetweenDoublingOvershootAndCap(): void
    {
        // Doubling from 100 reaches 6,553,600 then must clamp to the 10M cap rather than
        // jump past it: an 8M/mo requirement is attainable and must be found.
        $result = (new GoalSeeker())->solve(
            $this->base(null, 1),
            new TargetValueGoal(8000000.0, 0),
        );
        self::assertTrue($result->attainable);
        self::assertEqualsWithDelta(8000000.0, $result->requiredMonthlyContribution, 1.0);
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
