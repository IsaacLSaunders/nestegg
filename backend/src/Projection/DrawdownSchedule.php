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
