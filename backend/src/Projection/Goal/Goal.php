<?php

declare(strict_types=1);

namespace App\Projection\Goal;

use App\Projection\ProjectionResult;

interface Goal
{
    public function isSatisfiedBy(ProjectionResult $result): bool;
}
