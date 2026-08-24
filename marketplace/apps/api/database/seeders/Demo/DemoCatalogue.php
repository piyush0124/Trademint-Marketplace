<?php

namespace Database\Seeders\Demo;

/**
 * The source data for the demo marketplace.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS IS COMPOSED RATHER THAN LISTED
 * ---------------------------------------------------------------------------
 * Two hundred hand-written listing rows would be unreadable, unmaintainable and
 * impossible to rebalance — changing the category mix would mean rewriting the
 * file. Instead each sector declares small pools (a handful of trading-name
 * stems, offerings, qualifiers) and the seeder composes them. Twelve offerings
 * against eight qualifiers is ninety-six distinct titles per sector, which is
 * far more than any sector needs, so nothing repeats.
 *
 * ---------------------------------------------------------------------------
 * EVERYTHING HERE IS FICTIONAL
 * ---------------------------------------------------------------------------
 * Trading names are invented. Contact details use the reserved `.test` TLD and
 * the 99999xxxxx phone range, neither of which can reach a real person. No real
 * company, person, address or GSTIN appears in this file. See the header of
 * DemoMarketplaceSeeder for the guard that keeps this out of production.
 *
 * ---------------------------------------------------------------------------
 * CATEGORY MIX
 * ---------------------------------------------------------------------------
 * Real Estate is deliberately absent. It exists in the category tree as one
 * sector among many, and seeding it heavily is exactly the impression this
 * dataset is meant to correct. Totals follow the brief and sum to 210, which
 * satisfies "at least 200" while keeping every per-sector figure intact.
 */
final class DemoCatalogue
{
    /**
     * Trading-name stems, shared across sectors and paired with a sector-specific
     * descriptor to produce names like "NorthStar Office Solutions".
     */
    public const NAME_STEMS = [
        'NorthStar', 'CloudBridge', 'UrbanCraft', 'PrimeRoute', 'Vertex',
        'Meridian', 'Ironwood', 'BluePeak', 'Anchorline', 'Summitworks',
        'Copperline', 'Trueline', 'Beacon', 'Kestrel', 'Sandstone',
        'Northgate', 'Silverline', 'Redwood', 'Highfield', 'Larksfield',
        'Greystone', 'Fieldmark', 'Oakridge', 'Brightpath', 'Stonebridge',
    ];

    /**
     * Qualifiers appended to an offering to distinguish listings from the same
     * supplier without inventing a second offering. Kept sector-neutral.
     */
    public const QUALIFIERS = [
        'Commercial Grade', 'Industrial Scale', 'Small Business Package',
        'Enterprise Programme', 'Annual Contract', 'Turnkey Delivery',
        'Bulk Supply', 'Priority Service',
    ];

