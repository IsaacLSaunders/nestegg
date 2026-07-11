<?php

declare(strict_types=1);

namespace App\Projection\Goal;

use App\Projection\ProjectionResult;

final readonly class DrawdownGoal implements Goal
{
    public function __construct(public int $mustSurviveThroughMonthIndex)
    {
    }

    public function isSatisfiedBy(ProjectionResult $result): bool
    {
        $depletion = $result->summary->depletionMonthIndex;

        return null === $depletion || $depletion > $this->mustSurviveThroughMonthIndex;
    }
}
