<?php

namespace App\Services\Catalogue;

/**
 * The starting taxonomy for a South African general marketplace, and the spec definitions for the categories that
 * need them. Data only; App\Console\Commands\SeedCategories applies it idempotently (by slug) and never runs by itself.
 *
 * Attribute rows: [key, label, type (text|number|enum|boolean), unit, options, facetable, required]
 */
class Taxonomy
{
    /**
     * @return array<string, array{name: string, children: array<string, string>}>
     */
    public static function categories(): array
    {
        return [
            'electronics-computers' => ['name' => 'Electronics and computers', 'children' => [
                'laptops' => 'Laptops', 'desktops' => 'Desktops and all-in-ones', 'tablets' => 'Tablets', 'monitors' => 'Monitors',
                'computer-components' => 'Components and storage', 'networking' => 'Networking and routers', 'printers-scanners' => 'Printers and scanners',
                'tv-audio-video' => 'TV, audio and video', 'cameras' => 'Cameras and drones', 'gaming' => 'Gaming', 'power-solar' => 'Power backup and solar',
            ]],
            'phones' => ['name' => 'Phones and accessories', 'children' => [
                'smartphones' => 'Smartphones', 'feature-phones' => 'Feature phones', 'phone-cases' => 'Cases and screen protectors',
                'chargers-cables' => 'Chargers and cables', 'smartwatches' => 'Smartwatches and wearables', 'headphones' => 'Headphones and earbuds',
            ]],
            'home-kitchen' => ['name' => 'Home and kitchen', 'children' => [
                'cookware' => 'Cookware and bakeware', 'small-kitchen-appliances' => 'Small kitchen appliances', 'dinnerware' => 'Dinnerware and cutlery',
                'bedding-bath' => 'Bedding and bath', 'home-decor' => 'Home decor', 'cleaning-laundry' => 'Cleaning and laundry', 'storage-organisation' => 'Storage and organisation',
                'lighting' => 'Lighting',
            ]],
            'appliances' => ['name' => 'Appliances', 'children' => [
                'fridges-freezers' => 'Fridges and freezers', 'washing-machines' => 'Washing machines and dryers', 'stoves-ovens' => 'Stoves and ovens',
                'dishwashers' => 'Dishwashers', 'heating-cooling' => 'Heaters, fans and air conditioners', 'vacuum-cleaners' => 'Vacuum cleaners', 'water-heating' => 'Geysers and water heating',
            ]],
            'furniture' => ['name' => 'Furniture', 'children' => [
                'living-room' => 'Living room', 'bedroom' => 'Bedroom', 'dining-room' => 'Dining room', 'office-furniture' => 'Office furniture', 'outdoor-furniture' => 'Outdoor furniture',
            ]],
            'garden-outdoor' => ['name' => 'Garden and outdoor', 'children' => [
                'garden-tools' => 'Garden tools', 'plants-seeds' => 'Plants and seeds', 'braai-outdoor-cooking' => 'Braai and outdoor cooking', 'pools-pool-care' => 'Pools and pool care',
                'irrigation' => 'Irrigation and hosepipes', 'pest-control' => 'Pest control',
            ]],
            'diy-tools' => ['name' => 'DIY and tools', 'children' => [
                'power-tools' => 'Power tools', 'hand-tools' => 'Hand tools', 'building-materials' => 'Building materials', 'paint-decorating' => 'Paint and decorating',
                'plumbing-electrical' => 'Plumbing and electrical', 'safety-security' => 'Safety and security',
            ]],
            'fashion-women' => ['name' => 'Fashion: women', 'children' => [
                'women-clothing' => 'Clothing', 'women-shoes' => 'Shoes', 'women-bags' => 'Bags and accessories', 'women-jewellery' => 'Jewellery and watches',
            ]],
            'fashion-men' => ['name' => 'Fashion: men', 'children' => [
                'men-clothing' => 'Clothing', 'men-shoes' => 'Shoes', 'men-bags' => 'Bags and accessories', 'men-watches' => 'Watches',
            ]],
            'fashion-kids' => ['name' => 'Fashion: kids', 'children' => [
                'kids-clothing' => 'Clothing', 'kids-shoes' => 'Shoes', 'school-uniforms' => 'School uniforms',
            ]],
            'beauty' => ['name' => 'Beauty', 'children' => [
                'skincare' => 'Skincare', 'haircare' => 'Haircare', 'makeup' => 'Makeup', 'fragrance' => 'Fragrance', 'grooming' => 'Grooming and shaving',
            ]],
            'health' => ['name' => 'Health and wellness', 'children' => [
                'vitamins-supplements' => 'Vitamins and supplements', 'personal-care' => 'Personal care', 'first-aid' => 'First aid', 'fitness-nutrition' => 'Sports nutrition',
            ]],
            'baby' => ['name' => 'Baby', 'children' => [
                'nappies-wipes' => 'Nappies and wipes', 'baby-feeding' => 'Feeding', 'prams-car-seats' => 'Prams and car seats', 'nursery' => 'Nursery', 'baby-clothing' => 'Baby clothing',
            ]],
            'toys-games' => ['name' => 'Toys and games', 'children' => [
                'toys' => 'Toys', 'board-games-puzzles' => 'Board games and puzzles', 'educational-toys' => 'Educational toys', 'outdoor-play' => 'Outdoor play',
            ]],
            'sports-outdoor' => ['name' => 'Sports and outdoor', 'children' => [
                'camping-hiking' => 'Camping and hiking', 'cycling' => 'Cycling', 'fitness-equipment' => 'Fitness equipment', 'team-sports' => 'Team sports', 'fishing' => 'Fishing',
            ]],
            'automotive' => ['name' => 'Automotive', 'children' => [
                'car-parts' => 'Car parts', 'car-care' => 'Car care', 'car-electronics' => 'Car electronics', 'motorcycle' => 'Motorcycle gear', 'tyres-wheels' => 'Tyres and wheels',
            ]],
            'books-stationery' => ['name' => 'Books and stationery', 'children' => [
                'books' => 'Books', 'stationery' => 'Stationery', 'textbooks' => 'Textbooks',
            ]],
            'pets' => ['name' => 'Pets', 'children' => [
                'dog' => 'Dogs', 'cat' => 'Cats', 'birds-small-pets' => 'Birds and small pets', 'fish-aquariums' => 'Fish and aquariums',
            ]],
            'groceries-pantry' => ['name' => 'Groceries and pantry', 'children' => [
                'pantry' => 'Pantry staples', 'snacks-sweets' => 'Snacks and sweets', 'beverages' => 'Beverages', 'biltong-dried' => 'Biltong and dried goods', 'household-supplies' => 'Household supplies',
            ]],
            'office-school' => ['name' => 'Office and school', 'children' => [
                'school-supplies' => 'School supplies', 'office-supplies' => 'Office supplies', 'school-bags' => 'School bags and lunch boxes', 'office-electronics' => 'Office electronics',
            ]],
            'art-crafts' => ['name' => 'Art and crafts', 'children' => [
                'art-supplies' => 'Art supplies', 'craft-supplies' => 'Craft and sewing', 'handmade' => 'Handmade goods', 'party-supplies' => 'Party and gift supplies',
            ]],
            'farm-agri' => ['name' => 'Farm and agri supplies', 'children' => [
                'livestock-feed' => 'Livestock feed', 'farm-equipment' => 'Farm equipment', 'fencing-gates' => 'Fencing and gates', 'seeds-fertiliser' => 'Seeds and fertiliser', 'animal-health' => 'Animal health',
            ]],
        ];
    }

