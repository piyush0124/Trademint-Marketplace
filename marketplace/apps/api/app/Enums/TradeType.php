<?php

namespace App\Enums;

enum TradeType: string
{
    case B2B = 'b2b';
    case B2C = 'b2c';
    case Both = 'both';
}
