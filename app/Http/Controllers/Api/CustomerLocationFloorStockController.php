<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerFloor;
use App\Models\CustomerProduct;
use App\Models\Product;
use App\Models\Stock;
use App\Models\StockProduct;
use App\Services\StockAvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class CustomerLocationFloorStockController extends Controller
{
    /**
     * Stock split: location pool (unallocated) vs each floor, per product.
     */
    public function show(Customer $customer): JsonResponse
    {
        try {
            $floors = CustomerFloor::where('location_id', $customer->id)
                ->whereNull('deleted_at')
                ->orderBy('name')
                ->get(['id', 'name']);

            $assignedIds = CustomerProduct::where('customer_id', $customer->id)
                ->where('status', 'active')
                ->pluck('product_id');

            $stockIds = StockProduct::whereHas('stock', function ($q) use ($customer) {
                $q->where('customer_id', $customer->id)->whereNull('deleted_at');
            })
                ->whereNull('deleted_at')
                ->distinct()
                ->pluck('product_id');

            $productIds = $assignedIds->merge($stockIds)->unique()->values()->all();

            if (empty($productIds)) {
                return response()->json([
                    'success' => true,
                    'message' => 'No products to show',
                    'data' => [
                        'customer_id' => $customer->id,
                        'customer_name' => $customer->company_name ?: $customer->name,
                        'floors' => $floors,
                        'products' => [],
                        'totals' => [
                            'location_total' => 0,
                            'floors_total' => 0,
                            'grand_total' => 0,
                        ],
                    ],
                ]);
            }

            $products = Product::whereIn('id', $productIds)->orderBy('name')->get(['id', 'name', 'code', 'size', 'unit']);

            $rows = [];
            $sumLocation = 0;
            $sumFloors = 0;
            $sumGrand = 0;

            foreach ($products as $product) {
                $total = StockAvailabilityService::calculateAvailableStock($customer->id, $product->id);
                $atLocation = StockAvailabilityService::calculateAvailableStockForFloorScope($customer->id, $product->id, null);
                $floorQtys = [];
                $productFloorsSum = 0;
                foreach ($floors as $floor) {
                    $fq = StockAvailabilityService::calculateAvailableStockForFloorScope($customer->id, $product->id, (int) $floor->id);
                    $floorQtys[] = [
                        'floor_id' => $floor->id,
                        'floor_name' => $floor->name,
                        'qty' => $fq,
                    ];
                    $productFloorsSum += $fq;
                }
                $rows[] = [
                    'product_id' => $product->id,
                    'product' => [
                        'id' => $product->id,
                        'name' => $product->name,
                        'code' => $product->code,
                        'size' => $product->size,
                        'unit' => $product->unit,
                    ],
                    'total_at_branch' => $total,
                    'at_location' => $atLocation,
                    'floors' => $floorQtys,
                ];
                $sumLocation += $atLocation;
                $sumFloors += $productFloorsSum;
                $sumGrand += $total;
            }

            return response()->json([
                'success' => true,
                'message' => 'Stock by location and floor retrieved',
                'data' => [
                    'customer_id' => $customer->id,
                    'customer_name' => $customer->company_name ?: $customer->name,
                    'floors' => $floors,
                    'products' => $rows,
                    'totals' => [
                        'location_sum_products' => $sumLocation,
                        'floors_sum_products' => $sumFloors,
                        'branch_total_sum' => $sumGrand,
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('CustomerLocationFloorStockController@show: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to load stock split',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Move qty between location pool and a floor (same branch). Ledger: out from source scope, in to target scope.
     */
    public function move(Request $request, Customer $customer): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'direction' => 'required|in:to_floor,to_location',
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
            'customer_floor_id' => 'required|exists:customers_floor,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $floor = CustomerFloor::where('id', $request->customer_floor_id)
            ->where('location_id', $customer->id)
            ->whereNull('deleted_at')
            ->first();

        if (!$floor) {
            return response()->json([
                'success' => false,
                'message' => 'Floor does not belong to this branch',
            ], 422);
        }

        $productId = (int) $request->product_id;
        $qty = (int) $request->quantity;
        $direction = $request->direction;
        $userId = Auth::id();

        if ($direction === 'to_floor') {
            $available = StockAvailabilityService::calculateAvailableStockForFloorScope($customer->id, $productId, null);
            if ($qty > $available) {
                return response()->json([
                    'success' => false,
                    'message' => "Insufficient stock at location. Available at location: {$available}, requested: {$qty}",
                ], 422);
            }
        } else {
            $available = StockAvailabilityService::calculateAvailableStockForFloorScope($customer->id, $productId, (int) $floor->id);
            if ($qty > $available) {
                return response()->json([
                    'success' => false,
                    'message' => "Insufficient stock on this floor. Available: {$available}, requested: {$qty}",
                ], 422);
            }
        }

        try {
            DB::transaction(function () use ($customer, $productId, $qty, $direction, $floor, $userId) {
                if ($direction === 'to_floor') {
                    $outHeader = Stock::create([
                        'customer_id' => $customer->id,
                        'customer_floor_id' => null,
                        'delivery_id' => null,
                        'transfer_status' => 'floor_allocation',
                        't_date' => now()->toDateString(),
                        'created_by' => $userId,
                        'status' => 'active',
                    ]);
                    StockProduct::create([
                        'stock_id' => $outHeader->id,
                        'product_id' => $productId,
                        'stock_qty' => $qty,
                        'stock_type' => 'out',
                    ]);

                    $inHeader = Stock::create([
                        'customer_id' => $customer->id,
                        'customer_floor_id' => $floor->id,
                        'delivery_id' => null,
                        'transfer_status' => 'floor_allocation',
                        't_date' => now()->toDateString(),
                        'created_by' => $userId,
                        'status' => 'active',
                    ]);
                    StockProduct::create([
                        'stock_id' => $inHeader->id,
                        'product_id' => $productId,
                        'stock_qty' => $qty,
                        'stock_type' => 'in',
                    ]);
                } else {
                    $outHeader = Stock::create([
                        'customer_id' => $customer->id,
                        'customer_floor_id' => $floor->id,
                        'delivery_id' => null,
                        'transfer_status' => 'floor_allocation',
                        't_date' => now()->toDateString(),
                        'created_by' => $userId,
                        'status' => 'active',
                    ]);
                    StockProduct::create([
                        'stock_id' => $outHeader->id,
                        'product_id' => $productId,
                        'stock_qty' => $qty,
                        'stock_type' => 'out',
                    ]);

                    $inHeader = Stock::create([
                        'customer_id' => $customer->id,
                        'customer_floor_id' => null,
                        'delivery_id' => null,
                        'transfer_status' => 'floor_allocation',
                        't_date' => now()->toDateString(),
                        'created_by' => $userId,
                        'status' => 'active',
                    ]);
                    StockProduct::create([
                        'stock_id' => $inHeader->id,
                        'product_id' => $productId,
                        'stock_qty' => $qty,
                        'stock_type' => 'in',
                    ]);
                }
            });

            try {
                $alerts = new StockAlertController();
                $alerts->checkAndTriggerThresholdAlerts($customer->id, $productId);
            } catch (\Exception $e) {
                Log::warning('Floor stock move: threshold check failed: ' . $e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => $direction === 'to_floor'
                    ? 'Stock moved from location to floor'
                    : 'Stock moved from floor to location',
            ]);
        } catch (\Exception $e) {
            Log::error('CustomerLocationFloorStockController@move: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to move stock',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
