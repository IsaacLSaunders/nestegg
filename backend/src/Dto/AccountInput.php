<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\AccountType;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class AccountInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 120)]
        public string $name,
        public AccountType $type,
        #[Assert\PositiveOrZero]
        public float $startingBalance = 0.0,
        #[Assert\PositiveOrZero]
        public ?float $startingBasis = null,
        #[Assert\Range(min: -1, max: 1)]
        public float $annualReturnRate = 0.07,
        #[Assert\Range(min: 0, max: 1)]
        public float $inflationRate = 0.03,
        #[Assert\Range(min: 1, max: 100)]
        public int $horizonYears = 40,
        #[Assert\Valid]
        public ContributionInput $contribution = new ContributionInput(),
        #[Assert\Valid]
        public DrawdownInput $drawdown = new DrawdownInput(),
    ) {
    }
}
