<?php

namespace Blax\Shop\Console\Commands;

use Blax\Shop\Enums\ProductStatus;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductAction;
use Blax\Shop\Models\ProductAttribute;
use Blax\Shop\Models\ProductCategory;
use Blax\Shop\Enums\ProductRelationType;
use Illuminate\Console\Command;
use Faker\Factory as Faker;

class ShopAddExampleProducts extends Command
{
    protected $signature = 'shop:add-example-products 
                            {--clean : Remove existing example products first}
                            {--count=3 : Number of products per type}';

    protected $description = 'Adds hotel-themed example products with realistic relationships and cross-sells.';

    /**
     * Available product types in the shop system
     */
    const PRODUCT_TYPES = [
        ProductType::SIMPLE->value => [
            'name' => 'Simple Product',
            'description' => 'A standalone product with no variations (e.g., a book, a service)',
        ],
        ProductType::VARIABLE->value => [
            'name' => 'Variable Product',
            'description' => 'A product with variations/options (e.g., a t-shirt with different sizes and colors)',
        ],
        ProductType::GROUPED->value => [
            'name' => 'Grouped Product',
            'description' => 'A collection of related products sold together (e.g., a product bundle)',
        ],
        ProductType::EXTERNAL->value => [
            'name' => 'External Product',
            'description' => 'A product that links to an external site for purchase',
        ],
        ProductType::BOOKING->value => [
            'name' => 'Booking Product',
            'description' => 'A product that represents a bookable service or appointment',
        ],
        ProductType::POOL->value => [
            'name' => 'Pool Product',
            'description' => 'A product that offers dynamic pricing based on availability (e.g., event tickets)',
        ],
    ];

    protected $faker;
    protected $categories = [];
    protected $createdProducts = [];
    protected $productTypeIndex = [];

    public function handle()
    {
        $this->faker = Faker::create();

        if ($this->option('clean')) {
            $this->cleanExampleProducts();
        }

        $this->info('Creating hotel-themed example products...');
        $this->newLine();

        // Create categories first
        $this->createCategories();

        $count = (int) $this->option('count');
        $totalCreated = 0;

        // Initialize product type index
        foreach (array_keys(self::PRODUCT_TYPES) as $type) {
            $this->productTypeIndex[$type] = 0;
        }

        foreach (self::PRODUCT_TYPES as $type => $details) {
            $this->line("<fg=cyan>Creating {$count} {$details['name']}(s)...</>");

            for ($i = 1; $i <= $count; $i++) {
                $product = $this->createProduct($type, $i);
                $totalCreated++;

                $this->line("  <fg=green>✓</> {$product->name}");
            }

            $this->newLine();
        }

        // Now add relationships (cross-sells, upsells)
        $this->addProductRelationships();

        $this->info("✓ Successfully created {$totalCreated} hotel example products!");
        $this->line("  - Products: {$totalCreated}");
        $this->line("  - Categories: " . count($this->categories));
        $this->newLine();
        $this->info("You can view them in your shop or use them for testing.");
    }

    protected function cleanExampleProducts(): void
    {
        $this->warn('Cleaning existing example products...');

        Product::where('slug', 'like', 'example-%')->delete();
        ProductCategory::where('slug', 'like', 'example-%')->delete();

        $this->info('✓ Cleaned existing example products');
        $this->newLine();
    }

    protected function createCategories(): void
    {
        $categoryNames = [
            'Hotel Rooms' => 'Available room types and accommodations',
            'Room Upgrades' => 'Premium room enhancements and services',
            'Food & Beverage' => 'In-room dining and bar selections',
            'Spa & Wellness' => 'Spa treatments and wellness packages',
            'Parking & Transport' => 'Parking spaces and transportation services',
            'Activities & Tours' => 'Local tours and activities',
        ];

        foreach ($categoryNames as $name => $description) {
            $category = ProductCategory::firstOrCreate(
                ['slug' => 'example-' . \Illuminate\Support\Str::slug($name)],
                [
                    'name' => $name,
                    'description' => $description,
                    'is_visible' => true,
                    'sort_order' => 0,
                    'meta' => json_encode((object)[]),
                ]
            );

            $this->categories[] = $category;
        }
    }

