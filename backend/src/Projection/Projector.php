<?php

declare(strict_types=1);

namespace App\Projection;

use App\Enum\AccountType;
use App\Enum\DrawdownEntryMode;

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
        $monthlyInflation = (1 + $a->annualInflationRate) ** (1 / 12) - 1;
        $taxModel = new FlatRateTaxModel($a->ordinaryIncomeTaxRate, $a->capitalGainsTaxRate);

        $balance = $a->startingBalance;
        $isBrokerage = AccountType::Brokerage === $a->accountType;
        $basis = $isBrokerage ? ($a->startingBasis ?? $a->startingBalance) : 0.0;

        $months = [];
        $totalContributions = 0.0;
        $totalGross = 0.0;
        $totalNet = 0.0;
        $totalTax = 0.0;
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
            $grossWithdrawal = 0.0;
            $netWithdrawal = 0.0;
            $taxPaid = 0.0;

            $d = $a->drawdown;
            if (null !== $d && $d->isActive($m, $a->deathMonthIndex)) {
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

                if ($isBrokerage && $balance > 0.0) {
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

            $months[] = new MonthState(
                index: $m,
                balance: $balance,
                basis: $basis,
                contribution: $contribution,
                grossWithdrawal: $grossWithdrawal,
                netWithdrawal: $netWithdrawal,
                taxPaid: $taxPaid,
            );
        }

        return new ProjectionResult($months, new ProjectionSummary(
            endingBalance: $balance,
            depletionMonthIndex: $depletionMonthIndex,
            totalContributions: $totalContributions,
            totalGrossWithdrawals: $totalGross,
            totalNetWithdrawals: $totalNet,
            totalTaxPaid: $totalTax,
        ));
    }
}
