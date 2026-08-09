<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SaleExportToFinanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create());
    }

    private function fakeFirestore(int $status = 200): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'fake-token'], 200),
            'firestore.googleapis.com/*' => Http::response(['error' => 'boom'], $status),
        ]);
    }

    /** @return \Illuminate\Support\Collection<int, \Illuminate\Http\Client\Request> */
    private function firestoreRequests()
    {
        return collect(Http::recorded())
            ->map(fn ($pair) => $pair[0])
            ->filter(fn ($request) => str_contains($request->url(), 'firestore.googleapis.com'));
    }

    private function makeSale(): Sale
    {
        $warehouse = Warehouse::factory()->create();
        $client = Client::factory()->create(['name' => 'Ahmed Ali', 'phone' => '99887766']);
        $product = Product::factory()->create();

        $sale = Sale::create([
            'warehouse_id' => $warehouse->id,
            'client_id' => $client->id,
            'sale_date' => now()->format('Y-m-d'),
            'discount_amount' => 0,
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price' => 50,
            'cost_price_at_sale' => 30,
            'total_price' => 100,
        ]);

        return $sale->fresh();
    }

    /** @test */
    public function it_exports_a_sale_and_writes_a_firestore_document()
    {
        $this->fakeFirestore();
        $sale = $this->makeSale();

        $response = $this->postJson("/api/sales/{$sale->id}/export-to-finance");

        $response->assertStatus(200);
        $this->assertNotNull($response->json('finance_exported_at'));

        Http::assertSent(fn ($request) => str_contains($request->url(), "journal_entries/sale_{$sale->id}")
            && $request->method() === 'PATCH');

        $sale->refresh();
        $this->assertNotNull($sale->finance_exported_at);
        $this->assertNull($sale->finance_export_error);
    }

    /** @test */
    public function it_picks_the_receivable_role_when_unpaid()
    {
        $this->fakeFirestore();
        $sale = $this->makeSale();

        $this->postJson("/api/sales/{$sale->id}/export-to-finance")->assertStatus(200);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'firestore.googleapis.com')) {
                return false;
            }
            $fields = $request->data()['fields']['lines']['arrayValue']['values'][0]['mapValue']['fields'];

            return $fields['account_role']['stringValue'] === 'sales_receivable';
        });
    }

    /** @test */
    public function it_picks_the_cash_role_when_fully_paid_in_cash()
    {
        $this->fakeFirestore();
        $sale = $this->makeSale();
        Payment::create([
            'sale_id' => $sale->id,
            'method' => 'cash',
            'amount' => 100,
            'payment_date' => now()->format('Y-m-d'),
        ]);

        $this->postJson("/api/sales/{$sale->id}/export-to-finance")->assertStatus(200);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'firestore.googleapis.com')) {
                return false;
            }
            $fields = $request->data()['fields']['lines']['arrayValue']['values'][0]['mapValue']['fields'];

            return $fields['account_role']['stringValue'] === 'sales_cash';
        });
    }

    /** @test */
    public function it_picks_the_bank_role_when_fully_paid_electronically()
    {
        $this->fakeFirestore();
        $sale = $this->makeSale();
        Payment::create(['sale_id' => $sale->id, 'method' => 'bankak', 'amount' => 100, 'payment_date' => now()->format('Y-m-d')]);

        $this->postJson("/api/sales/{$sale->id}/export-to-finance")->assertStatus(200);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'firestore.googleapis.com')) {
                return false;
            }
            $fields = $request->data()['fields']['lines']['arrayValue']['values'][0]['mapValue']['fields'];

            return $fields['account_role']['stringValue'] === 'sales_bank';
        });
    }

    /** @test */
    public function it_falls_back_to_receivable_when_payment_methods_are_mixed()
    {
        $this->fakeFirestore();
        $sale = $this->makeSale();
        $cashPayment = Payment::create(['sale_id' => $sale->id, 'method' => 'cash', 'amount' => 50, 'payment_date' => now()->format('Y-m-d')]);
        $bankakPayment = Payment::create(['sale_id' => $sale->id, 'method' => 'bankak', 'amount' => 50, 'payment_date' => now()->format('Y-m-d')]);

        $this->postJson("/api/sales/{$sale->id}/export-to-finance")->assertStatus(200);

        $docs = $this->firestoreRequests();
        $this->assertCount(3, $docs, 'expected the sale entry plus one entry per payment');

        $saleDoc = $docs->first(fn ($r) => str_contains($r->url(), "journal_entries/sale_{$sale->id}")
            && ! str_contains($r->url(), '_payment_'));
        $saleFields = $saleDoc->data()['fields']['lines']['arrayValue']['values'][0]['mapValue']['fields'];
        $this->assertSame('sales_receivable', $saleFields['account_role']['stringValue']);
        $this->assertSame(100.0, $saleFields['debit']['doubleValue']);

        $cashPaymentDoc = $docs->first(fn ($r) => str_contains($r->url(), "sale_{$sale->id}_payment_{$cashPayment->id}"));
        $this->assertNotNull($cashPaymentDoc, 'expected a payment entry for the cash payment');
        $cashPaymentFields = $cashPaymentDoc->data()['fields']['lines']['arrayValue']['values'][0]['mapValue']['fields'];
        $this->assertSame('sales_cash', $cashPaymentFields['account_role']['stringValue']);
        $this->assertSame(50.0, $cashPaymentFields['debit']['doubleValue']);

        $bankakPaymentDoc = $docs->first(fn ($r) => str_contains($r->url(), "sale_{$sale->id}_payment_{$bankakPayment->id}"));
        $this->assertNotNull($bankakPaymentDoc, 'expected a payment entry for the bankak payment');
        $bankakPaymentFields = $bankakPaymentDoc->data()['fields']['lines']['arrayValue']['values'][0]['mapValue']['fields'];
        $this->assertSame('sales_bank', $bankakPaymentFields['account_role']['stringValue']);
        $this->assertSame(50.0, $bankakPaymentFields['debit']['doubleValue']);
    }

    /** @test */
    public function it_creates_a_payment_entry_reducing_receivable_for_a_partial_payment()
    {
        $this->fakeFirestore();
        $sale = $this->makeSale();
        $payment = Payment::create(['sale_id' => $sale->id, 'method' => 'cash', 'amount' => 40, 'payment_date' => now()->format('Y-m-d')]);

        $this->postJson("/api/sales/{$sale->id}/export-to-finance")->assertStatus(200);

        $docs = $this->firestoreRequests();
        $this->assertCount(2, $docs);

        $paymentDoc = $docs->first(fn ($r) => str_contains($r->url(), "sale_{$sale->id}_payment_{$payment->id}"));
        $this->assertNotNull($paymentDoc);
        $lines = $paymentDoc->data()['fields']['lines']['arrayValue']['values'];
        $debitLine = $lines[0]['mapValue']['fields'];
        $creditLine = $lines[1]['mapValue']['fields'];

        $this->assertSame('sales_cash', $debitLine['account_role']['stringValue']);
        $this->assertSame(40.0, $debitLine['debit']['doubleValue']);
        $this->assertSame('sales_receivable', $creditLine['account_role']['stringValue']);
        $this->assertSame(40.0, $creditLine['credit']['doubleValue']);
        $this->assertSame("sales-api_client_{$sale->client_id}", $creditLine['party_id']['stringValue']);
    }

    /** @test */
    public function it_creates_no_payment_entry_when_the_sale_is_booked_directly_to_cash()
    {
        $this->fakeFirestore();
        $sale = $this->makeSale();
        Payment::create(['sale_id' => $sale->id, 'method' => 'cash', 'amount' => 100, 'payment_date' => now()->format('Y-m-d')]);

        $this->postJson("/api/sales/{$sale->id}/export-to-finance")->assertStatus(200);

        // Already booked in full directly to sales_cash on the sale entry itself —
        // a separate payment entry here would double-count the amount received.
        $this->assertCount(1, $this->firestoreRequests());
    }

    /** @test */
    public function it_records_the_error_and_leaves_exported_at_null_when_firestore_write_fails()
    {
        $this->fakeFirestore(status: 500);
        $sale = $this->makeSale();

        $response = $this->postJson("/api/sales/{$sale->id}/export-to-finance");

        $response->assertStatus(422);
        $sale->refresh();
        $this->assertNull($sale->finance_exported_at);
        $this->assertNotNull($sale->finance_export_error);
    }

    /** @test */
    public function re_exporting_overwrites_the_same_firestore_document_instead_of_duplicating()
    {
        $this->fakeFirestore();
        $sale = $this->makeSale();

        $this->postJson("/api/sales/{$sale->id}/export-to-finance")->assertStatus(200);
        $this->postJson("/api/sales/{$sale->id}/export-to-finance")->assertStatus(200);

        // Same doc id both times ("sale_{id}") — a real duplicate would use a
        // different id per call. The access token is cached, so only the
        // Firestore PATCH count (not the oauth exchange) reflects the two calls.
        $firestoreRequests = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'firestore.googleapis.com'));

        $this->assertCount(2, $firestoreRequests);
        $firestoreRequests->each(
            fn ($pair) => $this->assertStringContainsString("journal_entries/sale_{$sale->id}", $pair[0]->url())
        );
    }
}