    protected function createProduct(string $type, int $index): Product
    {
        $productData = $this->getProductDataByType($type, $index);
        $productName = $productData['name'];
        $slug = 'example-' . \Illuminate\Support\Str::slug($productName) . '-' . uniqid();

        // Determine pricing
        $baseUnitAmount = $productData['price'];
        $onSale = $productData['on_sale'] ?? false;
        $saleStart = $onSale ? now()->subDays($this->faker->numberBetween(1, 30)) : null;
        $saleEnd = $onSale ? now()->addDays($this->faker->numberBetween(7, 60)) : null;

        $product = Product::create([
            'slug' => $slug,
            'name' => $productName,
            'sku' => $productData['sku'],
            'type' => ProductType::from($type),
            'status' => ProductStatus::PUBLISHED,
            'is_visible' => $productData['visible'] ?? true,
            'featured' => $productData['featured'] ?? false,
            'sale_start' => $saleStart,
            'sale_end' => $saleEnd,
            'manage_stock' => $productData['manage_stock'] ?? ($type !== ProductType::EXTERNAL->value),
            'low_stock_threshold' => $productData['low_stock_threshold'] ?? 5,
            'weight' => $productData['weight'] ?? null,
            'length' => $productData['length'] ?? null,
            'width' => $productData['width'] ?? null,
            'height' => $productData['height'] ?? null,
            'virtual' => $productData['virtual'] ?? false,
            'downloadable' => $productData['downloadable'] ?? false,
            'published_at' => now(),
            'sort_order' => $this->faker->numberBetween(0, 100),
            'tax_class' => 'standard',
            'meta' => [
                'description' => $productData['description'],
                'short_description' => $productData['short_description'],
                'example' => true,
            ],
        ]);

        // Set localized name and descriptions
        $product->setLocalized('name', $productName, null, true);
        $product->setLocalized('short_description', $productData['short_description'], null, true);
        $product->setLocalized('description', $productData['description'], null, true);

        // Add to appropriate categories
        $categoryNames = $productData['categories'] ?? [];
        $matchingCategories = collect($this->categories)->filter(function ($cat) use ($categoryNames) {
            return in_array($cat->name, $categoryNames);
        });
        if ($matchingCategories->isNotEmpty()) {
            $product->categories()->attach($matchingCategories->pluck('id'));
        }

        // Create default price
        $product->prices()->create([
            'name' => 'Default',
            'type' => 'one_time',
            'currency' => 'EUR',
            'unit_amount' => $baseUnitAmount,
            'sale_unit_amount' => $onSale ? (int) round($baseUnitAmount * 0.85) : null,
            'is_default' => true,
            'active' => true,
            'billing_scheme' => 'per_unit',
            'meta' => ['example' => true],
        ]);

        // Add stock if needed
        if ($productData['stock'] ?? 0 > 0) {
            $product->increaseStock($productData['stock']);
        }

        // Add attributes
        if (isset($productData['attributes'])) {
            $this->addAttributesToProduct($product, $productData['attributes']);
        }

        // Store for later relationship building
        $this->createdProducts[$type][] = $product;

        // Handle type-specific creation
        if ($type === ProductType::VARIABLE->value) {
            $this->addVariationsForHotel($product, $productData, $baseUnitAmount);
        } elseif ($type === ProductType::GROUPED->value) {
            $this->addGroupedProductsForHotel($product, $productData);
        } elseif ($type === ProductType::POOL->value) {
            $this->addPoolItemsForHotel($product, $productData);
        } elseif ($type === ProductType::BOOKING->value) {
            // Bookings need stock to be bookable
            if ($product->stock_quantity === 0) {
                $product->increaseStock($productData['stock'] ?? 10);
            }
        }

        return $product;
    }

