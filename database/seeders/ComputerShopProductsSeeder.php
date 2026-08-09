<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use Illuminate\Database\Seeder;

class ComputerShopProductsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            'Desktop Computers' => Category::firstOrCreate(['name' => 'Desktop Computers']),
            'Laptops' => Category::firstOrCreate(['name' => 'Laptops']),
            'Components' => Category::firstOrCreate(['name' => 'Components']),
            'Accessories' => Category::firstOrCreate(['name' => 'Accessories']),
            'Networking' => Category::firstOrCreate(['name' => 'Networking']),
        ];

        $pieceUnit = Unit::firstOrCreate(['name' => 'Piece'], ['is_active' => true]);
        $meterUnit = Unit::firstOrCreate(['name' => 'Meter'], ['is_active' => true]);

        // --- 10th Gen Intel Core Desktop & Laptop bundles ---
        // CPU key => [label, cores/threads, base clock]
        $cpus = [
            'i3' => 'Core i3-10100 (4C/8T, 3.6GHz)',
            'i5' => 'Core i5-10400 (6C/12T, 2.9GHz)',
            'i7' => 'Core i7-10700 (8C/16T, 2.9GHz)',
        ];

        // Configuration tiers: RAM (GB) + storage description
        $configs = [
            ['ram' => 8, 'storage' => '256GB SSD', 'suffix' => '8-256SSD'],
            ['ram' => 16, 'storage' => '512GB SSD', 'suffix' => '16-512SSD'],
            ['ram' => 32, 'storage' => '512GB SSD + 1TB HDD', 'suffix' => '32-512SSD-1TBHDD'],
        ];

        // Base prices (SDG) per CPU tier, scaled up per config tier below.
        $desktopBasePrice = ['i3' => 380000, 'i5' => 520000, 'i7' => 720000];
        $laptopBasePrice = ['i3' => 480000, 'i5' => 650000, 'i7' => 890000];
        $tierMarkup = [0 => 1.0, 1 => 1.25, 2 => 1.55]; // scale price up for higher RAM/storage tiers

        $products = [];

        foreach ($cpus as $cpuKey => $cpuLabel) {
            foreach ($configs as $tierIndex => $config) {
                // Desktop bundle
                $desktopPrice = (int) round($desktopBasePrice[$cpuKey] * $tierMarkup[$tierIndex], -3);
                $products[] = [
                    'name' => "HP Desktop {$cpuLabel} / {$config['ram']}GB RAM / {$config['storage']}",
                    'sku' => "PC-DT-{$cpuKey}-{$config['suffix']}",
                    'description' => "Desktop PC - Intel {$cpuLabel}, 10th Generation, {$config['ram']}GB DDR4 RAM, {$config['storage']}",
                    'category' => 'Desktop Computers',
                    'sale_price' => $desktopPrice,
                    'cost_price' => (int) round($desktopPrice / 1.15, -3),
                ];

                // Laptop bundle
                $laptopPrice = (int) round($laptopBasePrice[$cpuKey] * $tierMarkup[$tierIndex], -3);
                $products[] = [
                    'name' => "Laptop {$cpuLabel} / {$config['ram']}GB RAM / {$config['storage']}",
                    'sku' => "PC-LT-{$cpuKey}-{$config['suffix']}",
                    'description' => "Laptop - Intel {$cpuLabel}, 10th Generation, {$config['ram']}GB DDR4 RAM, {$config['storage']}",
                    'category' => 'Laptops',
                    'sale_price' => $laptopPrice,
                    'cost_price' => (int) round($laptopPrice / 1.15, -3),
                ];
            }
        }

        // --- Components ---
        $products = array_merge($products, [
            ['name' => 'SSD 256GB SATA III', 'sku' => 'CMP-SSD-256', 'description' => 'Solid State Drive 256GB, SATA III 2.5"', 'category' => 'Components', 'sale_price' => 32000, 'cost_price' => 26000],
            ['name' => 'SSD 512GB SATA III', 'sku' => 'CMP-SSD-512', 'description' => 'Solid State Drive 512GB, SATA III 2.5"', 'category' => 'Components', 'sale_price' => 55000, 'cost_price' => 45000],
            ['name' => 'SSD 1TB NVMe M.2', 'sku' => 'CMP-SSD-1TB-NVME', 'description' => 'NVMe M.2 Solid State Drive 1TB', 'category' => 'Components', 'sale_price' => 95000, 'cost_price' => 80000],
            ['name' => 'HDD 1TB 7200RPM', 'sku' => 'CMP-HDD-1TB', 'description' => 'Internal Hard Disk Drive 1TB, 7200RPM', 'category' => 'Components', 'sale_price' => 38000, 'cost_price' => 30000],
            ['name' => 'HDD 2TB 7200RPM', 'sku' => 'CMP-HDD-2TB', 'description' => 'Internal Hard Disk Drive 2TB, 7200RPM', 'category' => 'Components', 'sale_price' => 65000, 'cost_price' => 53000],
            ['name' => 'RAM DDR4 8GB 2666MHz', 'sku' => 'CMP-RAM-8-DDR4', 'description' => 'DDR4 Desktop Memory Module 8GB 2666MHz', 'category' => 'Components', 'sale_price' => 28000, 'cost_price' => 22000],
            ['name' => 'RAM DDR4 16GB 2666MHz', 'sku' => 'CMP-RAM-16-DDR4', 'description' => 'DDR4 Desktop Memory Module 16GB 2666MHz', 'category' => 'Components', 'sale_price' => 52000, 'cost_price' => 42000],
            ['name' => 'Motherboard H410M', 'sku' => 'CMP-MB-H410', 'description' => 'Intel H410 Chipset Motherboard, LGA1200 (10th Gen)', 'category' => 'Components', 'sale_price' => 85000, 'cost_price' => 68000],
            ['name' => 'Motherboard B460M', 'sku' => 'CMP-MB-B460', 'description' => 'Intel B460 Chipset Motherboard, LGA1200 (10th Gen)', 'category' => 'Components', 'sale_price' => 110000, 'cost_price' => 90000],
            ['name' => 'Power Supply Unit 500W', 'sku' => 'CMP-PSU-500', 'description' => 'ATX Power Supply Unit 500W', 'category' => 'Components', 'sale_price' => 45000, 'cost_price' => 36000],
            ['name' => 'Mid Tower Case ATX', 'sku' => 'CMP-CASE-ATX', 'description' => 'ATX Mid Tower Computer Case', 'category' => 'Components', 'sale_price' => 55000, 'cost_price' => 44000],
            ['name' => 'CPU Air Cooler', 'sku' => 'CMP-COOLER-AIR', 'description' => 'Air CPU Cooler with Heatsink and Fan', 'category' => 'Components', 'sale_price' => 25000, 'cost_price' => 19000],
        ]);

        // --- Accessories ---
        $products = array_merge($products, [
            ['name' => 'USB Wired Keyboard', 'sku' => 'ACC-KB-USB', 'description' => 'Standard USB Wired Keyboard', 'category' => 'Accessories', 'sale_price' => 12000, 'cost_price' => 8000],
            ['name' => 'Wireless Keyboard', 'sku' => 'ACC-KB-WL', 'description' => 'Wireless Keyboard with USB Receiver', 'category' => 'Accessories', 'sale_price' => 22000, 'cost_price' => 16000],
            ['name' => 'USB Optical Mouse', 'sku' => 'ACC-MS-USB', 'description' => 'Standard USB Wired Optical Mouse', 'category' => 'Accessories', 'sale_price' => 8000, 'cost_price' => 5000],
            ['name' => 'Wireless Mouse', 'sku' => 'ACC-MS-WL', 'description' => 'Wireless Optical Mouse with USB Receiver', 'category' => 'Accessories', 'sale_price' => 15000, 'cost_price' => 10000],
            ['name' => 'Keyboard & Mouse Combo', 'sku' => 'ACC-KBMS-COMBO', 'description' => 'Wired Keyboard and Mouse Combo Set', 'category' => 'Accessories', 'sale_price' => 18000, 'cost_price' => 12000],
            ['name' => 'LED Monitor 19"', 'sku' => 'ACC-MON-19', 'description' => 'LED Monitor 19 inch, HD', 'category' => 'Accessories', 'sale_price' => 95000, 'cost_price' => 78000],
            ['name' => 'LED Monitor 24"', 'sku' => 'ACC-MON-24', 'description' => 'LED Monitor 24 inch, Full HD', 'category' => 'Accessories', 'sale_price' => 165000, 'cost_price' => 135000],
            ['name' => 'Mouse Pad', 'sku' => 'ACC-MOUSEPAD', 'description' => 'Standard Mouse Pad', 'category' => 'Accessories', 'sale_price' => 3000, 'cost_price' => 1500],
            ['name' => 'USB Flash Drive 32GB', 'sku' => 'ACC-USB-32GB', 'description' => 'USB 3.0 Flash Drive 32GB', 'category' => 'Accessories', 'sale_price' => 12000, 'cost_price' => 8000],
            ['name' => 'USB Flash Drive 64GB', 'sku' => 'ACC-USB-64GB', 'description' => 'USB 3.0 Flash Drive 64GB', 'category' => 'Accessories', 'sale_price' => 18000, 'cost_price' => 12000],
            ['name' => 'USB Web Camera HD', 'sku' => 'ACC-WEBCAM-HD', 'description' => 'USB HD Webcam with Built-in Microphone', 'category' => 'Accessories', 'sale_price' => 28000, 'cost_price' => 20000],
            ['name' => 'USB Speakers 2.0', 'sku' => 'ACC-SPKR-20', 'description' => 'USB Powered Desktop Speakers 2.0', 'category' => 'Accessories', 'sale_price' => 20000, 'cost_price' => 14000],
            ['name' => 'Laptop Bag 15.6"', 'sku' => 'ACC-BAG-156', 'description' => 'Laptop Carry Bag, fits up to 15.6 inch', 'category' => 'Accessories', 'sale_price' => 25000, 'cost_price' => 17000],
            ['name' => 'UPS 650VA', 'sku' => 'ACC-UPS-650', 'description' => 'Uninterruptible Power Supply 650VA', 'category' => 'Accessories', 'sale_price' => 75000, 'cost_price' => 60000],
            ['name' => 'Power Extension Strip 4-Way', 'sku' => 'ACC-PWR-STRIP4', 'description' => '4-Way Power Extension Strip with Surge Protection', 'category' => 'Accessories', 'sale_price' => 15000, 'cost_price' => 10000],
        ]);

        // --- Networking ---
        $products = array_merge($products, [
            ['name' => 'CAT6 Network Cable', 'sku' => 'NET-CAT6-CABLE', 'description' => 'CAT6 UTP Ethernet Cable, sold per meter', 'category' => 'Networking', 'sale_price' => 1500, 'cost_price' => 900, 'unit' => 'meter'],
            ['name' => 'RJ45 Connector (Pack of 100)', 'sku' => 'NET-RJ45-100PK', 'description' => 'RJ45 Crimp Connectors, pack of 100 pieces', 'category' => 'Networking', 'sale_price' => 12000, 'cost_price' => 8000],
            ['name' => 'Network Switch 8-Port', 'sku' => 'NET-SW-8PORT', 'description' => '8-Port 10/100Mbps Ethernet Switch', 'category' => 'Networking', 'sale_price' => 35000, 'cost_price' => 26000],
            ['name' => 'Network Switch 16-Port', 'sku' => 'NET-SW-16PORT', 'description' => '16-Port 10/100Mbps Ethernet Switch', 'category' => 'Networking', 'sale_price' => 65000, 'cost_price' => 50000],
            ['name' => 'Wireless Router', 'sku' => 'NET-ROUTER-WL', 'description' => 'Wireless N Router, 4 LAN Ports', 'category' => 'Networking', 'sale_price' => 45000, 'cost_price' => 34000],
            ['name' => 'Network Cable Tester', 'sku' => 'NET-CABLE-TESTER', 'description' => 'RJ45/RJ11 Network Cable Tester', 'category' => 'Networking', 'sale_price' => 18000, 'cost_price' => 12000],
            ['name' => 'PCIe Wireless Network Card', 'sku' => 'NET-PCIE-WIFI', 'description' => 'PCIe Wi-Fi Network Adapter Card', 'category' => 'Networking', 'sale_price' => 22000, 'cost_price' => 15000],
        ]);

        $count = 0;
        foreach ($products as $item) {
            if (Product::where('sku', $item['sku'])->exists()) {
                continue;
            }

            $unit = ($item['unit'] ?? null) === 'meter' ? $meterUnit : $pieceUnit;

            Product::create([
                'name' => $item['name'],
                'sku' => $item['sku'],
                'description' => $item['description'],
                'category_id' => $categories[$item['category']]->id,
                'stocking_unit_id' => $unit->id,
                'sellable_unit_id' => $unit->id,
                'units_per_stocking_unit' => 1,
                'stock_alert_level' => 5,
                'has_expiry_date' => false,
                'sale_price' => $item['sale_price'],
                'cost_price' => $item['cost_price'],
                'preferred_currency' => 'SDG',
            ]);

            $count++;
        }

        $this->command->info("Seeded {$count} computer shop products.");
    }
}
