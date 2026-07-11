<?php

declare(strict_types=1);

namespace App\Projection;

final readonly class GoalSeekResult
{
    public function __construct(
        public bool $attainable,
        public float $requiredMonthlyContribution,
        public ProjectionResult $projection,
    ) {
    }
}