    protected function getProductDataByType(string $type, int $index): array
    {
        $data = [];

        switch ($type) {
            case ProductType::SIMPLE->value:
                $simpleProducts = [
                    [
                        'name' => 'Premium Wine Bottle - Château Margaux',
                        'sku' => 'WINE-001',
                        'price' => 15000, // €150
                        'description' => 'Exceptional French Bordeaux wine from the renowned Château Margaux estate. Perfect complement to your stay.',
                        'short_description' => 'Premium French Bordeaux wine',
                        'categories' => ['Food & Beverage'],
                        'attributes' => [
                            ['name' => 'Type', 'value' => 'Red Wine'],
                            ['name' => 'Region', 'value' => 'Bordeaux, France'],
                            ['name' => 'Vintage', 'value' => '2015'],
                            ['name' => 'Volume', 'value' => '750ml'],
                        ],
                        'stock' => 12,
                        'featured' => true,
                    ],
                    [
                        'name' => 'Single Malt Whiskey - Lagavulin 16',
                        'sku' => 'WHISK-002',
                        'price' => 12000, // €120
                        'description' => 'Award-winning Islay single malt whiskey with rich, peaty flavors. Delivered to your room with complimentary glassware.',
                        'short_description' => 'Premium Islay single malt whiskey',
                        'categories' => ['Food & Beverage'],
                        'attributes' => [
                            ['name' => 'Type', 'value' => 'Single Malt Whiskey'],
                            ['name' => 'Region', 'value' => 'Islay, Scotland'],
                            ['name' => 'Age', 'value' => '16 Years'],
                            ['name' => 'Volume', 'value' => '700ml'],
                        ],
                        'stock' => 8,
                    ],
                    [
                        'name' => 'Champagne - Dom Pérignon',
                        'sku' => 'CHAMP-003',
                        'price' => 25000, // €250
                        'description' => 'Luxury champagne from the prestigious Dom Pérignon house. Celebrate your special occasion in style.',
                        'short_description' => 'Luxury vintage champagne',
                        'categories' => ['Food & Beverage'],
                        'attributes' => [
                            ['name' => 'Type', 'value' => 'Champagne'],
                            ['name' => 'Region', 'value' => 'Épernay, France'],
                            ['name' => 'Vintage', 'value' => '2012'],
                            ['name' => 'Volume', 'value' => '750ml'],
                        ],
                        'stock' => 6,
                        'featured' => true,
                        'on_sale' => true,
                    ],
                ];
                $data = $simpleProducts[$index - 1] ?? $simpleProducts[0];
                break;

            case ProductType::VARIABLE->value:
                $variableProducts = [
                    [
                        'name' => 'In-Room Breakfast Service',
                        'sku' => 'BREAK-001',
                        'price' => 2500, // €25 base
                        'description' => 'Start your day with a delicious breakfast delivered to your room. Choose from Continental, American, or Full English breakfast options.',
                        'short_description' => 'Breakfast delivered to your room',
                        'categories' => ['Food & Beverage'],
                        'variations' => ['Continental', 'American', 'Full English'],
                        'variation_prices' => [2500, 3200, 3800],
                        'attributes' => [
                            ['name' => 'Service Time', 'value' => '7:00 AM - 11:00 AM'],
                            ['name' => 'Delivery', 'value' => 'Room Service'],
                        ],
                    ],
                    [
                        'name' => 'Spa Treatment Package',
                        'sku' => 'SPA-001',
                        'price' => 8000, // €80 base
                        'description' => 'Relax and rejuvenate with our professional spa treatments. Available in 60, 90, or 120-minute sessions.',
                        'short_description' => 'Professional spa and massage treatments',
                        'categories' => ['Spa & Wellness'],
                        'variations' => ['60 Minutes', '90 Minutes', '120 Minutes'],
                        'variation_prices' => [8000, 11000, 14000],
                        'attributes' => [
                            ['name' => 'Location', 'value' => 'Hotel Spa - 2nd Floor'],
                            ['name' => 'Booking Required', 'value' => 'Yes'],
                        ],
                    ],
                    [
                        'name' => 'Airport Transfer Service',
                        'sku' => 'TRANS-001',
                        'price' => 4500, // €45 base
                        'description' => 'Convenient airport transfer service with professional drivers. Choose your vehicle type for comfort.',
                        'short_description' => 'Professional airport transfer',
                        'categories' => ['Parking & Transport'],
                        'variations' => ['Standard Sedan', 'Luxury Sedan', 'SUV'],
                        'variation_prices' => [4500, 7500, 9500],
                        'attributes' => [
                            ['name' => 'Notice Required', 'value' => '24 hours'],
                            ['name' => 'Distance', 'value' => 'Up to 50km'],
                        ],
                    ],
                ];
                $data = $variableProducts[$index - 1] ?? $variableProducts[0];
                break;

            case ProductType::GROUPED->value:
                $groupedProducts = [
                    [
                        'name' => 'Romantic Package',
                        'sku' => 'PKG-ROM-001',
                        'price' => 0, // Calculated from children
                        'description' => 'Complete romantic experience including champagne, chocolate-covered strawberries, rose petals, and spa voucher.',
                        'short_description' => 'Complete romantic experience package',
                        'categories' => ['Room Upgrades'],
                        'manage_stock' => false,
                        'grouped_items' => [
                            ['name' => 'Champagne Bottle', 'price' => 8000, 'sku' => 'ROM-CHAMP'],
                            ['name' => 'Chocolate Strawberries', 'price' => 3500, 'sku' => 'ROM-STRAW'],
                            ['name' => 'Rose Petal Decoration', 'price' => 4000, 'sku' => 'ROM-ROSE'],
                            ['name' => 'Spa Voucher (€50)', 'price' => 5000, 'sku' => 'ROM-SPA'],
                        ],
                    ],
                    [
                        'name' => 'Business Traveler Package',
                        'sku' => 'PKG-BIZ-001',
                        'price' => 0,
                        'description' => 'Everything a business traveler needs: high-speed WiFi upgrade, printing credits, meeting room hour, and premium coffee.',
                        'short_description' => 'Essential business traveler amenities',
                        'categories' => ['Room Upgrades'],
                        'manage_stock' => false,
                        'grouped_items' => [
                            ['name' => 'Premium WiFi (100Mbps)', 'price' => 1500, 'sku' => 'BIZ-WIFI'],
                            ['name' => 'Printing Credits (50 pages)', 'price' => 1000, 'sku' => 'BIZ-PRINT'],
                            ['name' => 'Meeting Room (1 hour)', 'price' => 5000, 'sku' => 'BIZ-MEET'],
                            ['name' => 'Premium Coffee Service', 'price' => 2000, 'sku' => 'BIZ-COFFEE'],
                        ],
                    ],
                    [
                        'name' => 'Family Fun Package',
                        'sku' => 'PKG-FAM-001',
                        'price' => 0,
                        'description' => 'Family-friendly package with kids activities, snacks, games, and access to family entertainment.',
                        'short_description' => 'Complete family entertainment package',
                        'categories' => ['Room Upgrades'],
                        'manage_stock' => false,
                        'grouped_items' => [
                            ['name' => 'Kids Activity Book Set', 'price' => 1500, 'sku' => 'FAM-BOOK'],
                            ['name' => 'Snack Box', 'price' => 2500, 'sku' => 'FAM-SNACK'],
                            ['name' => 'Board Games Collection', 'price' => 3000, 'sku' => 'FAM-GAMES'],
                            ['name' => 'Pool & Playroom Access', 'price' => 4000, 'sku' => 'FAM-ACCESS'],
                        ],
                    ],
                ];
                $data = $groupedProducts[$index - 1] ?? $groupedProducts[0];
                break;

            case ProductType::EXTERNAL->value:
                $externalProducts = [
                    [
                        'name' => 'City Tour Bus Tickets',
                        'sku' => 'EXT-TOUR-001',
                        'price' => 3500, // €35
                        'description' => 'Hop-on hop-off city tour tickets. Book through our partner for the best rates. External booking required.',
                        'short_description' => 'Hop-on hop-off city tour',
                        'categories' => ['Activities & Tours'],
                        'manage_stock' => false,
                        'attributes' => [
                            ['name' => 'External URL', 'value' => 'https://citytours.example.com'],
                            ['name' => 'Duration', 'value' => '24 hours unlimited'],
                            ['name' => 'Provider', 'value' => 'City Tours Inc.'],
                        ],
                    ],
                    [
                        'name' => 'Museum Pass (3-Day)',
                        'sku' => 'EXT-MUS-001',
                        'price' => 4500, // €45
                        'description' => 'Access to all major museums for 3 days. Purchase through official museum portal with our special hotel rate.',
                        'short_description' => '3-day all-museum access pass',
                        'categories' => ['Activities & Tours'],
                        'manage_stock' => false,
                        'attributes' => [
                            ['name' => 'External URL', 'value' => 'https://museumpass.example.com'],
                            ['name' => 'Validity', 'value' => '3 consecutive days'],
                            ['name' => 'Museums Included', 'value' => '15+ venues'],
                        ],
                    ],
                    [
                        'name' => 'Theater Show Tickets',
                        'sku' => 'EXT-SHOW-001',
                        'price' => 8500, // €85
                        'description' => 'Premium theater show tickets for the best productions in town. Booked via our entertainment partner.',
                        'short_description' => 'Premium theater tickets',
                        'categories' => ['Activities & Tours'],
                        'manage_stock' => false,
                        'attributes' => [
                            ['name' => 'External URL', 'value' => 'https://theater.example.com'],
                            ['name' => 'Seating', 'value' => 'Premium seats'],
                            ['name' => 'Provider', 'value' => 'City Theater Box Office'],
                        ],
                    ],
                ];
                $data = $externalProducts[$index - 1] ?? $externalProducts[0];
                break;

            case ProductType::BOOKING->value:
                $bookingProducts = [
                    [
                        'name' => 'Standard Double Room',
                        'sku' => 'ROOM-STD-001',
                        'price' => 12000, // €120/night
                        'description' => 'Comfortable double room with modern amenities, city view, private bathroom, and complimentary WiFi. Perfect for couples or solo travelers.',
                        'short_description' => 'Comfortable double room with city view',
                        'categories' => ['Hotel Rooms'],
                        'stock' => 15,
                        'manage_stock' => true,
                        'featured' => true,
                        'attributes' => [
                            ['name' => 'Bed Type', 'value' => 'Queen Bed'],
                            ['name' => 'Room Size', 'value' => '25 m²'],
                            ['name' => 'View', 'value' => 'City View'],
                            ['name' => 'Max Guests', 'value' => '2'],
                        ],
                    ],
                    [
                        'name' => 'Deluxe Suite',
                        'sku' => 'ROOM-DLX-001',
                        'price' => 22000, // €220/night
                        'description' => 'Spacious suite with separate living area, king bed, luxurious bathroom with jacuzzi, and panoramic city views. Includes access to executive lounge.',
                        'short_description' => 'Luxury suite with panoramic views',
                        'categories' => ['Hotel Rooms'],
                        'stock' => 8,
                        'manage_stock' => true,
                        'featured' => true,
                        'attributes' => [
                            ['name' => 'Bed Type', 'value' => 'King Bed'],
                            ['name' => 'Room Size', 'value' => '45 m²'],
                            ['name' => 'View', 'value' => 'Panoramic City View'],
                            ['name' => 'Max Guests', 'value' => '2-3'],
                            ['name' => 'Special Features', 'value' => 'Jacuzzi, Executive Lounge Access'],
                        ],
                    ],
                    [
                        'name' => 'Presidential Suite',
                        'sku' => 'ROOM-PRES-001',
                        'price' => 45000, // €450/night
                        'description' => 'Ultimate luxury accommodation with 2 bedrooms, private terrace, premium butler service, and exclusive amenities. The pinnacle of comfort and elegance.',
                        'short_description' => 'Ultimate luxury presidential suite',
                        'categories' => ['Hotel Rooms'],
                        'stock' => 2,
                        'manage_stock' => true,
                        'featured' => true,
                        'attributes' => [
                            ['name' => 'Bedrooms', 'value' => '2 (King + Queen)'],
                            ['name' => 'Room Size', 'value' => '120 m²'],
                            ['name' => 'View', 'value' => '360° City View + Terrace'],
                            ['name' => 'Max Guests', 'value' => '4-6'],
                            ['name' => 'Special Features', 'value' => 'Butler Service, Private Terrace, Premium Bar'],
                        ],
                    ],
                ];
                $data = $bookingProducts[$index - 1] ?? $bookingProducts[0];
                break;

            case ProductType::POOL->value:
                $poolProducts = [
                    [
                        'name' => 'Parking Spaces - North Garage',
                        'sku' => 'PARK-NORTH-POOL',
                        'price' => 2500, // €25/day base
                        'description' => 'Secure covered parking in our North Garage. Multiple spots available with 24/7 surveillance and easy hotel access.',
                        'short_description' => 'Secure North Garage parking',
                        'categories' => ['Parking & Transport'],
                        'visible' => true,
                        'manage_stock' => true,
                        'pool_items' => [
                            ['name' => 'Spot A3', 'stock' => 1, 'price' => 2500],
                            ['name' => 'Spot A7', 'stock' => 1, 'price' => 2500],
                            ['name' => 'Spot B12', 'stock' => 1, 'price' => 2800],
                            ['name' => 'Spot C5', 'stock' => 1, 'price' => 2800],
                            ['name' => 'Spot D9', 'stock' => 1, 'price' => 3000],
                        ],
                    ],
                    [
                        'name' => 'Parking Spaces - South Area',
                        'sku' => 'PARK-SOUTH-POOL',
                        'price' => 2000, // €20/day base
                        'description' => 'Open-air parking in our South Area. Well-lit and monitored spaces near the main entrance.',
                        'short_description' => 'Outdoor South Area parking',
                        'categories' => ['Parking & Transport'],
                        'visible' => true,
                        'manage_stock' => true,
                        'pool_items' => [
                            ['name' => 'South Area Zone 1', 'stock' => 5, 'price' => 2000],
                            ['name' => 'South Area Zone 2', 'stock' => 5, 'price' => 2000],
                            ['name' => 'South Area Zone 3', 'stock' => 5, 'price' => 1800],
                        ],
                    ],
                    [
                        'name' => 'VIP Parking Spaces - Underground',
                        'sku' => 'PARK-VIP-POOL',
                        'price' => 4000, // €40/day base
                        'description' => 'Premium underground parking with direct elevator access to hotel lobby. Reserved for suite guests and VIP members.',
                        'short_description' => 'Premium underground VIP parking',
                        'categories' => ['Parking & Transport'],
                        'visible' => true,
                        'manage_stock' => true,
                        'featured' => true,
                        'pool_items' => [
                            ['name' => 'VIP-1', 'stock' => 1, 'price' => 5000],
                            ['name' => 'VIP-2', 'stock' => 1, 'price' => 5000],
                            ['name' => 'VIP-3', 'stock' => 1, 'price' => 4500],
                            ['name' => 'VIP-4', 'stock' => 1, 'price' => 4500],
                            ['name' => 'Executive E1', 'stock' => 1, 'price' => 4000],
                            ['name' => 'Executive E2', 'stock' => 1, 'price' => 4000],
                        ],
                    ],
                ];
                $data = $poolProducts[$index - 1] ?? $poolProducts[0];
                break;
        }

        return $data;
    }

