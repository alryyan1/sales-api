<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\PackageItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PackageController extends Controller
{
    public function index()
    {
        $packages = Package::with('items.product')->get();
        $packages->each(function ($package) {
            $package->items->each(function ($item) {
                if ($item->product) {
                    $item->product->append(['last_sale_price_per_sellable_unit']);
                }
            });
        });
        return response()->json($packages);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'items' => 'nullable|array',
            'items.*.product_id' => 'required|exists:products,id',
        ]);

        return DB::transaction(function () use ($request) {
            $package = Package::create($request->only('name'));

            if ($request->has('items')) {
                foreach ($request->items as $item) {
                    $package->items()->create([
                        'product_id' => $item['product_id'],
                    ]);
                }
            }

            return response()->json($package->load('items.product'), 201);
        });
    }

    public function show(Package $package)
    {
        $package->load('items.product');
        $package->items->each(function ($item) {
            if ($item->product) {
                $item->product->append(['last_sale_price_per_sellable_unit']);
            }
        });
        return response()->json($package);
    }

    public function update(Request $request, Package $package)
    {
        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'items' => 'sometimes|required|array',
            'items.*.product_id' => 'required|exists:products,id',
        ]);

        return DB::transaction(function () use ($request, $package) {
            $package->update($request->only('name'));

            if ($request->has('items')) {
                $package->items()->delete();
                foreach ($request->items as $item) {
                    $package->items()->create([
                        'product_id' => $item['product_id'],
                    ]);
                }
            }

            return response()->json($package->load('items.product'));
        });
    }

    public function destroy(Package $package)
    {
        $package->delete();
        return response()->json(null, 204);
    }
}