    /**
     * Attribute definitions by category slug. A definition on a parent is inherited by every child.
     *
     * @return array<string, array<int, array{0: string, 1: string, 2: string, 3: ?string, 4: ?array, 5: bool, 6: bool}>>
     */
    public static function attributes(): array
    {
        $size = ['XS', 'S', 'M', 'L', 'XL', 'XXL'];
        $colour = ['Black', 'White', 'Grey', 'Blue', 'Red', 'Green', 'Brown', 'Pink', 'Yellow', 'Orange', 'Purple', 'Beige', 'Multicolour'];
        $material = ['Cotton', 'Polyester', 'Leather', 'Denim', 'Wool', 'Linen', 'Synthetic', 'Other'];

        return [
            'laptops' => [
                ['ram', 'Memory (RAM)', 'number', 'GB', null, true, false],
                ['storage', 'Storage', 'number', 'GB', null, true, false],
                ['storage_type', 'Storage type', 'enum', null, ['SSD', 'HDD', 'eMMC'], true, false],
                ['processor', 'Processor', 'text', null, null, true, false],
                ['screen_size', 'Screen size', 'number', 'inch', null, true, false],
                ['operating_system', 'Operating system', 'enum', null, ['Windows', 'macOS', 'ChromeOS', 'Linux', 'None'], true, false],
            ],
            'desktops' => [
                ['ram', 'Memory (RAM)', 'number', 'GB', null, true, false],
                ['storage', 'Storage', 'number', 'GB', null, true, false],
                ['processor', 'Processor', 'text', null, null, true, false],
            ],
            'tablets' => [
                ['storage', 'Storage', 'number', 'GB', null, true, false],
                ['ram', 'Memory (RAM)', 'number', 'GB', null, true, false],
                ['screen_size', 'Screen size', 'number', 'inch', null, true, false],
                ['connectivity', 'Connectivity', 'enum', null, ['Wi-Fi', 'Wi-Fi + Cellular'], true, false],
            ],
            'monitors' => [
                ['screen_size', 'Screen size', 'number', 'inch', null, true, false],
                ['resolution', 'Resolution', 'enum', null, ['HD', 'Full HD', '2K', '4K'], true, false],
                ['refresh_rate', 'Refresh rate', 'number', 'Hz', null, true, false],
            ],
            'smartphones' => [
                ['storage', 'Storage', 'number', 'GB', null, true, false],
                ['ram', 'Memory (RAM)', 'number', 'GB', null, true, false],
                ['screen_size', 'Screen size', 'number', 'inch', null, true, false],
                ['network', 'Network', 'enum', null, ['4G', '5G'], true, false],
                ['dual_sim', 'Dual SIM', 'boolean', null, null, true, false],
            ],
            'tv-audio-video' => [
                ['screen_size', 'Screen size', 'number', 'inch', null, true, false],
                ['resolution', 'Resolution', 'enum', null, ['HD', 'Full HD', '4K', '8K'], true, false],
            ],
            'fridges-freezers' => [
                ['capacity', 'Capacity', 'number', 'litres', null, true, false],
                ['energy_rating', 'Energy rating', 'enum', null, ['A', 'B', 'C', 'D'], true, false],
            ],
            'washing-machines' => [
                ['capacity', 'Load capacity', 'number', 'kg', null, true, false],
                ['loading', 'Loading', 'enum', null, ['Front', 'Top'], true, false],
            ],
            'women-clothing' => [
                ['size', 'Size', 'enum', null, $size, true, false],
                ['colour', 'Colour', 'enum', null, $colour, true, false],
                ['material', 'Material', 'enum', null, $material, true, false],
            ],
            'men-clothing' => [
                ['size', 'Size', 'enum', null, $size, true, false],
                ['colour', 'Colour', 'enum', null, $colour, true, false],
                ['material', 'Material', 'enum', null, $material, true, false],
            ],
            'kids-clothing' => [
                ['size', 'Age or size', 'text', null, null, true, false],
                ['colour', 'Colour', 'enum', null, $colour, true, false],
                ['material', 'Material', 'enum', null, $material, true, false],
            ],
            'women-shoes' => [['shoe_size', 'Shoe size (UK)', 'number', null, null, true, false], ['colour', 'Colour', 'enum', null, $colour, true, false]],
            'men-shoes' => [['shoe_size', 'Shoe size (UK)', 'number', null, null, true, false], ['colour', 'Colour', 'enum', null, $colour, true, false]],
            'kids-shoes' => [['shoe_size', 'Shoe size (UK)', 'number', null, null, true, false], ['colour', 'Colour', 'enum', null, $colour, true, false]],
            'furniture' => [
                ['colour', 'Colour', 'enum', null, $colour, true, false],
                ['material', 'Material', 'text', null, null, true, false],
                ['assembly_required', 'Assembly required', 'boolean', null, null, true, false],
            ],
            'books' => [
                ['author', 'Author', 'text', null, null, false, false],
                ['isbn', 'ISBN', 'text', null, null, false, false],
                ['language', 'Language', 'enum', null, ['English', 'Afrikaans', 'isiZulu', 'isiXhosa', 'Sesotho', 'Other'], true, false],
            ],
            'groceries-pantry' => [
                ['pack_size', 'Pack size', 'text', null, null, false, false],
                ['halal', 'Halal', 'boolean', null, null, true, false],
            ],
            'power-tools' => [
                ['power_source', 'Power source', 'enum', null, ['Mains', 'Battery', 'Petrol'], true, false],
                ['voltage', 'Voltage', 'number', 'V', null, true, false],
            ],
            'power-solar' => [
                ['capacity_wh', 'Capacity', 'number', 'Wh', null, true, false],
                ['output_w', 'Output', 'number', 'W', null, true, false],
            ],
        ];
    }
}
