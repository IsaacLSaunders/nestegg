<?php

declare(strict_types=1);

namespace App\Enum;

enum AccountType: string
{
    case Traditional401k = 'traditional_401k';
    case Roth401k = 'roth_401k';
    case TraditionalIra = 'traditional_ira';
    case RothIra = 'roth_ira';
    case Brokerage = 'brokerage';
    case Plan529 = 'plan_529';
    case Cash = 'cash';
}
