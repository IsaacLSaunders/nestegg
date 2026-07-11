<?php

declare(strict_types=1);

namespace App\Enum;

enum DrawdownFrequency: string
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';
}
