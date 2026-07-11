<?php

declare(strict_types=1);

namespace App\Projection;

final class MonthIndexMapper
{
    public static function indexOf(\DateTimeImmutable $start, \DateTimeImmutable $date): int
    {
        return ((int) $date->format('Y') - (int) $start->format('Y')) * 12
            + ((int) $date->format('n') - (int) $start->format('n'));
    }
}
