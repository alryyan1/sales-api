<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ScheduledSaleResource;
use App\Models\ScheduledSale;
use App\Services\ScheduledSaleNotifier;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ScheduledSaleController extends Controller
{
    private function itemValidationRules(): array
    {
        return [
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
        ];
    }

    private function ensurePermission(): void
    {
        if (! Auth::user()->can('جدولة الفواتير')) {
            abort(403, 'This action is unauthorized.');
        }
    }

    public function index(Request $request)
    {
        $query = ScheduledSale::with(['client:id,name,phone', 'items.product:id,name', 'sale:id,number']);

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($clientId = $request->input('client_id')) {
            $query->where('client_id', $clientId);
        }
        if ($from = $request->input('scheduled_from')) {
            $query->whereDate('scheduled_at', '>=', $from);
        }
        if ($to = $request->input('scheduled_to')) {
            $query->whereDate('scheduled_at', '<=', $to);
        }
        if ($search = $request->input('search')) {
            $query->whereHas('client', fn ($q) => $q->where('name', 'like', "%{$search}%"));
        }

        $scheduledSales = $query->orderByDesc('scheduled_at')->paginate($request->input('per_page', 20));
        $scheduledSales->getCollection()->transform(fn ($s) => new ScheduledSaleResource($s));

        return response()->json($scheduledSales);
    }

    public function store(Request $request)
    {
        $this->ensurePermission();

        $validated = $request->validate(array_merge([
            'client_id' => 'required|exists:clients,id',
            'scheduled_at' => 'required|date|after_or_equal:'.now()->subMinute()->toDateTimeString(),
            'discount_amount' => 'nullable|numeric|min:0',
            'discount_type' => ['nullable', Rule::in(['percentage', 'fixed'])],
            'notes' => 'nullable|string|max:65535',
        ], $this->itemValidationRules()));

        if (! empty($validated['discount_amount']) && $validated['discount_amount'] > 0 && ! Auth::user()->can('تخفيض')) {
            abort(403, 'This action is unauthorized.');
        }

        $scheduledSale = DB::transaction(function () use ($validated, $request) {
            $scheduledSale = ScheduledSale::create([
                'client_id' => $validated['client_id'],
                'user_id' => $request->user()->id,
                'warehouse_id' => $request->user()->warehouse_id ?? 1,
                'scheduled_at' => $validated['scheduled_at'],
                'status' => ScheduledSale::STATUS_PENDING,
                'discount_amount' => $validated['discount_amount'] ?? null,
                'discount_type' => $validated['discount_type'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($validated['items'] as $item) {
                $scheduledSale->items()->create($item);
            }

            return $scheduledSale;
        });

        $scheduledSale->load(['client:id,name,phone', 'items.product:id,name']);

        return response()->json(['data' => new ScheduledSaleResource($scheduledSale)], Response::HTTP_CREATED);
    }

    public function show(ScheduledSale $scheduledSale)
    {
        $scheduledSale->load(['client:id,name,phone', 'items.product:id,name', 'sale:id,number', 'user:id,name']);

        return new ScheduledSaleResource($scheduledSale);
    }

    public function update(Request $request, ScheduledSale $scheduledSale)
    {
        $this->ensurePermission();

        if ($scheduledSale->status !== ScheduledSale::STATUS_PENDING) {
            return response()->json([
                'message' => 'Only pending scheduled sales can be edited.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $validated = $request->validate(array_merge([
            'client_id' => 'required|exists:clients,id',
            'scheduled_at' => 'required|date',
            'discount_amount' => 'nullable|numeric|min:0',
            'discount_type' => ['nullable', Rule::in(['percentage', 'fixed'])],
            'notes' => 'nullable|string|max:65535',
        ], $this->itemValidationRules()));

        if (! empty($validated['discount_amount']) && $validated['discount_amount'] > 0 && ! Auth::user()->can('تخفيض')) {
            abort(403, 'This action is unauthorized.');
        }

        DB::transaction(function () use ($validated, $scheduledSale) {
            $scheduledSale->update([
                'client_id' => $validated['client_id'],
                'scheduled_at' => $validated['scheduled_at'],
                'discount_amount' => $validated['discount_amount'] ?? null,
                'discount_type' => $validated['discount_type'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);

            $scheduledSale->items()->delete();
            foreach ($validated['items'] as $item) {
                $scheduledSale->items()->create($item);
            }
        });

        $scheduledSale->load(['client:id,name,phone', 'items.product:id,name']);

        return new ScheduledSaleResource($scheduledSale);
    }

    public function cancel(ScheduledSale $scheduledSale)
    {
        $this->ensurePermission();

        if (! in_array($scheduledSale->status, [ScheduledSale::STATUS_PENDING, ScheduledSale::STATUS_FAILED])) {
            return response()->json([
                'message' => 'Only pending or failed scheduled sales can be cancelled.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $scheduledSale->update(['status' => ScheduledSale::STATUS_CANCELLED]);

        return new ScheduledSaleResource($scheduledSale);
    }

    public function retry(ScheduledSale $scheduledSale)
    {
        $this->ensurePermission();

        if ($scheduledSale->status !== ScheduledSale::STATUS_FAILED || $scheduledSale->sale_id !== null) {
            return response()->json([
                'message' => 'Only failed scheduled sales that have not created a sale yet can be retried.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $scheduledSale->update([
            'status' => ScheduledSale::STATUS_PENDING,
            'error_message' => null,
        ]);

        return new ScheduledSaleResource($scheduledSale);
    }

    public function resendWhatsapp(ScheduledSale $scheduledSale, ScheduledSaleNotifier $notifier)
    {
        $this->ensurePermission();

        if ($scheduledSale->sale_id === null) {
            return response()->json([
                'message' => 'Cannot resend WhatsApp before the sale has been created.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $notifier->sendInvoice($scheduledSale);

        $scheduledSale->refresh()->load(['client:id,name,phone', 'sale:id,number']);

        return new ScheduledSaleResource($scheduledSale);
    }
}
