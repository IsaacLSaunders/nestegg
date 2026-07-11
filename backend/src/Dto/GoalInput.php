<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class GoalInput
{
    public function __construct(
        #[Assert\Choice(choices: ['drawdown', 'target_value'])]
        public string $kind,
        #[Assert\Positive]
        public ?float $amount = null,
        #[Assert\Date]
        public ?string $atDate = null,
        public bool $amountInTodaysDollars = true,
    ) {
    }
}
