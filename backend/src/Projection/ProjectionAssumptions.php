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
