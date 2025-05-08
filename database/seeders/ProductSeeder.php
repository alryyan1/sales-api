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
            ['name' => 'تلفزيون سامسونج الذكي 55 بوصة Crystal UHD 4K', 'category' => 'TVs'],
            ['name' => 'شاشة إل جي OLED evo C3 مقاس 65 بوصة بدقة 4K', 'category' => 'TVs'],
            ['name' => 'تلفزيون سوني برافيا XR A80L OLED مقاس 77 بوصة', 'category' => 'TVs'],
            ['name' => 'شاشة TCL QLED ذكية 50 بوصة مع Google TV', 'category' => 'TVs'],
            ['name' => 'تلفزيون هايسنس ULED U8K Mini-LED مقاس 75 بوصة', 'category' => 'TVs'],

            // --- Refrigerators ---
            ['name' => 'ثلاجة سامسونج بابين بتقنية SpaceMax™ سعة 650 لتر', 'category' => 'Refrigerators'],
            ['name' => 'ثلاجة إل جي InstaView™ Door-in-Door® سعة 700 لتر', 'category' => 'Refrigerators'],
            ['name' => 'ثلاجة هيتاشي بفريزر علوي سعة 500 لتر لون فضي', 'category' => 'Refrigerators'],
            ['name' => 'ثلاجة بيكو باب واحد صغيرة سعة 150 لتر', 'category' => 'Refrigerators'],
            ['name' => 'فريزر عمودي توشيبا 7 أدراج No Frost', 'category' => 'Refrigerators'],

            // --- Laptops ---
            ['name' => 'لابتوب ديل XPS 15 بمعالج Core i7 وذاكرة 16GB', 'category' => 'Laptops'],
            ['name' => 'لابتوب أبل MacBook Air M2 شريحة 8 أنوية GPU', 'category' => 'Laptops'],
            ['name' => 'لابتوب لينوفو Legion 5 Pro للألعاب RTX 3070', 'category' => 'Laptops'],
            ['name' => 'لابتوب HP Spectre x360 14 بوصة 2 في 1', 'category' => 'Laptops'],
            ['name' => 'لابتوب مايكروسوفت Surface Laptop Go 3', 'category' => 'Laptops'],

            // --- Smartphones ---
            ['name' => 'هاتف أبل iPhone 15 Pro Max سعة 256GB', 'category' => 'Smartphones'],
            ['name' => 'هاتف سامسونج Galaxy S24 Ultra سعة 512GB', 'category' => 'Smartphones'],
            ['name' => 'هاتف جوجل Pixel 8 Pro', 'category' => 'Smartphones'],
            ['name' => 'هاتف شاومي 13T Pro', 'category' => 'Smartphones'],
            ['name' => 'هاتف ون بلس 11', 'category' => 'Smartphones'],

            // --- Washing Machines ---
            ['name' => 'غسالة سامسونج تعبئة أمامية 9 كيلو بتقنية EcoBubble', 'category' => 'Washing Machines'],
            ['name' => 'غسالة إل جي تعبئة علوية 12 كيلو بالبخار', 'category' => 'Washing Machines'],
            ['name' => 'غسالة بوش Serie 6 تعبئة أمامية 8 كيلو', 'category' => 'Washing Machines'],
            ['name' => 'غسالة أريستون حوضين 10 كيلو', 'category' => 'Washing Machines'],
            ['name' => 'غسالة ونشافة إل جي WashTower™', 'category' => 'Washing Machines'],

            // --- Audio ---
            ['name' => 'سماعات سوني WH-1000XM5 بخاصية إلغاء الضوضاء', 'category' => 'Audio'],
            ['name' => 'سماعات أبل AirPods Pro (الجيل الثاني)', 'category' => 'Audio'],
            ['name' => 'مكبر صوت بلوتوث JBL Charge 5', 'category' => 'Audio'],
            ['name' => 'نظام المسرح المنزلي سامسونج HW-Q990C', 'category' => 'Audio'],
            ['name' => 'سماعات Bose QuietComfort Earbuds II', 'category' => 'Audio'],

            // --- Cameras ---
            ['name' => 'كاميرا سوني Alpha a7 IV بدون مرآة (الجسم فقط)', 'category' => 'Cameras'],
            ['name' => 'كاميرا كانون EOS R6 Mark II (الجسم فقط)', 'category' => 'Cameras'],
            ['name' => 'كاميرا نيكون Z 6II (الجسم فقط)', 'category' => 'Cameras'],
            ['name' => 'كاميرا GoPro HERO12 Black', 'category' => 'Cameras'],
            ['name' => 'عدسة سيجما 24-70mm f/2.8 DG DN Art لسوني E-mount', 'category' => 'Cameras'],

            // --- Gaming Consoles ---
            ['name' => 'جهاز سوني PlayStation 5 (إصدار القرص)', 'category' => 'Gaming'],
            ['name' => 'جهاز مايكروسوفت Xbox Series X', 'category' => 'Gaming'],
            ['name' => 'جهاز نينتندو سويتش OLED', 'category' => 'Gaming'],
            ['name' => 'جهاز Steam Deck OLED 512GB', 'category' => 'Gaming'],
            ['name' => 'يد تحكم Xbox لاسلكية - لون أسود', 'category' => 'Gaming Accessories'],

            // --- Printers ---
            ['name' => 'طابعة إبسون EcoTank L3250 واي فاي متعددة الوظائف', 'category' => 'Printers'],
            ['name' => 'طابعة HP LaserJet Pro M404dn ليزر أحادية اللون', 'category' => 'Printers'],
            ['name' => 'طابعة كانون PIXMA G3420 واي فاي حبر مستمر', 'category' => 'Printers'],
            ['name' => 'طابعة Brother ليزر ملونة HL-L3270CDW', 'category' => 'Printers'],
            ['name' => 'طابعة صور محمولة Canon SELPHY CP1500', 'category' => 'Printers'],

            // --- Other Appliances ---
            ['name' => 'ميكروويف سامسونج 40 لتر بالشواية', 'category' => 'Appliances'],
            ['name' => 'مكنسة دايسون V15 Detect Absolute لاسلكية', 'category' => 'Appliances'],
            ['name' => 'مقلاة هوائية فيليبس XXL Airfryer سعة 7.3 لتر', 'category' => 'Appliances'],
            ['name' => 'مكيف سبليت جري 2 طن بارد فقط', 'category' => 'Appliances'],
            ['name' => 'جهاز تنقية الهواء شاومي Mi Air Purifier 4 Pro', 'category' => 'Appliances'],

        ];

        $count = 0;
        foreach ($productsToSeed as $productData) {
            // Check if product with this name already exists to avoid duplicates if seeder runs multiple times
            if (Product::where('name', $productData['name'])->doesntExist()) {
                Product::factory()->create([
                    'name' => $productData['name'],
                    'sku' => 'SKU-' . Str::upper(Str::random(6)) . '-' . $count, // Generate unique SKU
                    'description' => fake()->optional(0.6)->sentence(10), // Add some random description
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