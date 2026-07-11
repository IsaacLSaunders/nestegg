<?php

declare(strict_types=1);

namespace App\Projection;

use App\Enum\AccountType;

final readonly class FlatRateTaxModel implements TaxModel
{
    public function __construct(
        private float $ordinaryIncomeTaxRate,
        private float $capitalGainsTaxRate,
    ) {
    }

    public function netFromGross(AccountType $type, float $gross, float $gainsFraction): float
    {
        return $gross * (1 - $this->effectiveRate($type, $gainsFraction));
    }

    public function grossFromNet(AccountType $type, float $net, float $gainsFraction): float
    {
        $rate = $this->effectiveRate($type, $gainsFraction);
        if ($rate >= 1.0) {
            return \INF;
        }

        return $net / (1 - $rate);
    }

    private function effectiveRate(AccountType $type, float $gainsFraction): float
    {
        return match ($type) {
            AccountType::Traditional401k, AccountType::TraditionalIra => $this->ordinaryIncomeTaxRate,
            AccountType::Brokerage => $gainsFraction * $this->capitalGainsTaxRate,
            AccountType::Roth401k, AccountType::RothIra, AccountType::Plan529, AccountType::Cash => 0.0,
        };
    }
}
