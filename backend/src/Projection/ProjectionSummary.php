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