    protected function addAttributesToProduct(Product $product, array $attributes): void
    {
        foreach ($attributes as $index => $attr) {
            ProductAttribute::create([
                'product_id' => $product->id,
                'key' => $attr['name'],
                'value' => $attr['value'],
                'sort_order' => $index,
                'meta' => json_encode((object)[]),
            ]);
        }
    }

    protected function addVariationsForHotel(Product $product, array $productData, int $basePrice): void
    {
        if (!isset($productData['variations'])) {
            return;
        }

        $variations = $productData['variations'];
        $prices = $productData['variation_prices'] ?? [];

        foreach ($variations as $index => $variation) {
            $variationProduct = Product::create([
                'slug' => $product->slug . '-' . \Illuminate\Support\Str::slug($variation),
                'sku' => $product->sku . '-' . strtoupper(substr($variation, 0, 3)),
                'type' => 'simple',
                'parent_id' => $product->id,
                'status' => 'published',
                'is_visible' => false,
                'manage_stock' => true,
                'published_at' => now(),
                'meta' => ['variation' => $variation, 'example' => true],
            ]);

            $variationProduct->setLocalized('name', ($product->getLocalized('name') ?: 'Product') . ' - ' . $variation, null, true);

            $variationAmount = $prices[$index] ?? ($basePrice + ($index * 500));
            $variationProduct->prices()->create([
                'name' => 'Default',
                'type' => 'one_time',
                'currency' => 'EUR',
                'unit_amount' => $variationAmount,
                'is_default' => true,
                'active' => true,
                'billing_scheme' => 'per_unit',
                'meta' => ['example' => true, 'variation' => $variation],
            ]);

            ProductAttribute::create([
                'product_id' => $variationProduct->id,
                'key' => 'Option',
                'value' => $variation,
                'sort_order' => 0,
                'meta' => null,
            ]);
        }
    }

