<?php

declare(strict_types=1);

namespace App\Projection\Goal;

use App\Projection\ProjectionResult;

final readonly class TargetValueGoal implements Goal
{
    public function __construct(
        public float $nominalTarget,
        public int $atMonthIndex,
    ) {
    }

    public function isSatisfiedBy(ProjectionResult $result): bool
    {
        if (!isset($result->months[$this->atMonthIndex])) {
            return false;
        }

        return $result->months[$this->atMonthIndex]->balance >= $this->nominalTarget;
    }
}
