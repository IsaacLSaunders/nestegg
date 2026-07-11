<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\DrawdownEntryMode;
use App\Enum\DrawdownFrequency;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class DrawdownInput
{
    public function __construct(
        #[Assert\PositiveOrZero]
        public ?float $amount = null,
        public DrawdownFrequency $frequency = DrawdownFrequency::Monthly,
        public DrawdownEntryMode $entryMode = DrawdownEntryMode::Gross,
        #[Assert\Date]
        public ?string $startsOn = null,
        #[Assert\Date]
        public ?string $endsOn = null,
        public bool $inflationIndexed = true,
    ) {
    }
}
