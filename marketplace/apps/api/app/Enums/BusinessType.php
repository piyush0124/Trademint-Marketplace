<?php

namespace App\Enums;

enum BusinessType: string
{
    case Manufacturer = 'manufacturer';
    case Wholesaler = 'wholesaler';
    case Retailer = 'retailer';
    case Distributor = 'distributor';
    case ServiceProvider = 'service_provider';
    case Other = 'other';
}
