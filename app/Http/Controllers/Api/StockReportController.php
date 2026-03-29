<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Models\StockProduct;
use App\Models\StockAvailability;
use App\Models\StockTransfer;
use App\Models\StockReport;
use App\Models\Delivery;
use App\Models\DeliveryProduct;
use App\Models\Customer;
use App\Models\Product;
use App\Services\StockAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StockReportController extends Controller
{
    /**
     * Get comprehensive stock report
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = $request->all();
            
            // Get date range
            $dateFrom = $request->get('date_from', now()->subDays(30)->format('Y-m-d'));
            $dateTo = $request->get('date_to', now()->format('Y-m-d'));
            $customerId = $request->get('customer_id');
            $productId = $request->get('product_id');

            // Build base query conditions
            $conditions = [
                'date_from' => $dateFrom,
                'date_to' => $dateTo
            ];

            if ($customerId) {
                $conditions['customer_id'] = $customerId;
            }
            if ($productId) {
                $conditions['product_id'] = $productId;
            }

            // Get deliveries (stock out)
            $deliveries = $this->getDeliveries($conditions);
            
            // Get stock availability
            $stockAvailability = $this->getStockAvailability($conditions);
            
            // Get stock transfers
            $stockTransfers = $this->getStockTransfers($conditions);

            // Get summary data
            $summary = $this->getSummaryData($conditions);

            return response()->json([
                'success' => true,
                'message' => 'Stock report retrieved successfully',
                'data' => [
                    'summary' => $summary,
                    'deliveries' => $deliveries,
                    'stock_availability' => $stockAvailability,
                    'stock_transfers' => $stockTransfers,
                    'filters' => [
                        'date_from' => $dateFrom,
                        'date_to' => $dateTo,
                        'customer_id' => $customerId,
                        'product_id' => $productId
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error retrieving stock report: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve stock report',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get deliveries data
     */
    private function getDeliveries(array $conditions): array
    {
        // Get deliveries from delivery table (not from stocks table)
        $query = Delivery::with(['customer', 'fromCustomer', 'deliveryProducts.product', 'creator'])
            ->whereIn('delivery_type', ['in', 'out'])
            ->whereBetween('prepare_date', [$conditions['date_from'], $conditions['date_to']]);

        if (isset($conditions['customer_id'])) {
            $query->where('customer_id', $conditions['customer_id']);
        }

        $deliveries = $query->orderBy('prepare_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        $result = [];
        foreach ($deliveries as $delivery) {
            foreach ($delivery->deliveryProducts as $deliveryProduct) {
                if (isset($conditions['product_id']) && $deliveryProduct->product_id != $conditions['product_id']) {
                    continue;
                }

                $result[] = [
                    'id' => $delivery->id,
                    'type' => 'delivery',
                    'customer_id' => $delivery->customer_id,
                    'customer_name' => $delivery->customer ? ($delivery->customer->company_name ?: $delivery->customer->name) : 'Unknown',
                    'product_id' => $deliveryProduct->product_id,
                    'product_name' => $deliveryProduct->product ? $deliveryProduct->product->name : 'Unknown',
                    'product_size' => $deliveryProduct->product ? $deliveryProduct->product->size : '',
                    'qty' => $deliveryProduct->delivery_qty,
                    'date' => $delivery->prepare_date,
                    'notes' => $deliveryProduct->notes ?? '',
                    'created_by' => $delivery->creator ? $delivery->creator->name : 'Unknown',
                    'created_at' => $delivery->created_at
                ];
            }
        }

        return $result;
    }

    /**
     * Get stock availability data
     */
    private function getStockAvailability(array $conditions): array
    {
        // Calculate stock availability dynamically from stocks and stocks_product
        // Get all customers and products
        $customersQuery = Customer::where('status', 'active');
        if (isset($conditions['customer_id'])) {
            $customersQuery->where('id', $conditions['customer_id']);
        }
        $customers = $customersQuery->get();

        $productsQuery = Product::where('status', 'active');
        if (isset($conditions['product_id'])) {
            $productsQuery->where('id', $conditions['product_id']);
        }
        $products = $productsQuery->get();

        $result = [];
        foreach ($customers as $customer) {
            foreach ($products as $product) {
                // Calculate availability up to the date_to
                $availableQty = StockAvailabilityService::calculateAvailableStock(
                    $customer->id,
                    $product->id,
                    $conditions['date_to']
                );

                if ($availableQty > 0) {
                    $result[] = [
                        'id' => "{$customer->id}_{$product->id}_{$conditions['date_to']}",
                        'type' => 'availability',
                        'customer_id' => $customer->id,
                        'customer_name' => $customer->company_name ?: $customer->name,
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'product_size' => $product->size,
                        'qty' => $availableQty,
                        'date' => $conditions['date_to'],
                        'time' => null,
                        'notes' => '',
                        'created_by' => 'System',
                        'created_at' => now()
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * Get stock transfers data
     */
    private function getStockTransfers(array $conditions): array
    {
        // Get transfers from delivery table where delivery_type = 'transfer'
        $query = Delivery::with(['customer', 'fromCustomer', 'deliveryProducts.product', 'creator'])
            ->where('delivery_type', 'transfer')
            ->whereBetween('prepare_date', [$conditions['date_from'], $conditions['date_to']]);

        if (isset($conditions['customer_id'])) {
            $query->where(function ($q) use ($conditions) {
                $q->where('customer_id', $conditions['customer_id'])
                  ->orWhere('from_cust_id', $conditions['customer_id']);
            });
        }

        $transfers = $query->orderBy('prepare_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        $result = [];
        foreach ($transfers as $transfer) {
            foreach ($transfer->deliveryProducts as $deliveryProduct) {
                if (isset($conditions['product_id']) && $deliveryProduct->product_id != $conditions['product_id']) {
                    continue;
                }

                $result[] = [
                    'id' => $transfer->id,
                    'type' => 'transfer',
                    'from_customer_id' => $transfer->from_cust_id,
                    'from_customer_name' => $transfer->fromCustomer ? ($transfer->fromCustomer->company_name ?: $transfer->fromCustomer->name) : 'Unknown',
                    'to_customer_id' => $transfer->customer_id,
                    'to_customer_name' => $transfer->customer ? ($transfer->customer->company_name ?: $transfer->customer->name) : 'Unknown',
                    'product_id' => $deliveryProduct->product_id,
                    'product_name' => $deliveryProduct->product ? $deliveryProduct->product->name : 'Unknown',
                    'product_size' => $deliveryProduct->product ? $deliveryProduct->product->size : '',
                    'qty' => $deliveryProduct->delivery_qty,
                    'date' => $transfer->prepare_date,
                    'time' => null,
                    'notes' => $deliveryProduct->notes ?? '',
                    'created_by' => $transfer->creator ? $transfer->creator->name : 'Unknown',
                    'created_at' => $transfer->created_at
                ];
            }
        }

        return $result;
    }

    /**
     * Get summary data
     */
    private function getSummaryData(array $conditions): array
    {
        $dateFrom = $conditions['date_from'];
        $dateTo = $conditions['date_to'];

        // Total deliveries - count delivery products
        $deliveriesQuery = DeliveryProduct::whereHas('delivery', function($q) use ($dateFrom, $dateTo, $conditions) {
            $q->whereIn('delivery_type', ['in', 'out'])
              ->whereBetween('prepare_date', [$dateFrom, $dateTo]);
            if (isset($conditions['customer_id'])) {
                $q->where('customer_id', $conditions['customer_id']);
            }
        });
        if (isset($conditions['product_id'])) {
            $deliveriesQuery->where('product_id', $conditions['product_id']);
        }
        $totalDeliveries = $deliveriesQuery->sum('delivery_qty');

        // Total stock availability - calculate from stocks_product
        $availabilityQuery = StockProduct::whereHas('stock', function($q) use ($dateFrom, $dateTo, $conditions) {
            $q->whereBetween('t_date', [$dateFrom, $dateTo])
              ->whereNull('deleted_at');
            if (isset($conditions['customer_id'])) {
                $q->where('customer_id', $conditions['customer_id']);
            }
        })
        ->where('stock_type', 'in')
        ->whereNull('deleted_at');
        if (isset($conditions['product_id'])) {
            $availabilityQuery->where('product_id', $conditions['product_id']);
        }
        $totalAvailability = $availabilityQuery->sum('stock_qty');

        // Total transfers - count transfer delivery products
        $transfersQuery = DeliveryProduct::whereHas('delivery', function($q) use ($dateFrom, $dateTo, $conditions) {
            $q->where('delivery_type', 'transfer')
              ->whereBetween('prepare_date', [$dateFrom, $dateTo]);
            if (isset($conditions['customer_id'])) {
                $q->where(function($subQ) use ($conditions) {
                    $subQ->where('customer_id', $conditions['customer_id'])
                         ->orWhere('from_cust_id', $conditions['customer_id']);
                });
            }
        });
        if (isset($conditions['product_id'])) {
            $transfersQuery->where('product_id', $conditions['product_id']);
        }
        $totalTransfers = $transfersQuery->sum('delivery_qty');

        // Unique customers involved
        $customers = collect();
        
        // Get customers from deliveries
        $deliveryCustomers = Delivery::whereIn('delivery_type', ['in', 'out'])
            ->whereBetween('prepare_date', [$dateFrom, $dateTo])
            ->pluck('customer_id');
        $customers = $customers->merge($deliveryCustomers);
        
        // Get customers from stock availability
        $availabilityCustomers = Stock::whereBetween('t_date', [$dateFrom, $dateTo])
            ->whereNull('deleted_at')
            ->pluck('customer_id');
        $customers = $customers->merge($availabilityCustomers);
        
        // Get customers from transfers
        $transferFromCustomers = Delivery::where('delivery_type', 'transfer')
            ->whereBetween('prepare_date', [$dateFrom, $dateTo])
            ->pluck('from_cust_id');
        $transferToCustomers = Delivery::where('delivery_type', 'transfer')
            ->whereBetween('prepare_date', [$dateFrom, $dateTo])
            ->pluck('customer_id');
        $customers = $customers->merge($transferFromCustomers)->merge($transferToCustomers);
        
        $uniqueCustomers = $customers->filter()->unique()->count();

        // Unique products involved
        $products = collect();
        
        // Get products from deliveries
        $deliveryProducts = DeliveryProduct::whereHas('delivery', function($q) use ($dateFrom, $dateTo) {
            $q->whereIn('delivery_type', ['in', 'out'])
              ->whereBetween('prepare_date', [$dateFrom, $dateTo]);
        })->pluck('product_id');
        $products = $products->merge($deliveryProducts);
        
        // Get products from stock availability
        $availabilityProducts = StockProduct::whereHas('stock', function($q) use ($dateFrom, $dateTo) {
            $q->whereBetween('t_date', [$dateFrom, $dateTo])
              ->whereNull('deleted_at');
        })->whereNull('deleted_at')->pluck('product_id');
        $products = $products->merge($availabilityProducts);
        
        // Get products from transfers
        $transferProducts = DeliveryProduct::whereHas('delivery', function($q) use ($dateFrom, $dateTo) {
            $q->where('delivery_type', 'transfer')
              ->whereBetween('prepare_date', [$dateFrom, $dateTo]);
        })->pluck('product_id');
        $products = $products->merge($transferProducts);
        
        $uniqueProducts = $products->filter()->unique()->count();

        return [
            'total_deliveries' => $totalDeliveries,
            'total_availability' => $totalAvailability,
            'total_transfers' => $totalTransfers,
            'unique_customers' => $uniqueCustomers,
            'unique_products' => $uniqueProducts,
            'date_range' => [
                'from' => $dateFrom,
                'to' => $dateTo
            ]
        ];
    }

    /**
     * Get customers list for dropdown
     */
    public function getCustomers(): JsonResponse
    {
        try {
            $customers = Customer::select('id', 'name', 'company_name')
                ->orderBy('company_name')
                ->get();
            
            return response()->json([
                'success' => true,
                'message' => 'Customers retrieved successfully',
                'data' => $customers
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving customers: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve customers',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get products list for dropdown
     */
    public function getProducts(): JsonResponse
    {
        try {
            $products = Product::select('id', 'name', 'code', 'size', 'unit')
                ->orderBy('name')
                ->get();
            
            return response()->json([
                'success' => true,
                'message' => 'Products retrieved successfully',
                'data' => $products
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving products: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve products',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get daily stock reports
     */
    public function getDailyReports(Request $request): JsonResponse
    {
        try {
            $dateFrom = $request->get('date_from', now()->subDays(30)->format('Y-m-d'));
            $dateTo = $request->get('date_to', now()->format('Y-m-d'));
            $customerId = $request->get('customer_id');
            $productId = $request->get('product_id');

            $query = StockReport::with(['customer', 'product', 'creator'])
                ->whereBetween('report_date', [$dateFrom, $dateTo]);

            if ($customerId) {
                $query->where('customer_id', $customerId);
            }
            if ($productId) {
                $query->where('product_id', $productId);
            }

            $reports = $query->orderBy('report_date', 'desc')
                ->orderBy('customer_id')
                ->orderBy('product_id')
                ->get()
                ->map(function ($report) {
                    return [
                        'id' => $report->id,
                        'customer_id' => $report->customer_id,
                        'customer_name' => $report->customer ? ($report->customer->company_name ?: $report->customer->name) : 'Unknown',
                        'product_id' => $report->product_id,
                        'product_name' => $report->product ? $report->product->name : 'Unknown',
                        'product_size' => $report->product ? $report->product->size : '',
                        'report_date' => $report->report_date,
                        'stock_in_qty' => $report->stock_in_qty,
                        'transfer_received_qty' => $report->transfer_received_qty,
                        'transfer_outside_qty' => $report->transfer_outside_qty,
                        'current_availability_qty' => $report->current_availability_qty,
                        'stock_used_qty' => $report->stock_used_qty,
                        'total_stock_in' => $report->total_stock_in,
                        'net_stock_movement' => $report->net_stock_movement,
                        'notes' => $report->notes,
                        'created_by' => $report->creator ? $report->creator->name : 'Unknown',
                        'created_at' => $report->created_at,
                        'updated_at' => $report->updated_at
                    ];
                });

            return response()->json([
                'success' => true,
                'message' => 'Daily stock reports retrieved successfully',
                'data' => $reports,
                'filters' => [
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'customer_id' => $customerId,
                    'product_id' => $productId
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error retrieving daily stock reports: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve daily stock reports',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get stock report summary
     */
    public function getReportSummary(Request $request): JsonResponse
    {
        try {
            $dateFrom = $request->get('date_from', now()->subDays(30)->format('Y-m-d'));
            $dateTo = $request->get('date_to', now()->format('Y-m-d'));
            $customerId = $request->get('customer_id');
            $productId = $request->get('product_id');

            $query = StockReport::whereBetween('report_date', [$dateFrom, $dateTo]);

            if ($customerId) {
                $query->where('customer_id', $customerId);
            }
            if ($productId) {
                $query->where('product_id', $productId);
            }

            $summary = $query->selectRaw('
                SUM(stock_in_qty) as total_stock_in,
                SUM(transfer_received_qty) as total_transfer_received,
                SUM(transfer_outside_qty) as total_transfer_outside,
                SUM(current_availability_qty) as total_current_availability,
                SUM(stock_used_qty) as total_stock_used,
                COUNT(DISTINCT customer_id) as unique_customers,
                COUNT(DISTINCT product_id) as unique_products,
                COUNT(*) as total_records
            ')->first();

            return response()->json([
                'success' => true,
                'message' => 'Stock report summary retrieved successfully',
                'data' => [
                    'total_stock_in' => $summary->total_stock_in ?? 0,
                    'total_transfer_received' => $summary->total_transfer_received ?? 0,
                    'total_transfer_outside' => $summary->total_transfer_outside ?? 0,
                    'total_current_availability' => $summary->total_current_availability ?? 0,
                    'total_stock_used' => $summary->total_stock_used ?? 0,
                    'unique_customers' => $summary->unique_customers ?? 0,
                    'unique_products' => $summary->unique_products ?? 0,
                    'total_records' => $summary->total_records ?? 0,
                    'date_range' => [
                        'from' => $dateFrom,
                        'to' => $dateTo
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error retrieving stock report summary: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve stock report summary',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Manually update stock report for a specific date
     */
    public function updateReport(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'customer_id' => 'required|exists:customers,id',
                'product_id' => 'required|exists:products,id',
                'report_date' => 'required|date',
                'stock_in_qty' => 'integer|min:0',
                'transfer_received_qty' => 'integer|min:0',
                'transfer_outside_qty' => 'integer|min:0',
                'current_availability_qty' => 'integer|min:0',
                'stock_used_qty' => 'integer|min:0',
                'notes' => 'nullable|string'
            ]);

            $data = $request->only([
                'stock_in_qty', 'transfer_received_qty', 'transfer_outside_qty',
                'current_availability_qty', 'stock_used_qty', 'notes'
            ]);

            $report = StockReport::updateOrCreateReport(
                $request->customer_id,
                $request->product_id,
                $request->report_date,
                $data
            );

            return response()->json([
                'success' => true,
                'message' => 'Stock report updated successfully',
                'data' => $report
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating stock report: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update stock report',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Recalculate all stock reports for a date range
     */
    public function recalculateReports(Request $request): JsonResponse
    {
        try {
            $dateFrom = $request->get('date_from', now()->subDays(30)->format('Y-m-d'));
            $dateTo = $request->get('date_to', now()->format('Y-m-d'));

            DB::beginTransaction();

            // Get all unique combinations of customer, product, and date from stocks_product
            $stockData = StockProduct::whereHas('stock', function($q) use ($dateFrom, $dateTo) {
                $q->whereBetween('t_date', [$dateFrom, $dateTo])
                  ->whereNull('deleted_at');
            })
            ->whereNull('deleted_at')
            ->join('stocks', 'stocks_product.stock_id', '=', 'stocks.id')
            ->select('stocks.customer_id', 'stocks_product.product_id', 'stocks.t_date as report_date')
            ->distinct();

            // Get transfer data from delivery table
            $transferData = Delivery::where('delivery_type', 'transfer')
                ->whereBetween('prepare_date', [$dateFrom, $dateTo])
                ->join('delivery_products', 'delivery.id', '=', 'delivery_products.delivery_id')
                ->select('delivery.from_cust_id as customer_id', 'delivery_products.product_id', 'delivery.prepare_date as report_date')
                ->distinct()
                ->union(
                    Delivery::where('delivery_type', 'transfer')
                        ->whereBetween('prepare_date', [$dateFrom, $dateTo])
                        ->join('delivery_products', 'delivery.id', '=', 'delivery_products.delivery_id')
                        ->select('delivery.customer_id', 'delivery_products.product_id', 'delivery.prepare_date as report_date')
                        ->distinct()
                );

            // Get unique customer/product/date combinations from stocks_product
            $availabilityData = StockProduct::whereHas('stock', function($q) use ($dateFrom, $dateTo) {
                $q->whereBetween('t_date', [$dateFrom, $dateTo])
                  ->whereNull('deleted_at');
            })
            ->select('product_id')
            ->distinct()
            ->get()
            ->map(function($sp) use ($dateFrom, $dateTo) {
                // Get unique customer/product/date combinations
                $stocks = Stock::whereBetween('t_date', [$dateFrom, $dateTo])
                    ->whereNull('deleted_at')
                    ->whereHas('stockProducts', function($q) use ($sp) {
                        $q->where('product_id', $sp->product_id);
                    })
                    ->select('customer_id', 't_date as report_date')
                    ->distinct()
                    ->get();
                return $stocks->map(function($stock) use ($sp) {
                    return (object)[
                        'customer_id' => $stock->customer_id,
                        'product_id' => $sp->product_id,
                        'report_date' => $stock->report_date
                    ];
                });
            })
            ->flatten();

            // Combine all data
            $allData = collect();
            $allData = $allData->merge($stockData->get());
            $allData = $allData->merge($transferData->get());
            $allData = $allData->merge($availabilityData);

            $updatedCount = 0;

            foreach ($allData as $data) {
                $customerId = $data->customer_id;
                $productId = $data->product_id;
                $reportDate = $data->report_date;

                // Calculate stock in qty from stocks_product
                $stockInQty = StockProduct::whereHas('stock', function($q) use ($customerId, $reportDate) {
                    $q->where('customer_id', $customerId)
                      ->where('t_date', $reportDate)
                      ->whereNull('deleted_at');
                })
                ->where('product_id', $productId)
                ->where('stock_type', 'in')
                ->whereNull('deleted_at')
                ->sum('stock_qty');

                // Calculate stock used qty from stocks_product
                $stockUsedQty = StockProduct::whereHas('stock', function($q) use ($customerId, $reportDate) {
                    $q->where('customer_id', $customerId)
                      ->where('t_date', $reportDate)
                      ->whereNull('deleted_at');
                })
                ->where('product_id', $productId)
                ->where('stock_type', 'sold-out')
                ->whereNull('deleted_at')
                ->sum('stock_qty');

                // Calculate transfer received qty (from stocks table - transfers in)
                $transferReceivedQty = StockProduct::whereHas('stock', function($q) use ($customerId, $reportDate) {
                    $q->where('customer_id', $customerId)
                      ->where('t_date', $reportDate)
                      ->whereNotNull('from_cust_id')
                      ->whereNull('deleted_at');
                })
                ->where('product_id', $productId)
                ->where('stock_type', 'in')
                ->whereNull('deleted_at')
                ->sum('stock_qty');

                // Calculate transfer outside qty (from stocks table - transfers out)
                $transferOutsideQty = StockProduct::whereHas('stock', function($q) use ($customerId, $reportDate) {
                    $q->where('customer_id', $customerId)
                      ->where('t_date', $reportDate)
                      ->whereNotNull('from_cust_id')
                      ->whereNull('deleted_at');
                })
                ->where('product_id', $productId)
                ->where('stock_type', 'out')
                ->whereNull('deleted_at')
                ->sum('stock_qty');

                // Calculate current availability qty using dynamic calculation
                $currentAvailabilityQty = StockAvailabilityService::calculateAvailableStock(
                    $customerId,
                    $productId,
                    $reportDate
                );

                // Update or create report
                StockReport::updateOrCreateReport($customerId, $productId, $reportDate, [
                    'stock_in_qty' => $stockInQty,
                    'stock_used_qty' => $stockUsedQty,
                    'transfer_received_qty' => $transferReceivedQty,
                    'transfer_outside_qty' => $transferOutsideQty,
                    'current_availability_qty' => $currentAvailabilityQty
                ]);

                $updatedCount++;
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Stock reports recalculated successfully. Updated {$updatedCount} records.",
                'data' => [
                    'updated_records' => $updatedCount,
                    'date_range' => [
                        'from' => $dateFrom,
                        'to' => $dateTo
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error recalculating stock reports: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to recalculate stock reports',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update stock report when stock record is created/updated/deleted
     */
    public static function updateStockReport($customerId, $productId, $date)
    {
        try {
            // Calculate stock in qty from stocks_product
            $stockInQty = StockProduct::whereHas('stock', function($q) use ($customerId, $date) {
                $q->where('customer_id', $customerId)
                  ->where('t_date', $date)
                  ->whereNull('deleted_at');
            })
            ->where('product_id', $productId)
            ->where('stock_type', 'in')
            ->whereNull('deleted_at')
            ->sum('stock_qty');

            // Calculate stock used qty from stocks_product
            $stockUsedQty = StockProduct::whereHas('stock', function($q) use ($customerId, $date) {
                $q->where('customer_id', $customerId)
                  ->where('t_date', $date)
                  ->whereNull('deleted_at');
            })
            ->where('product_id', $productId)
            ->where('stock_type', 'sold-out')
            ->whereNull('deleted_at')
            ->sum('stock_qty');

            // Calculate transfer received qty (from stocks table - transfers in)
            $transferReceivedQty = StockProduct::whereHas('stock', function($q) use ($customerId, $date) {
                $q->where('customer_id', $customerId)
                  ->where('t_date', $date)
                  ->whereNotNull('from_cust_id')
                  ->whereNull('deleted_at');
            })
            ->where('product_id', $productId)
            ->where('stock_type', 'in')
            ->whereNull('deleted_at')
            ->sum('stock_qty');

            // Calculate transfer outside qty (from stocks table - transfers out)
            $transferOutsideQty = StockProduct::whereHas('stock', function($q) use ($customerId, $date) {
                $q->where('customer_id', $customerId)
                  ->where('t_date', $date)
                  ->whereNotNull('from_cust_id')
                  ->whereNull('deleted_at');
            })
            ->where('product_id', $productId)
            ->where('stock_type', 'out')
            ->whereNull('deleted_at')
            ->sum('stock_qty');

            // Calculate current availability qty using dynamic calculation
            $currentAvailabilityQty = StockAvailabilityService::calculateAvailableStock(
                $customerId,
                $productId,
                $date
            );

            // Update or create stock report
            StockReport::updateOrCreateReport($customerId, $productId, $date, [
                'stock_in_qty' => $stockInQty,
                'stock_used_qty' => $stockUsedQty,
                'transfer_received_qty' => $transferReceivedQty,
                'transfer_outside_qty' => $transferOutsideQty,
                'current_availability_qty' => $currentAvailabilityQty
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating stock report: ' . $e->getMessage());
        }
    }

    /**
     * Get all stock products records (All Records tab)
     */
    public function getAllStockProducts(Request $request): JsonResponse
    {
        try {
            $query = StockProduct::with(['stock.customer', 'stock.fromCustomer', 'stock.modifier', 'product', 'stock.creator'])
                ->whereNull('deleted_at');

            // Filters
            if ($request->has('customer_id') && $request->customer_id) {
                $query->whereHas('stock', function($q) use ($request) {
                    $q->where('customer_id', $request->customer_id);
                });
            }
            if ($request->has('product_id') && $request->product_id) {
                $query->where('product_id', $request->product_id);
            }
            if ($request->has('stock_type') && $request->stock_type) {
                $query->where('stock_type', $request->stock_type);
            }
            if ($request->has('date_from') && $request->date_from) {
                $query->whereHas('stock', function($q) use ($request) {
                    $q->whereDate('t_date', '>=', $request->date_from);
                });
            }
            if ($request->has('date_to') && $request->date_to) {
                $query->whereHas('stock', function($q) use ($request) {
                    $q->whereDate('t_date', '<=', $request->date_to);
                });
            }

            // Check if all data is requested (no pagination)
            if ($request->has('all') && $request->get('all') === 'true') {
                $stockProducts = $query->orderBy('id', 'desc')->get();
                return response()->json([
                    'success' => true,
                    'message' => 'All stock products retrieved successfully',
                    'data' => $stockProducts
                ]);
            }

            $perPage = $request->get('per_page', 15);
            $stockProducts = $query->orderBy('id', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'Stock products retrieved successfully',
                'data' => $stockProducts
            ]);

        } catch (\Exception $e) {
            Log::error('Error retrieving all stock products: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve stock products',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get delivery products (Deliveries tab)
     */
    public function getDeliveryProducts(Request $request): JsonResponse
    {
        try {
            $query = DeliveryProduct::with(['delivery.customer', 'delivery.fromCustomer', 'delivery.modifier', 'product', 'delivery.creator'])
                ->whereNull('deleted_at');

            // Filters
            if ($request->has('customer_id') && $request->customer_id) {
                $query->whereHas('delivery', function($q) use ($request) {
                    $q->where('customer_id', $request->customer_id);
                });
            }
            if ($request->has('product_id') && $request->product_id) {
                $query->where('product_id', $request->product_id);
            }
            if ($request->has('delivery_type') && $request->delivery_type) {
                $query->whereHas('delivery', function($q) use ($request) {
                    $q->where('delivery_type', $request->delivery_type);
                });
            }
            if ($request->has('date_from') && $request->date_from) {
                $query->whereHas('delivery', function($q) use ($request) {
                    $q->whereDate('prepare_date', '>=', $request->date_from);
                });
            }
            if ($request->has('date_to') && $request->date_to) {
                $query->whereHas('delivery', function($q) use ($request) {
                    $q->whereDate('prepare_date', '<=', $request->date_to);
                });
            }

            // Check if all data is requested (no pagination)
            if ($request->has('all') && $request->get('all') === 'true') {
                $deliveryProducts = $query->orderBy('id', 'desc')->get();
                return response()->json([
                    'success' => true,
                    'message' => 'Delivery products retrieved successfully',
                    'data' => $deliveryProducts
                ]);
            }

            $perPage = $request->get('per_page', 15);
            $deliveryProducts = $query->orderBy('id', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'Delivery products retrieved successfully',
                'data' => $deliveryProducts
            ]);

        } catch (\Exception $e) {
            Log::error('Error retrieving delivery products: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve delivery products',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get stock availability for all customers (Stock Availability tab)
     */
    public function getStockAvailabilityReport(Request $request): JsonResponse
    {
        try {
            // Get customer filter
            $customerId = $request->get('customer_id');
            $productId = $request->get('product_id');
            $showZero = $request->get('show_zero', false);
            
            // Get active customers (filtered if customer_id provided)
            $customersQuery = Customer::where('status', 'active');
            if ($customerId) {
                $customersQuery->where('id', $customerId);
            }
            $customers = $customersQuery->get();
            
            // Get active products (filtered if product_id provided)
            $productsQuery = Product::where('status', 'active');
            if ($productId) {
                $productsQuery->where('id', $productId);
            }
            $products = $productsQuery->get();

            $availabilityData = [];

            foreach ($customers as $customer) {
                foreach ($products as $product) {
                    // Calculate current availability up to current day
                    $availableQty = StockAvailabilityService::calculateAvailableStock(
                        $customer->id,
                        $product->id,
                        now()->format('Y-m-d')
                    );

                    if ($availableQty > 0 || $showZero) {
                        // Get minimum_threshold - ensure it's a number
                        $minimumThreshold = $product->getAttribute('minimum_threshold');
                        if ($minimumThreshold === null || $minimumThreshold === '' || $minimumThreshold === false) {
                            $minimumThreshold = 0;
                        } else {
                            $minimumThreshold = (float)$minimumThreshold;
                        }
                        
                        $availabilityData[] = [
                            'customer_id' => $customer->id,
                            'customer_name' => !empty($customer->company_name) ? $customer->company_name : $customer->name,
                            'product_id' => $product->id,
                            'product_name' => $product->name,
                            'product_code' => $product->code,
                            'product_size' => $product->size,
                            'product_unit' => $product->unit,
                            'available_qty' => $availableQty,
                            'minimum_threshold' => $minimumThreshold,
                            'as_of_date' => now()->format('Y-m-d')
                        ];
                    }
                }
            }

            // Sort by customer name, then product name
            usort($availabilityData, function($a, $b) {
                if ($a['customer_name'] === $b['customer_name']) {
                    return strcmp($a['product_name'], $b['product_name']);
                }
                return strcmp($a['customer_name'], $b['customer_name']);
            });

            return response()->json([
                'success' => true,
                'message' => 'Stock availability report retrieved successfully',
                'data' => $availabilityData
            ]);

        } catch (\Exception $e) {
            Log::error('Error retrieving stock availability report: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve stock availability report',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get transfer products (Transfers tab)
     */
    public function getTransferProducts(Request $request): JsonResponse
    {
        try {
            $query = DeliveryProduct::with(['delivery.customer', 'delivery.fromCustomer', 'delivery.modifier', 'product', 'delivery.creator'])
                ->whereHas('delivery', function($q) {
                    $q->where('delivery_type', 'transfer');
                })
                ->whereNull('deleted_at');

            // Filters
            if ($request->has('customer_id') && $request->customer_id) {
                $query->whereHas('delivery', function($q) use ($request) {
                    $q->where(function($subQ) use ($request) {
                        $subQ->where('customer_id', $request->customer_id)
                             ->orWhere('from_cust_id', $request->customer_id);
                    });
                });
            }
            if ($request->has('product_id') && $request->product_id) {
                $query->where('product_id', $request->product_id);
            }
            if ($request->has('date_from') && $request->date_from) {
                $query->whereHas('delivery', function($q) use ($request) {
                    $q->whereDate('prepare_date', '>=', $request->date_from);
                });
            }
            if ($request->has('date_to') && $request->date_to) {
                $query->whereHas('delivery', function($q) use ($request) {
                    $q->whereDate('prepare_date', '<=', $request->date_to);
                });
            }

            // Check if all data is requested (no pagination)
            if ($request->has('all') && $request->get('all') === 'true') {
                $transferProducts = $query->orderBy('id', 'desc')->get();
                return response()->json([
                    'success' => true,
                    'message' => 'Transfer products retrieved successfully',
                    'data' => $transferProducts
                ]);
            }

            $perPage = $request->get('per_page', 15);
            $transferProducts = $query->orderBy('id', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'Transfer products retrieved successfully',
                'data' => $transferProducts
            ]);

        } catch (\Exception $e) {
            Log::error('Error retrieving transfer products: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve transfer products',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export stock report to Excel (CSV format)
     */
    public function exportExcel(Request $request): StreamedResponse
    {
        try {
            $tab = $request->get('tab', 'all'); // 'all', 'deliveries', 'transfers'
            $customerId = $request->get('customer_id');
            $productId = $request->get('product_id');
            $dateFrom = $request->get('date_from');
            $dateTo = $request->get('date_to');

            $data = [];
            $headers = [];

            if ($tab === 'all') {
                $query = StockProduct::with(['stock.customer', 'product'])
                    ->whereNull('deleted_at');

                if ($customerId) {
                    $query->whereHas('stock', function($q) use ($customerId) {
                        $q->where('customer_id', $customerId);
                    });
                }
                if ($productId) {
                    $query->where('product_id', $productId);
                }
                if ($dateFrom) {
                    $query->whereHas('stock', function($q) use ($dateFrom) {
                        $q->whereDate('t_date', '>=', $dateFrom);
                    });
                }
                if ($dateTo) {
                    $query->whereHas('stock', function($q) use ($dateTo) {
                        $q->whereDate('t_date', '<=', $dateTo);
                    });
                }

                $stockProducts = $query->orderBy('id', 'desc')->get();

                $headers = ['SI No', 'Date', 'Customer', 'Product', 'Product Code', 'Size', 'Stock Type', 'Quantity', 'Unit'];
                
                $sno = 1;
                foreach ($stockProducts as $sp) {
                    $data[] = [
                        $sno++,
                        $sp->stock ? $sp->stock->t_date : '',
                        $sp->stock && $sp->stock->customer ? ($sp->stock->customer->company_name ?: $sp->stock->customer->name) : 'Unknown',
                        $sp->product ? $sp->product->name : 'Unknown',
                        $sp->product ? $sp->product->code : '',
                        $sp->product ? $sp->product->size : '',
                        ucfirst(str_replace('-', ' ', $sp->stock_type ?? '')),
                        $sp->stock_qty ?? 0,
                        $sp->product ? $sp->product->unit : ''
                    ];
                }
            } elseif ($tab === 'deliveries') {
                $query = DeliveryProduct::with(['delivery.customer', 'product'])
                    ->whereNull('deleted_at');

                if ($customerId) {
                    $query->whereHas('delivery', function($q) use ($customerId) {
                        $q->where('customer_id', $customerId);
                    });
                }
                if ($productId) {
                    $query->where('product_id', $productId);
                }
                if ($dateFrom) {
                    $query->whereHas('delivery', function($q) use ($dateFrom) {
                        $q->whereDate('prepare_date', '>=', $dateFrom);
                    });
                }
                if ($dateTo) {
                    $query->whereHas('delivery', function($q) use ($dateTo) {
                        $q->whereDate('prepare_date', '<=', $dateTo);
                    });
                }

                $deliveryProducts = $query->orderBy('id', 'desc')->get();

                $headers = ['SI No', 'Date', 'Customer', 'Product', 'Product Code', 'Size', 'Delivery Type', 'Quantity', 'Unit'];
                
                $sno = 1;
                foreach ($deliveryProducts as $dp) {
                    $data[] = [
                        $sno++,
                        $dp->delivery ? $dp->delivery->prepare_date : '',
                        $dp->delivery && $dp->delivery->customer ? ($dp->delivery->customer->company_name ?: $dp->delivery->customer->name) : 'Unknown',
                        $dp->product ? $dp->product->name : 'Unknown',
                        $dp->product ? $dp->product->code : '',
                        $dp->product ? $dp->product->size : '',
                        ucfirst($dp->delivery ? $dp->delivery->delivery_type : ''),
                        $dp->delivery_qty ?? 0,
                        $dp->product ? $dp->product->unit : ''
                    ];
                }
            } elseif ($tab === 'transfers') {
                $query = DeliveryProduct::with(['delivery.customer', 'delivery.fromCustomer', 'product'])
                    ->whereHas('delivery', function($q) {
                        $q->where('delivery_type', 'transfer');
                    })
                    ->whereNull('deleted_at');

                if ($customerId) {
                    $query->whereHas('delivery', function($q) use ($customerId) {
                        $q->where(function($subQ) use ($customerId) {
                            $subQ->where('customer_id', $customerId)
                                 ->orWhere('from_cust_id', $customerId);
                        });
                    });
                }
                if ($productId) {
                    $query->where('product_id', $productId);
                }
                if ($dateFrom) {
                    $query->whereHas('delivery', function($q) use ($dateFrom) {
                        $q->whereDate('prepare_date', '>=', $dateFrom);
                    });
                }
                if ($dateTo) {
                    $query->whereHas('delivery', function($q) use ($dateTo) {
                        $q->whereDate('prepare_date', '<=', $dateTo);
                    });
                }

                $transferProducts = $query->orderBy('id', 'desc')->get();

                $headers = ['SI No', 'Date', 'From Customer', 'To Customer', 'Product', 'Product Code', 'Size', 'Quantity', 'Unit'];
                
                $sno = 1;
                foreach ($transferProducts as $tp) {
                    $data[] = [
                        $sno++,
                        $tp->delivery ? $tp->delivery->prepare_date : '',
                        $tp->delivery && $tp->delivery->fromCustomer ? ($tp->delivery->fromCustomer->company_name ?: $tp->delivery->fromCustomer->name) : 'Unknown',
                        $tp->delivery && $tp->delivery->customer ? ($tp->delivery->customer->company_name ?: $tp->delivery->customer->name) : 'Unknown',
                        $tp->product ? $tp->product->name : 'Unknown',
                        $tp->product ? $tp->product->code : '',
                        $tp->product ? $tp->product->size : '',
                        $tp->delivery_qty ?? 0,
                        $tp->product ? $tp->product->unit : ''
                    ];
                }
            }

            $filename = 'stock_report_' . $tab . '_' . date('Y-m-d_His') . '.csv';

            $responseHeaders = [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Cache-Control' => 'max-age=0',
            ];

            $callback = function () use ($headers, $data) {
                $output = fopen('php://output', 'w');
                
                // Add BOM for UTF-8
                fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

                $escapeCsv = function ($value) {
                    if (is_string($value) && (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n") || str_contains($value, "\r"))) {
                        return '"' . str_replace('"', '""', $value) . '"';
                    }
                    return $value ?? '';
                };

                // Header row
                fputcsv($output, array_map($escapeCsv, $headers));

                // Data rows
                foreach ($data as $row) {
                    fputcsv($output, array_map($escapeCsv, $row));
                }

                fclose($output);
            };

            return response()->stream($callback, 200, $responseHeaders);

        } catch (\Exception $e) {
            Log::error('Error exporting stock report to Excel: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to export stock report',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export stock report to PDF (HTML format for printing)
     */
    public function exportPdf(Request $request): Response
    {
        try {
            $tab = $request->get('tab', 'all');
            $customerId = $request->get('customer_id');
            $productId = $request->get('product_id');
            $dateFrom = $request->get('date_from');
            $dateTo = $request->get('date_to');

            $data = [];
            $headers = [];
            $title = 'Stock Report - ' . ucfirst($tab);

            if ($tab === 'all') {
                $query = StockProduct::with(['stock.customer', 'product'])
                    ->whereNull('deleted_at');

                if ($customerId) {
                    $query->whereHas('stock', function($q) use ($customerId) {
                        $q->where('customer_id', $customerId);
                    });
                }
                if ($productId) {
                    $query->where('product_id', $productId);
                }
                if ($dateFrom) {
                    $query->whereHas('stock', function($q) use ($dateFrom) {
                        $q->whereDate('t_date', '>=', $dateFrom);
                    });
                }
                if ($dateTo) {
                    $query->whereHas('stock', function($q) use ($dateTo) {
                        $q->whereDate('t_date', '<=', $dateTo);
                    });
                }

                $stockProducts = $query->orderBy('id', 'desc')->get();

                $headers = ['SI No', 'Date', 'Customer', 'Product', 'Product Code', 'Size', 'Stock Type', 'Quantity', 'Unit'];
                
                $sno = 1;
                foreach ($stockProducts as $sp) {
                    $data[] = [
                        $sno++,
                        $sp->stock ? $sp->stock->t_date : '',
                        $sp->stock && $sp->stock->customer ? ($sp->stock->customer->company_name ?: $sp->stock->customer->name) : 'Unknown',
                        $sp->product ? $sp->product->name : 'Unknown',
                        $sp->product ? $sp->product->code : '',
                        $sp->product ? $sp->product->size : '',
                        ucfirst(str_replace('-', ' ', $sp->stock_type ?? '')),
                        $sp->stock_qty ?? 0,
                        $sp->product ? $sp->product->unit : ''
                    ];
                }
            } elseif ($tab === 'deliveries') {
                $query = DeliveryProduct::with(['delivery.customer', 'product'])
                    ->whereNull('deleted_at');

                if ($customerId) {
                    $query->whereHas('delivery', function($q) use ($customerId) {
                        $q->where('customer_id', $customerId);
                    });
                }
                if ($productId) {
                    $query->where('product_id', $productId);
                }
                if ($dateFrom) {
                    $query->whereHas('delivery', function($q) use ($dateFrom) {
                        $q->whereDate('prepare_date', '>=', $dateFrom);
                    });
                }
                if ($dateTo) {
                    $query->whereHas('delivery', function($q) use ($dateTo) {
                        $q->whereDate('prepare_date', '<=', $dateTo);
                    });
                }

                $deliveryProducts = $query->orderBy('id', 'desc')->get();

                $headers = ['SI No', 'Date', 'Customer', 'Product', 'Product Code', 'Size', 'Delivery Type', 'Quantity', 'Unit'];
                
                $sno = 1;
                foreach ($deliveryProducts as $dp) {
                    $data[] = [
                        $sno++,
                        $dp->delivery ? $dp->delivery->prepare_date : '',
                        $dp->delivery && $dp->delivery->customer ? ($dp->delivery->customer->company_name ?: $dp->delivery->customer->name) : 'Unknown',
                        $dp->product ? $dp->product->name : 'Unknown',
                        $dp->product ? $dp->product->code : '',
                        $dp->product ? $dp->product->size : '',
                        ucfirst($dp->delivery ? $dp->delivery->delivery_type : ''),
                        $dp->delivery_qty ?? 0,
                        $dp->product ? $dp->product->unit : ''
                    ];
                }
            } elseif ($tab === 'transfers') {
                $query = DeliveryProduct::with(['delivery.customer', 'delivery.fromCustomer', 'product'])
                    ->whereHas('delivery', function($q) {
                        $q->where('delivery_type', 'transfer');
                    })
                    ->whereNull('deleted_at');

                if ($customerId) {
                    $query->whereHas('delivery', function($q) use ($customerId) {
                        $q->where(function($subQ) use ($customerId) {
                            $subQ->where('customer_id', $customerId)
                                 ->orWhere('from_cust_id', $customerId);
                        });
                    });
                }
                if ($productId) {
                    $query->where('product_id', $productId);
                }
                if ($dateFrom) {
                    $query->whereHas('delivery', function($q) use ($dateFrom) {
                        $q->whereDate('prepare_date', '>=', $dateFrom);
                    });
                }
                if ($dateTo) {
                    $query->whereHas('delivery', function($q) use ($dateTo) {
                        $q->whereDate('prepare_date', '<=', $dateTo);
                    });
                }

                $transferProducts = $query->orderBy('id', 'desc')->get();

                $headers = ['SI No', 'Date', 'From Customer', 'To Customer', 'Product', 'Product Code', 'Size', 'Quantity', 'Unit'];
                
                $sno = 1;
                foreach ($transferProducts as $tp) {
                    $data[] = [
                        $sno++,
                        $tp->delivery ? $tp->delivery->prepare_date : '',
                        $tp->delivery && $tp->delivery->fromCustomer ? ($tp->delivery->fromCustomer->company_name ?: $tp->delivery->fromCustomer->name) : 'Unknown',
                        $tp->delivery && $tp->delivery->customer ? ($tp->delivery->customer->company_name ?: $tp->delivery->customer->name) : 'Unknown',
                        $tp->product ? $tp->product->name : 'Unknown',
                        $tp->product ? $tp->product->code : '',
                        $tp->product ? $tp->product->size : '',
                        $tp->delivery_qty ?? 0,
                        $tp->product ? $tp->product->unit : ''
                    ];
                }
            }

            // Generate HTML content for PDF
            $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>EBMS - ' . htmlspecialchars($title) . '</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 10px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { border: 1px solid #ddd; padding: 6px; text-align: left; }
        th { background-color: #dc3545; color: white; font-weight: bold; }
        .report-title { text-align: center; font-size: 18px; font-weight: bold; margin-bottom: 5px; }
        .report-subtitle { text-align: center; font-size: 14px; color: #6c757d; margin-bottom: 10px; }
        .report-meta { text-align: center; font-size: 9px; color: #6c757d; margin-bottom: 15px; }
    </style>
</head>
<body>
    <div class="report-title">EBMS</div>
    <div class="report-subtitle">' . htmlspecialchars($title) . '</div>
    <div class="report-meta">Generated on: ' . date('d/m/Y H:i:s') . '</div>
    <table>
        <thead>
            <tr>';

            foreach ($headers as $header) {
                $html .= '<th>' . htmlspecialchars($header) . '</th>';
            }

            $html .= '</tr>
        </thead>
        <tbody>';

            foreach ($data as $row) {
                $html .= '<tr>';
                foreach ($row as $cell) {
                    $html .= '<td>' . htmlspecialchars($cell) . '</td>';
                }
                $html .= '</tr>';
            }

            $html .= '</tbody>
    </table>
</body>
</html>';

            return response($html, 200)
                ->header('Content-Type', 'text/html; charset=UTF-8');

        } catch (\Exception $e) {
            Log::error('Error exporting stock report to PDF: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to export stock report to PDF',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
