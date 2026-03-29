<?php

namespace App\Services;

use App\Models\StockAvailability;
use App\Models\StockAvailabilityGroup;
use App\Models\Stock;
use App\Models\StockProduct;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class StockAvailabilityService
{
    /**
     * Calculate available stock directly from stocks_product table
     * Formula: SUM(in) - (SUM(out) + SUM(sale)) = Available Qty
     * 
     * stock_type meanings:
     * - 'in' = stock in (deliveries, transfers received)
     * - 'out' = stock out (transfers sent, returns)
     * - 'sold-out' = sold out (daily usage/sales)
     */
    public static function calculateAvailableStock($customerId, $productId, $date = null)
    {
        $query = StockProduct::whereHas('stock', function($query) use ($customerId) {
            $query->where('customer_id', $customerId)
                  ->whereNull('deleted_at');
        })
        ->where('product_id', $productId)
        ->whereNull('deleted_at');
        
        // If date is provided, filter by date (for day-wise calculation)
        // For availability calculation, include all dates up to and including the given date
        if ($date) {
            $dateStr = \Carbon\Carbon::parse($date)->format('Y-m-d');
            $query->whereHas('stock', function($query) use ($dateStr) {
                $query->whereDate('t_date', '<=', $dateStr);
            });
        }
        
        // Calculate stock in: sum of stock_type = 'in'
        $stockIn = (clone $query)
            ->where('stock_type', 'in')
            ->sum('stock_qty');
        
        // Calculate stock out: sum of stock_type = 'out' (transfers, returns)
        $stockOut = (clone $query)
            ->where('stock_type', 'out')
            ->sum('stock_qty');
        
        // Calculate sales: sum of stock_type = 'sold-out' (daily usage/sales)
        $stockSale = (clone $query)
            ->where('stock_type', 'sold-out')
            ->sum('stock_qty');
        
        // Available = Stock In - (Stock Out + Sales)
        // Formula: SUM(in) - (SUM(out) + SUM(sold-out))
        return max(0, $stockIn - ($stockOut + $stockSale));
    }

    /**
     * Calculate available stock for transfer including all current date transactions
     * This includes all transactions up to and including the given date
     * Formula: SUM(in) - (SUM(out) + SUM(sold-out)) = Available Qty
     * 
     * @param int $customerId
     * @param int $productId
     * @param string $date Date in Y-m-d format
     * @return int Available quantity including current date transactions
     */
    public static function calculateAvailableStockForTransfer($customerId, $productId, $date)
    {
        $dateStr = \Carbon\Carbon::parse($date)->format('Y-m-d');
        
        // Query all stock products up to and including the given date
        $query = StockProduct::whereHas('stock', function($query) use ($customerId, $dateStr) {
            $query->where('customer_id', $customerId)
                  ->whereDate('t_date', '<=', $dateStr) // Include all dates up to and including the date
                  ->whereNull('deleted_at');
        })
        ->where('product_id', $productId)
        ->whereNull('deleted_at');
        
        // Calculate stock in: sum of stock_type = 'in'
        $stockIn = (clone $query)
            ->where('stock_type', 'in')
            ->sum('stock_qty');
        
        // Calculate stock out: sum of stock_type = 'out' (transfers, returns)
        $stockOut = (clone $query)
            ->where('stock_type', 'out')
            ->sum('stock_qty');
        
        // Calculate sales: sum of stock_type = 'sold-out' (daily usage/sales)
        $stockSale = (clone $query)
            ->where('stock_type', 'sold-out')
            ->sum('stock_qty');
        
        // Available = Stock In - (Stock Out + Sales)
        // This includes all transactions including current date
        return max(0, $stockIn - ($stockOut + $stockSale));
    }

    /**
     * Calculate stock movements for a specific date (day-wise)
     * Returns stock in, stock out, and sales for that specific date
     */
    public static function calculateDayWiseMovements($customerId, $productId, $date)
    {
        $dateStr = \Carbon\Carbon::parse($date)->format('Y-m-d');
        
        $baseQuery = StockProduct::whereHas('stock', function($query) use ($customerId, $dateStr) {
            $query->where('customer_id', $customerId)
                  ->whereDate('t_date', $dateStr)
                  ->whereNull('deleted_at');
        })
        ->where('product_id', $productId)
        ->whereNull('deleted_at');
        
        // Stock In for the date
        $stockIn = (clone $baseQuery)
            ->where('stock_type', 'in')
            ->sum('stock_qty');
        
        // Stock Out for the date (transfers, returns)
        $stockOut = (clone $baseQuery)
            ->where('stock_type', 'out')
            ->sum('stock_qty');
        
        // Sales for the date (daily usage/sales)
        $stockSale = (clone $baseQuery)
            ->where('stock_type', 'sold-out')
            ->sum('stock_qty');
        
        return [
            'stock_in_qty' => $stockIn,
            'stock_out_qty' => $stockOut,
            'sale_qty' => $stockSale,
            'net_qty' => $stockIn - ($stockOut + $stockSale)
        ];
    }

    /**
     * Day-wise movements for a single floor scope (or Branch Inventory when customer_floor_id is null).
     */
    public static function calculateDayWiseMovementsForFloorScope(int $customerId, int $productId, ?int $customerFloorId, $date): array
    {
        $dateStr = Carbon::parse($date)->format('Y-m-d');

        $baseQuery = StockProduct::whereHas('stock', function ($query) use ($customerId, $dateStr, $customerFloorId) {
            $query->where('customer_id', $customerId)
                ->whereDate('t_date', $dateStr)
                ->whereNull('deleted_at');

            if ($customerFloorId === null) {
                $query->whereNull('customer_floor_id');
            } else {
                $query->where('customer_floor_id', $customerFloorId);
            }
        })
            ->where('product_id', $productId)
            ->whereNull('deleted_at');

        $stockIn = (clone $baseQuery)->where('stock_type', 'in')->sum('stock_qty');
        $stockOut = (clone $baseQuery)->where('stock_type', 'out')->sum('stock_qty');
        $stockSale = (clone $baseQuery)->where('stock_type', 'sold-out')->sum('stock_qty');

        return [
            'stock_in_qty' => $stockIn,
            'stock_out_qty' => $stockOut,
            'sale_qty' => $stockSale,
            'net_qty' => $stockIn - ($stockOut + $stockSale),
        ];
    }

    /**
     * Get day-wise stock availability report for a customer
     * Groups by date and calculates available stock for each day
     */
    public static function getDayWiseAvailabilityReport($customerId, $productId = null, $startDate = null, $endDate = null)
    {
        $query = StockProduct::whereHas('stock', function($query) use ($customerId) {
            $query->where('customer_id', $customerId)
                  ->whereNull('deleted_at');
        })
        ->whereNull('deleted_at');
        
        if ($productId) {
            $query->where('product_id', $productId);
        }
        
        if ($startDate) {
            $query->whereHas('stock', function($query) use ($startDate) {
                $query->whereDate('t_date', '>=', $startDate);
            });
        }
        
        if ($endDate) {
            $query->whereHas('stock', function($query) use ($endDate) {
                $query->whereDate('t_date', '<=', $endDate);
            });
        }
        
        // Get all stock products with their dates
        $stockProducts = $query->with(['stock' => function($query) {
            $query->select('id', 'customer_id', 't_date');
        }])
        ->get();
        
        // Group by date and calculate
        $dayWiseData = [];
        $runningTotal = 0;
        
        foreach ($stockProducts->groupBy(function($item) {
            return \Carbon\Carbon::parse($item->stock->t_date)->format('Y-m-d');
        }) as $date => $products) {
            $dayStockIn = $products->where('stock_type', 'in')->sum('stock_qty');
            $dayStockOut = $products->where('stock_type', 'out')->sum('stock_qty');
            $daySale = $products->where('stock_type', 'sold-out')->sum('stock_qty');
            $dayNet = $dayStockIn - ($dayStockOut + $daySale);
            $runningTotal += $dayNet;
            
            $dayWiseData[] = [
                'date' => $date,
                'stock_in_qty' => $dayStockIn,
                'stock_out_qty' => $dayStockOut,
                'sale_qty' => $daySale,
                'net_qty' => $dayNet,
                'available_qty' => max(0, $runningTotal)
            ];
        }
        
        // Sort by date
        usort($dayWiseData, function($a, $b) {
            return strcmp($a['date'], $b['date']);
        });
        
        return $dayWiseData;
    }

    /**
     * Get opening stock for a customer, product, and date
     * Calculates available stock up to the day before the given date
     */
    public static function getOpeningStock($customerId, $productId, $date)
    {
        // Calculate available stock up to the previous day
        $previousDate = \Carbon\Carbon::parse($date)->subDay()->format('Y-m-d');
        return self::calculateAvailableStock($customerId, $productId, $previousDate);
    }

    /**
     * Calculate stock movements for a customer, product, and date
     * This is now just an alias for calculateDayWiseMovements for backward compatibility
     */
    public static function calculateStockMovements($customerId, $productId, $date)
    {
        return self::calculateDayWiseMovements($customerId, $productId, $date);
    }

    /**
     * Get current available stock for a customer and product
     * Calculates directly from stocks_product table (no date limit = all time)
     */
    public static function getCurrentAvailableStock($customerId, $productId)
    {
        return self::calculateAvailableStock($customerId, $productId);
    }

    /**
     * Available qty for a product at one branch (customer), scoped by floor.
     * customer_floor_id null = location pool only (not yet allocated to a floor).
     * Non-null = that floor only. Uses same in / out / sold-out formula as calculateAvailableStock.
     */
    public static function calculateAvailableStockForFloorScope(int $customerId, int $productId, ?int $customerFloorId, $date = null): int
    {
        $query = StockProduct::whereHas('stock', function ($query) use ($customerId, $customerFloorId) {
            $query->where('customer_id', $customerId)
                ->whereNull('deleted_at');

            if ($customerFloorId === null) {
                $query->whereNull('customer_floor_id');
            } else {
                $query->where('customer_floor_id', $customerFloorId);
            }
        })
            ->where('product_id', $productId)
            ->whereNull('deleted_at');

        if ($date) {
            $dateStr = Carbon::parse($date)->format('Y-m-d');
            $query->whereHas('stock', function ($query) use ($dateStr) {
                $query->whereDate('t_date', '<=', $dateStr);
            });
        }

        $stockIn = (clone $query)->where('stock_type', 'in')->sum('stock_qty');
        $stockOut = (clone $query)->where('stock_type', 'out')->sum('stock_qty');
        $stockSale = (clone $query)->where('stock_type', 'sold-out')->sum('stock_qty');

        return max(0, $stockIn - ($stockOut + $stockSale));
    }

    /**
     * Maximum cumulative sold-out qty allowed for this product/scope/date: stock in minus stock out
     * (ignores sold-out). Use this to cap stock_used_qty; remaining after sales is calculateAvailableStockForFloorScope.
     */
    public static function calculateMaxSoldOutQtyForFloorScope(int $customerId, int $productId, ?int $customerFloorId, $date = null): int
    {
        $query = StockProduct::whereHas('stock', function ($query) use ($customerId, $customerFloorId) {
            $query->where('customer_id', $customerId)
                ->whereNull('deleted_at');

            if ($customerFloorId === null) {
                $query->whereNull('customer_floor_id');
            } else {
                $query->where('customer_floor_id', $customerFloorId);
            }
        })
            ->where('product_id', $productId)
            ->whereNull('deleted_at');

        if ($date) {
            $dateStr = Carbon::parse($date)->format('Y-m-d');
            $query->whereHas('stock', function ($query) use ($dateStr) {
                $query->whereDate('t_date', '<=', $dateStr);
            });
        }

        $stockIn = (clone $query)->where('stock_type', 'in')->sum('stock_qty');
        $stockOut = (clone $query)->where('stock_type', 'out')->sum('stock_qty');

        return max(0, $stockIn - $stockOut);
    }
}
