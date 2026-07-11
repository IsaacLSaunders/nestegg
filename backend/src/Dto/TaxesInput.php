<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class TaxesInput
{
    public function __construct(
        #[Assert\Range(min: 0, max: 1)]
        public float $ordinaryIncomeTaxRate = 0.22,
        #[Assert\Range(min: 0, max: 1)]
        public float $capitalGainsTaxRate = 0.15,
    ) {
    }
}
