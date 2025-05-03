<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str; // For SKU generation

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        
        // --- Product Seeding Logic ---
        $this->command->info('Seeding Products...');

        $productsToSeed = [
            // --- TVs ---
            ['name' => 'تلفزيون سامسونج الذكي 55 بوصة Crystal UHD 4K', 'category' => 'TVs', 'base_purchase' => 1500, 'base_sale' => 2200],
            ['name' => 'شاشة إل جي OLED evo C3 مقاس 65 بوصة بدقة 4K', 'category' => 'TVs', 'base_purchase' => 4500, 'base_sale' => 6500],
            ['name' => 'تلفزيون سوني برافيا XR A80L OLED مقاس 77 بوصة', 'category' => 'TVs', 'base_purchase' => 9000, 'base_sale' => 12500],
            ['name' => 'شاشة TCL QLED ذكية 50 بوصة مع Google TV', 'category' => 'TVs', 'base_purchase' => 1200, 'base_sale' => 1800],
            ['name' => 'تلفزيون هايسنس ULED U8K Mini-LED مقاس 75 بوصة', 'category' => 'TVs', 'base_purchase' => 5500, 'base_sale' => 7800],

            // --- Refrigerators ---
            ['name' => 'ثلاجة سامسونج بابين بتقنية SpaceMax™ سعة 650 لتر', 'category' => 'Refrigerators', 'base_purchase' => 3200, 'base_sale' => 4500],
            ['name' => 'ثلاجة إل جي InstaView™ Door-in-Door® سعة 700 لتر', 'category' => 'Refrigerators', 'base_purchase' => 5000, 'base_sale' => 7200],
            ['name' => 'ثلاجة هيتاشي بفريزر علوي سعة 500 لتر لون فضي', 'category' => 'Refrigerators', 'base_purchase' => 2500, 'base_sale' => 3500],
            ['name' => 'ثلاجة بيكو باب واحد صغيرة سعة 150 لتر', 'category' => 'Refrigerators', 'base_purchase' => 800, 'base_sale' => 1200],
            ['name' => 'فريزر عمودي توشيبا 7 أدراج No Frost', 'category' => 'Refrigerators', 'base_purchase' => 2800, 'base_sale' => 3900],

            // --- Laptops ---
            ['name' => 'لابتوب ديل XPS 15 بمعالج Core i7 وذاكرة 16GB', 'category' => 'Laptops', 'base_purchase' => 5500, 'base_sale' => 7500],
            ['name' => 'لابتوب أبل MacBook Air M2 شريحة 8 أنوية GPU', 'category' => 'Laptops', 'base_purchase' => 4800, 'base_sale' => 6200],
            ['name' => 'لابتوب لينوفو Legion 5 Pro للألعاب RTX 3070', 'category' => 'Laptops', 'base_purchase' => 6500, 'base_sale' => 8800],
            ['name' => 'لابتوب HP Spectre x360 14 بوصة 2 في 1', 'category' => 'Laptops', 'base_purchase' => 5200, 'base_sale' => 7000],
            ['name' => 'لابتوب مايكروسوفت Surface Laptop Go 3', 'category' => 'Laptops', 'base_purchase' => 3000, 'base_sale' => 4200],

            // --- Smartphones ---
            ['name' => 'هاتف أبل iPhone 15 Pro Max سعة 256GB', 'category' => 'Smartphones', 'base_purchase' => 4500, 'base_sale' => 5800],
            ['name' => 'هاتف سامسونج Galaxy S24 Ultra سعة 512GB', 'category' => 'Smartphones', 'base_purchase' => 4800, 'base_sale' => 6100],
            ['name' => 'هاتف جوجل Pixel 8 Pro', 'category' => 'Smartphones', 'base_purchase' => 3500, 'base_sale' => 4800],
            ['name' => 'هاتف شاومي 13T Pro', 'category' => 'Smartphones', 'base_purchase' => 2500, 'base_sale' => 3500],
            ['name' => 'هاتف ون بلس 11', 'category' => 'Smartphones', 'base_purchase' => 2800, 'base_sale' => 3900],

            // --- Washing Machines ---
            ['name' => 'غسالة سامسونج تعبئة أمامية 9 كيلو بتقنية EcoBubble', 'category' => 'Washing Machines', 'base_purchase' => 2200, 'base_sale' => 3100],
            ['name' => 'غسالة إل جي تعبئة علوية 12 كيلو بالبخار', 'category' => 'Washing Machines', 'base_purchase' => 2500, 'base_sale' => 3500],
            ['name' => 'غسالة بوش Serie 6 تعبئة أمامية 8 كيلو', 'category' => 'Washing Machines', 'base_purchase' => 2800, 'base_sale' => 3900],
            ['name' => 'غسالة أريستون حوضين 10 كيلو', 'category' => 'Washing Machines', 'base_purchase' => 1500, 'base_sale' => 2100],
            ['name' => 'غسالة ونشافة إل جي WashTower™', 'category' => 'Washing Machines', 'base_purchase' => 6000, 'base_sale' => 8500],

            // --- Audio ---
            ['name' => 'سماعات سوني WH-1000XM5 بخاصية إلغاء الضوضاء', 'category' => 'Audio', 'base_purchase' => 1200, 'base_sale' => 1600],
            ['name' => 'سماعات أبل AirPods Pro (الجيل الثاني)', 'category' => 'Audio', 'base_purchase' => 800, 'base_sale' => 1100],
            ['name' => 'مكبر صوت بلوتوث JBL Charge 5', 'category' => 'Audio', 'base_purchase' => 500, 'base_sale' => 750],
            ['name' => 'نظام المسرح المنزلي سامسونج HW-Q990C', 'category' => 'Audio', 'base_purchase' => 4000, 'base_sale' => 5500],
            ['name' => 'سماعات Bose QuietComfort Earbuds II', 'category' => 'Audio', 'base_purchase' => 900, 'base_sale' => 1300],

            // --- Cameras ---
            ['name' => 'كاميرا سوني Alpha a7 IV بدون مرآة (الجسم فقط)', 'category' => 'Cameras', 'base_purchase' => 8500, 'base_sale' => 11000],
            ['name' => 'كاميرا كانون EOS R6 Mark II (الجسم فقط)', 'category' => 'Cameras', 'base_purchase' => 9000, 'base_sale' => 11800],
            ['name' => 'كاميرا نيكون Z 6II (الجسم فقط)', 'category' => 'Cameras', 'base_purchase' => 7000, 'base_sale' => 9500],
            ['name' => 'كاميرا GoPro HERO12 Black', 'category' => 'Cameras', 'base_purchase' => 1500, 'base_sale' => 2000],
            ['name' => 'عدسة سيجما 24-70mm f/2.8 DG DN Art لسوني E-mount', 'category' => 'Cameras', 'base_purchase' => 4000, 'base_sale' => 5200],

            // --- Gaming Consoles ---
            ['name' => 'جهاز سوني PlayStation 5 (إصدار القرص)', 'category' => 'Gaming', 'base_purchase' => 2000, 'base_sale' => 2500],
            ['name' => 'جهاز مايكروسوفت Xbox Series X', 'category' => 'Gaming', 'base_purchase' => 1900, 'base_sale' => 2400],
            ['name' => 'جهاز نينتندو سويتش OLED', 'category' => 'Gaming', 'base_purchase' => 1300, 'base_sale' => 1700],
            ['name' => 'جهاز Steam Deck OLED 512GB', 'category' => 'Gaming', 'base_purchase' => 2500, 'base_sale' => 3200],
            ['name' => 'يد تحكم Xbox لاسلكية - لون أسود', 'category' => 'Gaming Accessories', 'base_purchase' => 200, 'base_sale' => 280],

            // --- Printers ---
            ['name' => 'طابعة إبسون EcoTank L3250 واي فاي متعددة الوظائف', 'category' => 'Printers', 'base_purchase' => 600, 'base_sale' => 850],
            ['name' => 'طابعة HP LaserJet Pro M404dn ليزر أحادية اللون', 'category' => 'Printers', 'base_purchase' => 800, 'base_sale' => 1100],
            ['name' => 'طابعة كانون PIXMA G3420 واي فاي حبر مستمر', 'category' => 'Printers', 'base_purchase' => 550, 'base_sale' => 780],
            ['name' => 'طابعة Brother ليزر ملونة HL-L3270CDW', 'category' => 'Printers', 'base_purchase' => 1200, 'base_sale' => 1600],
            ['name' => 'طابعة صور محمولة Canon SELPHY CP1500', 'category' => 'Printers', 'base_purchase' => 450, 'base_sale' => 650],

            // --- Other Appliances ---
            ['name' => 'ميكروويف سامسونج 40 لتر بالشواية', 'category' => 'Appliances', 'base_purchase' => 500, 'base_sale' => 700],
            ['name' => 'مكنسة دايسون V15 Detect Absolute لاسلكية', 'category' => 'Appliances', 'base_purchase' => 2500, 'base_sale' => 3300],
            ['name' => 'مقلاة هوائية فيليبس XXL Airfryer سعة 7.3 لتر', 'category' => 'Appliances', 'base_purchase' => 800, 'base_sale' => 1150],
            ['name' => 'مكيف سبليت جري 2 طن بارد فقط', 'category' => 'Appliances', 'base_purchase' => 2800, 'base_sale' => 3800],
            ['name' => 'جهاز تنقية الهواء شاومي Mi Air Purifier 4 Pro', 'category' => 'Appliances', 'base_purchase' => 700, 'base_sale' => 1000],

        ];

        $count = 0;
        foreach ($productsToSeed as $productData) {
            // Check if product with this name already exists to avoid duplicates if seeder runs multiple times
            if (Product::where('name', $productData['name'])->doesntExist()) {
                Product::factory()->create([
                    'name' => $productData['name'],
                    'sku' => 'SKU-' . Str::upper(Str::random(6)) . '-' . $count, // Generate unique SKU
                    'description' => fake()->optional(0.6)->sentence(10), // Add some random description
                    'purchase_price' => $productData['base_purchase'] * fake()->randomFloat(2, 0.9, 1.1), // Slight variation
                    'sale_price' => $productData['base_sale'] * fake()->randomFloat(2, 0.95, 1.2), // Slight variation
                    'stock_quantity' => fake()->numberBetween(5, 250), // Initial stock
                    'stock_alert_level' => fake()->optional(0.9, 10)->numberBetween(5, 25),
                    // Add category logic here if you implement categories
                ]);
                $count++;
            }
            // Break if you only want exactly 50, even if some names are skipped
            // if ($count >= 50) break;
        }
        $this->command->info("Seeded {$count} products.");

        // Add more using factory if needed to reach 50
        $remaining = 50 - $count;
        if ($remaining > 0) {
            Product::factory()->count($remaining)->create();
            $this->command->info("Seeded {$remaining} additional random products using factory.");
        }

    }
}
