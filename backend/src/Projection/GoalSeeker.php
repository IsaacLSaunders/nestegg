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
            if ($hi >= self::CONTRIBUTION_CAP) {
                return new GoalSeekResult(false, 0.0, $zeroResult);
            }
            $hi = min($hi * 2, self::CONTRIBUTION_CAP);
        }

        while ($hi - $lo > self::TOLERANCE) {
            $mid = ($lo + $hi) / 2;
            if ($goal->isSatisfiedBy($run($mid))) {
                $hi = $mid;
            } else {
                $lo = $mid;
            }
        }

        // Round UP to the cent so the returned contribution provably satisfies the goal.
        $final = ceil($hi * 100) / 100;
        $finalRun = $run($final);
        if (!$goal->isSatisfiedBy($finalRun)) {
            $final = $hi;
            $finalRun = $run($hi);
        }

        return new GoalSeekResult(true, round($final, 2), $finalRun);
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
