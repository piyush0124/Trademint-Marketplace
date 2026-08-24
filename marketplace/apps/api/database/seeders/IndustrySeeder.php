<?php

namespace Database\Seeders;

use App\Models\Industry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * A small starter set of business verticals — generic and PAN-India
 * applicable, not tied to any city or region. Admin can add more later.
 */
class IndustrySeeder extends Seeder
{
    public function run(): void
    {
        $industries = [
            'Textiles & Garments',
            'Electronics',
            'Food & Beverage',
            'Construction Materials',
            'Automotive',
            'Furniture',
            'Packaging',
            'Chemicals',
            'Agriculture',
            'IT & Software Services',
            'Logistics & Transportation',
            'Healthcare & Pharma',
        ];

        foreach ($industries as $name) {
            Industry::firstOrCreate(['slug' => Str::slug($name)], ['name' => $name]);
        }
    }
}
