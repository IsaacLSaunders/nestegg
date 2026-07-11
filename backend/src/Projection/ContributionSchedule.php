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
