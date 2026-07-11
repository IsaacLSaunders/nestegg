<?php

declare(strict_types=1);

namespace App\Projection;

use App\Enum\AccountType;

interface TaxModel
{
    public function netFromGross(AccountType $type, float $gross, float $gainsFraction): float;

    public function grossFromNet(AccountType $type, float $net, float $gainsFraction): float;
}
