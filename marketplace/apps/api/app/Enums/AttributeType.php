<?php

namespace App\Enums;

enum AttributeType: string
{
    case String = 'string';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Boolean = 'boolean';
    case Select = 'select';
}
