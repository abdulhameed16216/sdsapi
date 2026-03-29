<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerFloor;
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

class FloorStockTransferController extends Controller
{
    /**
     * List floor stock transfers (stocks.transfer_status = floor_allocation)
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Only show allocation "IN to floor" headers (hide OUT rows to avoid duplicates)
            $query = Stock::with([
                'customer.customerGroup:id,name',
                'customer:id,name,company_name,customer_group_id',
                'customerFloor:id,name,location_id',
                'stockProducts.product:id,name,code,size,unit',
                'creator:id,username',
            ])
                ->where('transfer_status', 'floor_allocation')
                ->whereNotNull('customer_floor_id')
                ->whereNull('deleted_at')
                ->whereHas('stockProducts', function ($q) {
                    $q->where('stock_type', 'in')->whereNull('deleted_at');
                });

            if ($request->has('customer_id') && $request->customer_id) {
                $query->where('customer_id', (int) $request->customer_id);
            }
            if ($request->has('date_from') && $request->date_from) {
                $query->whereDate('t_date', '>=', $request->date_from);
            }
            if ($request->has('date_to') && $request->date_to) {
                $query->whereDate('t_date', '<=', $request->date_to);
            }

            $stocks = $query->orderBy('t_date', 'desc')->orderBy('created_at', 'desc')->get();

            $rows = $stocks->map(function (Stock $s) {
                $line = $s->stockProducts->whereNull('deleted_at')->firstWhere('stock_type', 'in');
                $product = $line && $line->product ? $line->product : null;
                $qty = $line ? (int) $line->stock_qty : 0;
                $branchName = $s->customer ? ($s->customer->company_name ?: $s->customer->name) : '-';
                $groupName = ($s->customer && $s->customer->customerGroup) ? $s->customer->customerGroup->name : '-';
                $floorName = $s->customerFloor ? $s->customerFloor->name : '-';

                return [
                    'id' => $s->id,
                    'customer_id' => $s->customer_id,
                    'branch' => $s->customer,
                    'customer_group_name' => $groupName,
                    'from_label' => $branchName . ' (Branch Inventory)',
                    'to_label' => $floorName,
                    't_date' => $s->t_date,
                    'qty' => $qty,
                    'products_count' => $product ? 1 : 0,
                    'product' => $product,
                    'from_floor_id' => null,
                    'to_floor_id' => $s->customer_floor_id,
                    'status' => $s->status ?: 'active',
                    'creator' => $s->creator,
                    'raw' => $s,
                ];
            })->values();

            return response()->json([
                'success' => true,
                'message' => 'Floor stock transfers retrieved',
                'data' => $rows,
            ]);
        } catch (\Exception $e) {
            Log::error('FloorStockTransferController@index: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load floor transfers',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a floor stock transfer within one branch.
     * Supports: location->floor, floor->location, floor->floor.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'customer_id' => 'required|exists:customers,id',
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
            'to_floor_id' => 'required|exists:customers_floor,id',
            't_date' => 'required|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $customerId = (int) $request->customer_id;
        $productId = (int) $request->product_id;
        $qty = (int) $request->quantity;
        $toFloorId = $request->to_floor_id ? (int) $request->to_floor_id : null;
        $date = $request->t_date;
        $floor = CustomerFloor::where('id', $toFloorId)->where('location_id', $customerId)->whereNull('deleted_at')->first();
        if (!$floor) {
            return response()->json(['success' => false, 'message' => 'Selected floor does not belong to this branch'], 422);
        }

        // Availability checks
        $available = StockAvailabilityService::calculateAvailableStockForFloorScope($customerId, $productId, null, $date);
        if ($qty > $available) {
            return response()->json(['success' => false, 'message' => "Insufficient stock at Branch Inventory. Available: {$available}, requested: {$qty}"], 422);
        }

        try {
            $userId = Auth::id();
            DB::transaction(function () use ($customerId, $productId, $qty, $toFloorId, $date, $userId, &$stock) {
                // OUT from branch pool
                $outHeader = Stock::create([
                    'customer_id' => $customerId,
                    'customer_floor_id' => null,
                    'delivery_id' => null,
                    'transfer_status' => 'floor_allocation',
                    't_date' => $date,
                    'created_by' => $userId,
                    'status' => 'active',
                ]);
                StockProduct::create([
                    'stock_id' => $outHeader->id,
                    'product_id' => $productId,
                    'stock_qty' => $qty,
                    'stock_type' => 'out',
                ]);

                // IN to target floor
                $inHeader = Stock::create([
                    'customer_id' => $customerId,
                    'customer_floor_id' => $toFloorId,
                    'delivery_id' => null,
                    'transfer_status' => 'floor_allocation',
                    't_date' => $date,
                    'created_by' => $userId,
                    'status' => 'active',
                ]);
                StockProduct::create([
                    'stock_id' => $inHeader->id,
                    'product_id' => $productId,
                    'stock_qty' => $qty,
                    'stock_type' => 'in',
                ]);
                $stock = $inHeader;
            });

            return response()->json([
                'success' => true,
                'message' => 'Floor transfer created',
                'data' => $stock->load(['stockProducts.product', 'customerFloor', 'customer']),
            ], 201);
        } catch (\Exception $e) {
            Log::error('FloorStockTransferController@store: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to create floor transfer', 'error' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'customer_id' => 'required|exists:customers,id',
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
            'to_floor_id' => 'required|exists:customers_floor,id',
            't_date' => 'required|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $customerId = (int) $request->customer_id;
        $productId = (int) $request->product_id;
        $qty = (int) $request->quantity;
        $toFloorId = (int) $request->to_floor_id;
        $date = $request->t_date;

        $floor = CustomerFloor::where('id', $toFloorId)->where('location_id', $customerId)->whereNull('deleted_at')->first();
        if (!$floor) {
            return response()->json(['success' => false, 'message' => 'Selected floor does not belong to this branch'], 422);
        }

        $available = StockAvailabilityService::calculateAvailableStockForFloorScope($customerId, $productId, null, $date);
        if ($qty > $available) {
            return response()->json(['success' => false, 'message' => "Insufficient stock at Branch Inventory. Available: {$available}, requested: {$qty}"], 422);
        }

        try {
            $userId = Auth::id();
            DB::transaction(function () use ($id, $customerId, $productId, $qty, $toFloorId, $date, $userId, &$updated) {
                // Remove previous pair (same as destroy logic)
                $inStock = Stock::with('stockProducts')
                    ->where('transfer_status', 'floor_allocation')
                    ->whereNotNull('customer_floor_id')
                    ->findOrFail($id);

                $inLine = $inStock->stockProducts->firstWhere('stock_type', 'in');
                $oldProductId = $inLine ? $inLine->product_id : null;
                $oldQty = $inLine ? (int) $inLine->stock_qty : null;

                StockProduct::where('stock_id', $inStock->id)->delete();
                $inStock->delete();

                if ($oldProductId && $oldQty !== null) {
                    $outStock = Stock::where('transfer_status', 'floor_allocation')
                        ->where('customer_id', $inStock->customer_id)
                        ->whereNull('customer_floor_id')
                        ->whereDate('t_date', $inStock->t_date)
                        ->whereNull('deleted_at')
                        ->where('id', '<', $inStock->id)
                        ->whereHas('stockProducts', function ($q) use ($oldProductId, $oldQty) {
                            $q->where('stock_type', 'out')
                                ->where('product_id', $oldProductId)
                                ->where('stock_qty', $oldQty)
                                ->whereNull('deleted_at');
                        })
                        ->orderBy('id', 'desc')
                        ->first();
                    if ($outStock) {
                        StockProduct::where('stock_id', $outStock->id)->delete();
                        $outStock->delete();
                    }
                }

                // Create replacement pair
                $outHeader = Stock::create([
                    'customer_id' => $customerId,
                    'customer_floor_id' => null,
                    'delivery_id' => null,
                    'transfer_status' => 'floor_allocation',
                    't_date' => $date,
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
                    'customer_id' => $customerId,
                    'customer_floor_id' => $toFloorId,
                    'delivery_id' => null,
                    'transfer_status' => 'floor_allocation',
                    't_date' => $date,
                    'created_by' => $userId,
                    'status' => 'active',
                ]);
                StockProduct::create([
                    'stock_id' => $inHeader->id,
                    'product_id' => $productId,
                    'stock_qty' => $qty,
                    'stock_type' => 'in',
                ]);
                $updated = $inHeader;
            });

            return response()->json([
                'success' => true,
                'message' => 'Floor transfer updated',
                'data' => $updated->load(['stockProducts.product', 'customerFloor', 'customer']),
            ]);
        } catch (\Exception $e) {
            Log::error('FloorStockTransferController@update: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update floor transfer',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            DB::transaction(function () use ($id) {
                // Selected row points to IN header (branch -> floor allocation)
                $inStock = Stock::with('stockProducts')
                    ->where('transfer_status', 'floor_allocation')
                    ->whereNotNull('customer_floor_id')
                    ->findOrFail($id);

                $inLine = $inStock->stockProducts->firstWhere('stock_type', 'in');
                $productId = $inLine ? $inLine->product_id : null;
                $qty = $inLine ? (int) $inLine->stock_qty : null;

                // Soft-delete IN header + lines
                StockProduct::where('stock_id', $inStock->id)->delete();
                $inStock->delete();

                // Try to soft-delete matching OUT header from Branch Inventory created for same allocation
                if ($productId && $qty !== null) {
                    $outStock = Stock::where('transfer_status', 'floor_allocation')
                        ->where('customer_id', $inStock->customer_id)
                        ->whereNull('customer_floor_id')
                        ->whereDate('t_date', $inStock->t_date)
                        ->whereNull('deleted_at')
                        ->where('id', '<', $inStock->id)
                        ->whereHas('stockProducts', function ($q) use ($productId, $qty) {
                            $q->where('stock_type', 'out')
                                ->where('product_id', $productId)
                                ->where('stock_qty', $qty)
                                ->whereNull('deleted_at');
                        })
                        ->orderBy('id', 'desc')
                        ->first();

                    if ($outStock) {
                        StockProduct::where('stock_id', $outStock->id)->delete();
                        $outStock->delete();
                    }
                }
            });

            return response()->json([
                'success' => true,
                'message' => 'Floor transfer deleted',
            ]);
        } catch (\Exception $e) {
            Log::error('FloorStockTransferController@destroy: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to delete floor transfer', 'error' => $e->getMessage()], 500);
        }
    }
}

