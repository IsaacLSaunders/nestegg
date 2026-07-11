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
