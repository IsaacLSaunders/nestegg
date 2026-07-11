<?php

declare(strict_types=1);

namespace App\Tests\Projection;

use App\Projection\MonthIndexMapper;
use PHPUnit\Framework\TestCase;

final class MonthIndexMapperTest extends TestCase
{
    public function testSameMonthIsZero(): void
    {
        self::assertSame(0, MonthIndexMapper::indexOf(
            new \DateTimeImmutable('2026-07-01'),
            new \DateTimeImmutable('2026-07-31'),
        ));
    }

    public function testDayOfMonthIgnored(): void
    {
        self::assertSame(1, MonthIndexMapper::indexOf(
            new \DateTimeImmutable('2026-07-15'),
            new \DateTimeImmutable('2026-08-01'),
        ));
    }

    public function testYearsSpan(): void
    {
        self::assertSame(180, MonthIndexMapper::indexOf(
            new \DateTimeImmutable('2026-07-01'),
            new \DateTimeImmutable('2041-07-01'),
        ));
    }

    public function testPastDateIsNegative(): void
    {
        self::assertSame(-1, MonthIndexMapper::indexOf(
            new \DateTimeImmutable('2026-07-01'),
            new \DateTimeImmutable('2026-06-30'),
        ));
    }
}
