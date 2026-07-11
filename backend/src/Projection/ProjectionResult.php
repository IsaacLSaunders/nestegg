<?php

declare(strict_types=1);

namespace App\Projection;

final readonly class ProjectionResult
{
    /** @param list<MonthState> $months */
    public function __construct(
        public array $months,
        public ProjectionSummary $summary,
    ) {
    }
}