    /**
     * @return array<string, array{
     *     category: string,
     *     listings: int,
     *     descriptors: list<string>,
     *     offerings: list<string>,
     *     services: list<string>,
     *     business_type: string,
     *     trade_type: string,
     *     unit: string,
     *     price: array{int, int},
     *     summary: string,
     * }>
     */
    public static function sectors(): array
    {
        return [
            'Construction' => [
                'category' => 'construction',
                'listings' => 25,
                'descriptors' => ['Infra Projects', 'Constructions', 'Civil Works', 'Building Solutions', 'Contracting Co'],
                'offerings' => [
                    'Commercial Interior Fit-out', 'RCC Structural Work', 'Industrial Shed Fabrication',
                    'Office Civil Renovation', 'Electrical Wiring and Panels', 'Plumbing and Sanitary Installation',
                    'False Ceiling and Partitions', 'External Painting and Waterproofing',
                    'Warehouse Flooring', 'Site Development and Earthwork',
                    'HVAC Ducting Installation', 'Modular Workstation Installation',
                ],
                'services' => ['Site survey and estimation', 'Turnkey project execution', 'Labour supply', 'Post-handover maintenance'],
                'business_type' => 'service_provider',
                'trade_type' => 'b2b',
                'unit' => 'project',
                'price' => [180000, 9500000],
                'summary' => 'Civil and interior contracting for commercial and industrial sites, with in-house supervision and documented handover.',
            ],

            'Technology' => [
                'category' => 'it-software',
                'listings' => 25,
                'descriptors' => ['Digital Systems', 'Technologies', 'Software Labs', 'Cloud Services', 'IT Solutions'],
                'offerings' => [
                    'Custom Web Application Development', 'Business Website Development',
                    'Mobile App Development', 'ERP Implementation and Rollout',
                    'Cloud Migration and Setup', 'Managed IT Support Retainer',
                    'API Integration Engagement', 'E-commerce Storefront Build',
                    'Data Warehouse and Reporting', 'Cybersecurity Assessment',
                    'DevOps and CI/CD Setup', 'Legacy System Modernisation',
                ],
                'services' => ['Discovery and scoping workshop', 'Sprint-based delivery', 'QA and UAT support', 'Post-launch support retainer'],
                'business_type' => 'service_provider',
                'trade_type' => 'b2b',
                'unit' => 'project',
                'price' => [60000, 4200000],
                'summary' => 'Software delivery for mid-market businesses, covering discovery, build, release and an ongoing support window.',
            ],

            'Professional Services' => [
                'category' => 'professional-services',
                'listings' => 20,
                'descriptors' => ['Advisory', 'Consulting Partners', 'Associates', 'Business Services', 'Advisors LLP'],
                'offerings' => [
                    'Statutory Audit Engagement', 'GST Registration and Filing',
                    'Company Incorporation', 'Payroll Processing Retainer',
                    'Trademark Filing and Prosecution', 'Contract Drafting and Review',
                    'Labour Law Compliance Audit', 'Bookkeeping and MIS Retainer',
                    'Architectural Design and Drawings', 'Internal Controls Review',
                    'Transfer Pricing Documentation', 'Due Diligence Review',
                ],
                'services' => ['Compliance calendar management', 'Filing and representation', 'Advisory retainer', 'Documentation support'],
                'business_type' => 'service_provider',
                'trade_type' => 'b2b',
                'unit' => 'engagement',
                'price' => [15000, 850000],
                'summary' => 'Accounting, legal and compliance support delivered on a retainer or per-engagement basis by qualified practitioners.',
            ],

            'Manufacturing' => [
                'category' => 'manufacturing',
                'listings' => 20,
                'descriptors' => ['Industries', 'Manufacturing Co', 'Fabricators', 'Engineering Works', 'Products Pvt Ltd'],
                'offerings' => [
                    'Sheet Metal Fabrication', 'Injection Moulded Components',
                    'Modular Office Furniture Production', 'CNC Machined Parts',
                    'Corrugated Packaging Manufacture', 'Powder Coating and Finishing',
                    'Industrial Fastener Supply', 'Steel Storage Racking',
                    'Precision Die Casting', 'Rubber and Gasket Moulding',
                    'Wire Harness Assembly', 'Contract Assembly Line',
                ],
                'services' => ['Prototype and tooling', 'Batch production', 'Quality inspection reports', 'Custom specification builds'],
                'business_type' => 'manufacturer',
                'trade_type' => 'b2b',
                'unit' => 'unit',
                'price' => [2500, 1800000],
                'summary' => 'Made-to-order production with tooling support, batch quality reports and scheduled dispatch against purchase orders.',
            ],

            'Logistics' => [
                'category' => 'logistics',
                'listings' => 20,
                'descriptors' => ['Logistics', 'Freight Services', 'Supply Chain', 'Transport Co', 'Cargo Movers'],
                'offerings' => [
                    'Full Truckload Freight', 'Part Load Consignment Movement',
                    'Warehousing and Storage', 'Last Mile Delivery Network',
                    'Cold Chain Transportation', 'Container Drayage and Port Handling',
                    'Bonded Warehouse Storage', 'Reverse Logistics and Returns',
                    'Heavy Machinery Transport', 'Inventory Management Service',
                    'Interstate Fleet Contract', 'Packing and Crating',
                ],
                'services' => ['Route planning', 'Real-time consignment tracking', 'Insurance and documentation', 'Dedicated fleet allocation'],
                'business_type' => 'service_provider',
                'trade_type' => 'b2b',
                'unit' => 'shipment',
                'price' => [8000, 950000],
                'summary' => 'Freight and warehousing across major corridors, with consignment tracking and documented proof of delivery.',
            ],

            'Home Services' => [
                'category' => 'home-services',
                'listings' => 20,
                'descriptors' => ['Home Services', 'Facility Care', 'Servicing Co', 'Maintenance Services', 'Care Solutions'],
                'offerings' => [
                    'Air Conditioner Servicing and Repair', 'Deep Cleaning Service',
                    'Pest Control Treatment', 'Electrical Repair and Rewiring',
                    'Plumbing Repair Visit', 'Appliance Installation',
                    'Carpentry and Furniture Repair', 'Painting and Touch-up',
                    'Water Tank Cleaning', 'Sofa and Upholstery Cleaning',
                    'CCTV Installation', 'Annual Maintenance Contract',
                ],
                'services' => ['Same-day scheduling', 'Trained and background-checked technicians', 'Parts warranty', 'Annual maintenance plans'],
                'business_type' => 'service_provider',
                'trade_type' => 'both',
                'unit' => 'visit',
                'price' => [499, 45000],
                'summary' => 'Scheduled home and office upkeep by trained technicians, with transparent visit pricing and a parts warranty.',
            ],

            'Business Supplies' => [
                'category' => 'office-supplies',
                'listings' => 20,
                'descriptors' => ['Office Solutions', 'Business Supplies', 'Workspace Co', 'Stationers', 'Supply Traders'],
                'offerings' => [
                    'Ergonomic Office Chairs', 'Workstation Desks and Cubicles',
                    'Printer and Toner Supply', 'Housekeeping Consumables',
                    'Corporate Stationery Kits', 'Conference Room AV Equipment',
                    'Laptop and Desktop Procurement', 'Storage Cabinets and Lockers',
                    'Pantry and Breakroom Supplies', 'Signage and Branding Boards',
                    'Networking and Cabling Hardware', 'Safety and First Aid Supplies',
                ],
                'services' => ['Bulk procurement', 'Scheduled replenishment', 'Installation and setup', 'GST invoicing and credit terms'],
                'business_type' => 'wholesaler',
                'trade_type' => 'b2b',
                'unit' => 'unit',
                'price' => [350, 320000],
                'summary' => 'Office procurement with scheduled replenishment, bulk pricing tiers and GST-compliant invoicing on credit terms.',
            ],

            'Marketing' => [
                'category' => 'marketing-advertising',
                'listings' => 15,
                'descriptors' => ['Media', 'Brand Studio', 'Marketing Group', 'Creative Works', 'Advertising Co'],
                'offerings' => [
                    'Performance Marketing Retainer', 'Search Engine Optimisation Programme',
                    'Brand Identity and Guidelines', 'Social Media Management',
                    'Product Photography and Video', 'Outdoor and Print Campaign',
                    'Content Marketing Retainer', 'Marketing Automation Setup',
                    'Trade Show Collateral Design', 'Influencer Campaign Management',
                    'Packaging Design', 'Website Copy and Messaging',
                ],
                'services' => ['Campaign strategy', 'Creative production', 'Media buying', 'Monthly performance reporting'],
                'business_type' => 'service_provider',
                'trade_type' => 'b2b',
                'unit' => 'month',
                'price' => [25000, 1200000],
                'summary' => 'Campaign strategy through to creative production and media buying, reported monthly against agreed targets.',
            ],

            'Events' => [
                'category' => 'events',
                'listings' => 15,
                'descriptors' => ['Events', 'Event Management', 'Experiences', 'Hospitality Events', 'Production House'],
                'offerings' => [
                    'Corporate Conference Management', 'Exhibition Stall Design and Build',
                    'Product Launch Production', 'Corporate Catering',
                    'Audio Visual and Lighting Rental', 'Wedding Planning and Decor',
                    'Team Offsite Management', 'Venue Sourcing and Booking',
                    'Stage and Truss Setup', 'Event Photography and Videography',
                    'Delegate Registration Management', 'Award Night Production',
                ],
                'services' => ['Venue sourcing', 'Vendor coordination', 'On-site event crew', 'Post-event reporting'],
                'business_type' => 'service_provider',
                'trade_type' => 'both',
                'unit' => 'event',
                'price' => [45000, 3500000],
                'summary' => 'End-to-end event delivery covering venue, production, vendors and on-site crew, priced per event.',
            ],

            'Education and Training' => [
                'category' => 'education-training',
                'listings' => 10,
                'descriptors' => ['Learning', 'Training Academy', 'Skills Institute', 'Education Services', 'Development Co'],
                'offerings' => [
                    'Corporate Leadership Training', 'Technical Upskilling Bootcamp',
                    'Workplace Safety Certification', 'Sales Team Enablement Programme',
                    'Compliance and Ethics Training', 'Language and Communication Training',
                    'Campus Recruitment Training', 'Data Literacy Workshop',
                    'Manufacturing Skills Certification', 'Customer Service Training',
                ],
                'services' => ['Training needs analysis', 'Instructor-led delivery', 'Assessment and certification', 'Blended learning content'],
                'business_type' => 'service_provider',
                'trade_type' => 'b2b',
                'unit' => 'seat',
                'price' => [3500, 480000],
                'summary' => 'Instructor-led and blended corporate training with assessment, certification and post-programme reporting.',
            ],

            'Automotive' => [
                'category' => 'automotive',
                'listings' => 10,
                'descriptors' => ['Motors', 'Auto Works', 'Vehicle Services', 'Auto Spares', 'Fleet Care'],
                'offerings' => [
                    'Commercial Fleet Servicing', 'Vehicle Spare Parts Supply',
                    'Tyre Supply and Fitment', 'Fleet Telematics Installation',
                    'Body Repair and Repainting', 'Battery Supply and Replacement',
                    'Annual Fleet Maintenance Contract', 'Vehicle Fabrication and Body Building',
                    'Lubricants and Consumables Supply', 'Roadside Assistance Contract',
                ],
                'services' => ['Fleet health inspection', 'Doorstep servicing', 'Genuine parts sourcing', 'Maintenance contracts'],
                'business_type' => 'distributor',
                'trade_type' => 'both',
                'unit' => 'vehicle',
                'price' => [1200, 650000],
                'summary' => 'Fleet servicing and parts supply with doorstep options, inspection reports and annual maintenance contracts.',
            ],

            'Hospitality' => [
                'category' => 'hospitality',
                'listings' => 10,
                'descriptors' => ['Hospitality', 'Hotel Supplies', 'Guest Services', 'Housekeeping Co', 'Facilities Group'],
                'offerings' => [
                    'Hotel Linen and Housekeeping Supply', 'Contract Housekeeping Staffing',
                    'Commercial Kitchen Equipment', 'Guest Amenities Supply',
                    'Restaurant Interior Fit-out', 'Laundry Services Contract',
                    'Banquet Furniture Rental', 'Food Safety Audit and Training',
                    'Uniform Supply and Laundering', 'Facility Deep Cleaning Contract',
                ],
                'services' => ['Supply contracts', 'Trained hospitality staffing', 'Equipment installation', 'Audit and compliance support'],
                'business_type' => 'wholesaler',
                'trade_type' => 'b2b',
                'unit' => 'contract',
                'price' => [12000, 1400000],
                'summary' => 'Supplies, staffing and equipment for hotels and restaurants, on contract with scheduled replenishment.',
            ],
        ];
    }

    /** Total listings this catalogue describes. */
    public static function totalListings(): int
    {
        return array_sum(array_column(self::sectors(), 'listings'));
    }
}