    protected function addGroupedProductsForHotel(Product $product, array $productData): void
    {
        if (!isset($productData['grouped_items'])) {
            return;
        }

        foreach ($productData['grouped_items'] as $i => $item) {
            $childProduct = Product::create([
                'slug' => $product->slug . '-item-' . ($i + 1),
                'sku' => $item['sku'],
                'type' => 'simple',
                'parent_id' => $product->id,
                'status' => 'published',
                'is_visible' => false,
                'manage_stock' => true,
                'published_at' => now(),
                'meta' => ['grouped_item' => true, 'example' => true],
            ]);

            $childProduct->setLocalized('name', $item['name'], null, true);

            $childProduct->prices()->create([
                'name' => 'Default',
                'type' => 'one_time',
                'currency' => 'EUR',
                'unit_amount' => $item['price'],
                'is_default' => true,
                'active' => true,
                'billing_scheme' => 'per_unit',
                'meta' => ['example' => true, 'grouped_item' => true],
            ]);
        }
    }

    protected function addPoolItemsForHotel(Product $pool, array $productData): void
    {
        if (!isset($productData['pool_items'])) {
            return;
        }

        $parkingIds = [];
        foreach ($productData['pool_items'] as $i => $item) {
            $parking = Product::create([
                'slug' => $pool->slug . '-' . \Illuminate\Support\Str::slug($item['name']),
                'name' => $item['name'],
                'sku' => $pool->sku . '-' . str_pad($i + 1, 2, '0', STR_PAD_LEFT),
                'type' => ProductType::BOOKING,
                'status' => ProductStatus::PUBLISHED,
                'is_visible' => false,
                'manage_stock' => true,
                'parent_id' => $pool->id, // Set pool as parent so it's not counted as a parent product
                'published_at' => now(),
                'meta' => ['example' => true, 'pool_item' => true, 'parent_pool' => $pool->name],
            ]);

            // Set stock for the parking spot
            $parking->increaseStock($item['stock']);

            // Create price for individual parking spot
            $parking->prices()->create([
                'name' => 'Default',
                'type' => 'one_time',
                'currency' => 'EUR',
                'unit_amount' => $item['price'],
                'is_default' => true,
                'active' => true,
                'billing_scheme' => 'per_unit',
                'meta' => ['example' => true],
            ]);

            $parkingIds[] = $parking->id;
        }

        // Attach all parking spots to the pool
        $pool->attachSingleItems($parkingIds);
    }

