<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ContributionInput
{
    public function __construct(
        #[Assert\PositiveOrZero]
        public float $monthlyAmount = 0.0,
        #[Assert\Range(min: 0, max: 1)]
        public float $escalationRate = 0.0,
        #[Assert\Date]
        public ?string $startsOn = null,
        #[Assert\Date]
        public ?string $endsOn = null,
    ) {
    }
}
