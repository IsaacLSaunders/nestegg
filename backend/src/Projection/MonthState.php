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