    protected function addProductRelationships(): void
    {
        $this->info('Adding product relationships (cross-sells, upsells)...');

        // Get rooms
        $rooms = $this->createdProducts[ProductType::BOOKING->value] ?? [];
        
        // Get simple products (beverages)
        $beverages = $this->createdProducts[ProductType::SIMPLE->value] ?? [];
        
        // Get parking pools
        $parkingPools = $this->createdProducts[ProductType::POOL->value] ?? [];

        // Add cross-sells to each room (beverages and parking)
        foreach ($rooms as $room) {
            // Add all beverages as cross-sell
            foreach ($beverages as $beverage) {
                // Use syncWithoutDetaching to avoid duplicate constraint violations
                $room->productRelations()->syncWithoutDetaching([
                    $beverage->id => ['type' => ProductRelationType::CROSS_SELL->value]
                ]);
            }

            // Add all parking pools as cross-sell
            foreach ($parkingPools as $parking) {
                $room->productRelations()->syncWithoutDetaching([
                    $parking->id => ['type' => ProductRelationType::CROSS_SELL->value]
                ]);
            }
        }

        // Add upsells: Standard -> Deluxe -> Presidential
        if (count($rooms) >= 2) {
            // Standard room can upsell to Deluxe
            $rooms[0]->productRelations()->syncWithoutDetaching([
                $rooms[1]->id => ['type' => ProductRelationType::UPSELL->value]
            ]);
            
            if (count($rooms) >= 3) {
                // Standard can also upsell to Presidential
                $rooms[0]->productRelations()->syncWithoutDetaching([
                    $rooms[2]->id => ['type' => ProductRelationType::UPSELL->value]
                ]);
                
                // Deluxe can upsell to Presidential
                $rooms[1]->productRelations()->syncWithoutDetaching([
                    $rooms[2]->id => ['type' => ProductRelationType::UPSELL->value]
                ]);
            }
        }

        $this->line('  <fg=green>✓</> Cross-sells and upsells added');
    }

    protected function generateProductName(string $type): string
    {
        // This method is deprecated - kept for compatibility
        return 'Example Product';
    }
}
