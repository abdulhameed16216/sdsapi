<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class StockSyncController extends Controller
{
    /**
     * Get stock movement calculation for a specific record
     */
    public function getStockMovementCalculation(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'customer_id' => 'required|exists:customers,id',
                'product_id' => 'required|exists:products,id',
                'date' => 'required|date'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Get previous day's closing stock as opening quantity
            $previousDate = Carbon::parse($request->date)->subDay()->format('Y-m-d');
            $openingStock = \App\Models\StockAvailability::where('customer_id', $request->customer_id)
                ->where('product_id', $request->product_id)
                ->where('date', $previousDate)
                ->value('closing_qty') ?? 0;

            // Calculate stock movements for this date
            $stockInQty = \App\Models\Stock::where('customer_id', $request->customer_id)
                ->where('product_id', $request->product_id)
                ->where('t_date', $request->date)
                ->where('stock_type', 'in')
                ->sum('qty');

            $stockOutQty = \App\Models\Stock::where('customer_id', $request->customer_id)
                ->where('product_id', $request->product_id)
                ->where('t_date', $request->date)
                ->where('stock_type', 'out')
                ->sum('qty');

            $calculatedAvailable = $openingStock + $stockInQty - $stockOutQty;

            return response()->json([
                'success' => true,
                'message' => 'Stock movement calculation retrieved successfully',
                'data' => [
                    'customer_id' => $request->customer_id,
                    'product_id' => $request->product_id,
                    'date' => $request->date,
                    'opening_stock' => $openingStock,
                    'stock_in_qty' => $stockInQty,
                    'stock_out_qty' => $stockOutQty,
                    'calculated_available_qty' => $calculatedAvailable,
                    'calculation_formula' => "Opening Stock ({$openingStock}) + Stock In ({$stockInQty}) - Stock Out ({$stockOutQty}) = {$calculatedAvailable}"
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error in get stock movement calculation: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to get stock movement calculation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Sync all existing stock records to stock availability (catch-up sync)
     */
    public function syncAllExistingStocks(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date|after_or_equal:start_date'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Get all unique combinations from stocks table
            $query = \App\Models\Stock::select('customer_id', 'product_id', 't_date')
                ->groupBy('customer_id', 'product_id', 't_date');

            if ($request->start_date) {
                $query->where('t_date', '>=', $request->start_date);
            }

            if ($request->end_date) {
                $query->where('t_date', '<=', $request->end_date);
            }

            $stockCombinations = $query->get();
            $syncedRecords = 0;
            $errors = [];

            foreach ($stockCombinations as $combination) {
                try {
                    $this->createStockAvailabilityRecord(
                        $combination->customer_id,
                        $combination->product_id,
                        $combination->t_date
                    );
                    $syncedRecords++;
                } catch (\Exception $e) {
                    $errors[] = "Error syncing customer {$combination->customer_id}, product {$combination->product_id}, date {$combination->t_date}: " . $e->getMessage();
                }
            }

            return response()->json([
                'success' => true,
                'message' => "Successfully synced {$syncedRecords} stock availability records",
                'data' => [
                    'total_combinations' => $stockCombinations->count(),
                    'synced_records' => $syncedRecords,
                    'errors' => $errors
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error in sync all existing stocks: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to sync all existing stocks',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create or update stock availability record (same logic as in controllers)
     */
    private function createStockAvailabilityRecord($customerId, $productId, $date)
    {
        try {
            // Get previous day's closing stock as opening quantity
            $previousDate = \Carbon\Carbon::parse($date)->subDay()->format('Y-m-d');
            $openingStock = \App\Models\StockAvailability::where('customer_id', $customerId)
                ->where('product_id', $productId)
                ->where('date', $previousDate)
                ->value('closing_qty') ?? 0;

            // Calculate stock movements for this date
            $stockInQty = \App\Models\Stock::where('customer_id', $customerId)
                ->where('product_id', $productId)
                ->where('t_date', $date)
                ->where('stock_type', 'in')
                ->sum('qty');

            $stockOutQty = \App\Models\Stock::where('customer_id', $customerId)
                ->where('product_id', $productId)
                ->where('t_date', $date)
                ->where('stock_type', 'out')
                ->sum('qty');

            // Calculate available quantity
            $calculatedAvailable = $openingStock + $stockInQty - $stockOutQty;

            // Create or update stock availability record
            \App\Models\StockAvailability::updateOrCreate(
                [
                    'customer_id' => $customerId,
                    'product_id' => $productId,
                    'date' => $date
                ],
                [
                    'open_qty' => $openingStock,
                    'stock_in_qty' => $stockInQty,
                    'stock_out_qty' => $stockOutQty,
                    'calculated_available_qty' => $calculatedAvailable,
                    'closing_qty' => $calculatedAvailable, // Set closing qty to calculated available
                    'time' => now()->format('H:i:s'),
                    'created_by' => auth()->id() ?? 1
                ]
            );

            Log::info("Stock availability record created/updated for customer {$customerId}, product {$productId}, date {$date}");

        } catch (\Exception $e) {
            Log::error("Error creating stock availability record: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Test endpoint to check synchronization status
     */
    public function testSyncStatus(): JsonResponse
    {
        try {
            $stockCount = \App\Models\Stock::count();
            $availabilityCount = \App\Models\StockAvailability::count();
            
            $latestStock = \App\Models\Stock::latest()->first();
            $hasAvailability = false;
            
            if ($latestStock) {
                $availability = \App\Models\StockAvailability::where('customer_id', $latestStock->customer_id)
                    ->where('product_id', $latestStock->product_id)
                    ->where('date', $latestStock->t_date)
                    ->first();
                    
                $hasAvailability = $availability ? true : false;
            }

            return response()->json([
                'success' => true,
                'message' => 'Sync status check completed',
                'data' => [
                    'stock_records_count' => $stockCount,
                    'availability_records_count' => $availabilityCount,
                    'latest_stock_record' => $latestStock ? [
                        'id' => $latestStock->id,
                        'customer_id' => $latestStock->customer_id,
                        'product_id' => $latestStock->product_id,
                        'date' => $latestStock->t_date,
                        'qty' => $latestStock->qty,
                        'stock_type' => $latestStock->stock_type
                    ] : null,
                    'has_corresponding_availability' => $hasAvailability,
                    'sync_needed' => $stockCount > 0 && $availabilityCount == 0
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error in test sync status: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to check sync status',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
