<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class PortfolioProjectionRequest
{
    /** @param list<AccountInput> $accounts */
    public function __construct(
        #[Assert\Valid]
        #[Assert\Count(min: 1)]
        public array $accounts,
        #[Assert\Valid]
        public TaxesInput $taxes = new TaxesInput(),
        #[Assert\Date]
        public ?string $birthDate = null,
        #[Assert\Range(min: 1, max: 120)]
        public ?int $deathAge = null,
        #[Assert\Date]
        public ?string $startsOn = null,
    ) {
    }
}
