<?php

declare(strict_types=1);

namespace App\Enum;

enum DrawdownEntryMode: string
{
    case Gross = 'gross';
    case Net = 'net';
}
