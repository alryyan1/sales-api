<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportBankTotalTest extends TestCase
{
    use RefreshDatabase;

    public function test_monthly_revenue_report_includes_bank_transfer_in_bank_total()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $now = Carbon::now();
        $year = $now->year;
        $month = $now->month;

        // Create a sale with various payment methods
        $sale = Sale::factory()->create([
            'sale_date' => $now->toDateString(),
            'total_amount' => 1000,
        ]);

        // Cash payment
        Payment::factory()->create([
            'sale_id' => $sale->id,
            'amount' => 100,
            'method' => 'cash',
            'payment_date' => $now,
        ]);

        // Bank transfer payment (should be included in bank total)
        Payment::factory()->create([
            'sale_id' => $sale->id,
            'amount' => 200,
            'method' => 'bank_transfer',
            'payment_date' => $now,
        ]);

        // Visa payment (not counted as "bank" — tracked separately, still in total_paid)
        Payment::factory()->create([
            'sale_id' => $sale->id,
            'amount' => 300,
            'method' => 'visa',
            'payment_date' => $now,
        ]);

        $response = $this->getJson("/api/reports/monthly-revenue?month={$month}&year={$year}");

        $response->assertStatus(200);
        $data = $response->json('data');

        // Total bank should be 200 (bank_transfer)
        $this->assertEquals(200, $data['month_summary']['total_bank']);

        // Check daily breakdown
        $todayStr = $now->toDateString();
        $dailyEntry = collect($data['daily_breakdown'])->firstWhere('date', $todayStr);
        $this->assertEquals(200, $dailyEntry['total_bank']);

        // --- Verify Returns ---
        $saleReturn = \App\Models\SaleReturn::create([
            'user_id' => $user->id,
            'sale_id' => $sale->id,
            'returned_payment_method' => 'cash',
        ]);

        \App\Models\SaleReturnItem::create([
            'sale_return_id' => $saleReturn->id,
            'product_id' => \App\Models\Product::factory()->create()->id,
            'quantity' => 2,
            'price' => 50, // Total 100
        ]);

        $response = $this->getJson("/api/reports/monthly-revenue?month={$month}&year={$year}");
        $data = $response->json('data');
        $dailyEntry = collect($data['daily_breakdown'])->firstWhere('date', $todayStr);

        $this->assertEquals(100, $dailyEntry['total_returns']);
        $this->assertEquals(100, $data['month_summary']['total_returns']);

        // Net = total_paid (600) - total_expense (0) = 600
        $this->assertEquals(600, $dailyEntry['net']);
    }
}
