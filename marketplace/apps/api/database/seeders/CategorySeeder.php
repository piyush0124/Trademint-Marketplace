<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\CategoryAttributeDefinition;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $realEstate = Category::updateOrCreate(
            ['slug' => 'real_estate'],
            [
                'name' => 'Real Estate',
                'description' => 'Properties for sale and rent',
                'is_active' => true,
                'sort_order' => 1,
            ]
        );

        $attributes = [
            [
                'key' => 'property_type',
                'label' => 'Property Type',
                'type' => 'select',
                'options' => ['apartment', 'house', 'villa', 'plot', 'commercial'],
                'is_required' => true,
                'is_filterable' => true,
                'sort_order' => 1,
            ],
            [
                'key' => 'listing_type',
                'label' => 'Listing Type',
                'type' => 'select',
                'options' => ['sale', 'rent'],
                'is_required' => true,
                'is_filterable' => true,
                'sort_order' => 2,
            ],
            [
                'key' => 'bedrooms',
                'label' => 'Bedrooms',
                'type' => 'integer',
                'options' => null,
                'is_required' => false,
                'is_filterable' => true,
                'sort_order' => 3,
            ],
            [
                'key' => 'bathrooms',
                'label' => 'Bathrooms',
                'type' => 'integer',
                'options' => null,
                'is_required' => false,
                'is_filterable' => false,
                'sort_order' => 4,
            ],
            [
                'key' => 'area_sqft',
                'label' => 'Area (sq ft)',
                'type' => 'decimal',
                'options' => null,
                'is_required' => false,
                'is_filterable' => true,
                'sort_order' => 5,
            ],
            [
                'key' => 'furnished',
                'label' => 'Furnished',
                'type' => 'boolean',
                'options' => null,
                'is_required' => false,
                'is_filterable' => true,
                'sort_order' => 6,
            ],
        ];

        foreach ($attributes as $attribute) {
            CategoryAttributeDefinition::updateOrCreate(
                [
                    'category_id' => $realEstate->id,
                    'key' => $attribute['key'],
                ],
                $attribute
            );
        }

        Category::updateOrCreate(
            ['slug' => 'cars'],
            [
                'name' => 'Cars',
                'description' => 'Vehicles for sale (coming soon)',
                'is_active' => false,
                'sort_order' => 2,
            ]
        );

        $this->seedIndustries();
    }

    /**
     * The marketplace is general purpose, not a real-estate site: Real Estate is
     * one category among many. This seeds a starting set of industries and
     * subcategories so the requirement, matching and quotation flows can be used
     * across sectors out of the box.
     *
     * This is data, not logic — nothing in the application branches on these
     * slugs, and an admin can add, rename or deactivate any of them. The
     * real-estate attribute definitions above stay where they belong: attached to
     * that one category as an optional, category-specific module.
     */
    private function seedIndustries(): void
    {
        $industries = [
            'construction' => ['Construction', ['Civil Construction', 'Interior Fit-out', 'Electrical', 'Plumbing']],
            'manufacturing' => ['Manufacturing', ['Furniture', 'Metal Products', 'Machinery', 'Packaging Materials']],
            'industrial-products' => ['Industrial Products', ['Raw Materials', 'Tools & Equipment', 'Safety Supplies']],
            'wholesale' => ['Wholesale', ['Bulk Goods', 'Distribution']],
            'retail' => ['Retail', ['Store Supplies', 'Point of Sale']],
            'it-software' => ['IT & Software', ['Web Development', 'Mobile App Development', 'Cloud Services', 'IT Support']],
            'digital-services' => ['Digital Services', ['UI/UX Design', 'Content Production', 'Data Services']],
            'marketing-advertising' => ['Marketing & Advertising', ['Digital Marketing', 'Branding', 'Print & Outdoor']],
            'consulting' => ['Consulting', ['Business Consulting', 'Financial Consulting', 'Compliance']],
            'professional-services' => ['Professional Services', ['Legal Services', 'Accounting', 'Architecture']],
            'education-training' => ['Education & Training', ['Corporate Training', 'Skill Development']],
            'healthcare-services' => ['Healthcare Services', ['Diagnostics', 'Medical Equipment', 'Facility Staffing']],
            'logistics' => ['Logistics', ['Freight', 'Warehousing', 'Last Mile Delivery']],
            'transportation' => ['Transportation', ['Passenger Transport', 'Fleet Services']],
            'events' => ['Events', ['Event Management', 'Catering', 'Audio Visual']],
            'hospitality' => ['Hospitality', ['Hotel Supplies', 'Housekeeping Services']],
            'home-services' => ['Home Services', ['Cleaning', 'Appliance Installation', 'Pest Control']],
            'repair-maintenance' => ['Repair & Maintenance', ['Equipment Repair', 'Annual Maintenance Contracts']],
            'agriculture' => ['Agriculture', ['Farm Inputs', 'Farm Equipment', 'Produce']],
            'automotive' => ['Automotive', ['Spare Parts', 'Vehicle Servicing']],
            'office-supplies' => ['Office Supplies', ['Furniture', 'Stationery', 'IT Peripherals']],
            'equipment-rental' => ['Equipment Rental', ['Construction Equipment', 'Event Equipment']],
            'security-services' => ['Security Services', ['Manned Guarding', 'Surveillance Systems']],
            'facility-management' => ['Facility Management', ['Integrated Facility Services', 'Landscaping']],
        ];

        // Real Estate keeps sort_order 1; everything else follows it.
        $sortOrder = 10;

        foreach ($industries as $slug => [$name, $subcategories]) {
            $parent = Category::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'is_active' => true,
                    'sort_order' => $sortOrder,
                ]
            );

            $childSortOrder = 1;

            foreach ($subcategories as $subcategory) {
                Category::updateOrCreate(
                    ['slug' => $slug.'-'.Str::slug($subcategory)],
                    [
                        'parent_id' => $parent->id,
                        'name' => $subcategory,
                        'is_active' => true,
                        'sort_order' => $childSortOrder,
                    ]
                );

                $childSortOrder++;
            }

            $sortOrder++;
        }
    }
}
