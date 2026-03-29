<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Models\StockTransaction;
use App\Models\Vendor;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\Response;

class StockController extends Controller
{
    /**
     * Display a listing of stocks
     */
    public function index(Request $request): JsonResponse
    {
        $query = Stock::with(['vendor', 'product']);

        // Filter by vendor
        if ($request->has('vendor_id')) {
            $query->where('vendor_id', $request->get('vendor_id'));
        }

        // Filter by product
        if ($request->has('product_id')) {
            $query->where('product_id', $request->get('product_id'));
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->get('status'));
        }

        // Filter low stock
        if ($request->has('low_stock') && $request->get('low_stock')) {
            $query->lowStock();
        }

        $stocks = $query->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $stocks
        ]);
    }

    /**
     * Store a newly created stock
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'vendor_id' => 'required|exists:vendors,id',
            'product_id' => 'required|exists:products,id',
            'current_quantity' => 'required|numeric|min:0',
            'minimum_threshold' => 'required|numeric|min:0',
            'maximum_capacity' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        // Check if stock already exists for this vendor-product combination
        $existingStock = Stock::where('vendor_id', $request->vendor_id)
            ->where('product_id', $request->product_id)
            ->first();

        if ($existingStock) {
            return response()->json([
                'success' => false,
                'message' => 'Stock already exists for this vendor-product combination'
            ], 400);
        }

        $stock = Stock::create($request->all());
        $stock->updateStatus();
        $stock->load(['vendor', 'product']);

        return response()->json([
            'success' => true,
            'message' => 'Stock created successfully',
            'data' => [
                'stock' => $stock
            ]
        ], 201);
    }

    /**
     * Display the specified stock
     */
    public function show(Stock $stock): JsonResponse
    {
        $stock->load(['vendor', 'product', 'transactions.user']);
        
        return response()->json([
            'success' => true,
            'data' => [
                'stock' => $stock
            ]
        ]);
    }

    /**
     * Update the specified stock
     */
    public function update(Request $request, Stock $stock): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'current_quantity' => 'sometimes|required|numeric|min:0',
            'minimum_threshold' => 'sometimes|required|numeric|min:0',
            'maximum_capacity' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        $stock->update($request->all());
        $stock->updateStatus();
        $stock->load(['vendor', 'product']);

        return response()->json([
            'success' => true,
            'message' => 'Stock updated successfully',
            'data' => [
                'stock' => $stock
            ]
        ]);
    }

    /**
     * Record stock usage
     */
    public function recordUsage(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'vendor_id' => 'required|exists:vendors,id',
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        $stock = Stock::where('vendor_id', $request->vendor_id)
            ->where('product_id', $request->product_id)
            ->first();

        if (!$stock) {
            return response()->json([
                'success' => false,
                'message' => 'Stock not found for this vendor-product combination'
            ], 404);
        }

        if ($stock->current_quantity < $request->quantity) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient stock. Available: ' . $stock->current_quantity
            ], 400);
        }

        DB::transaction(function () use ($request, $stock) {
            $previousQuantity = $stock->current_quantity;
            $newQuantity = $previousQuantity - $request->quantity;

            // Update stock
            $stock->update(['current_quantity' => $newQuantity]);
            $stock->updateStatus();

            // Record transaction
            StockTransaction::create([
                'user_id' => Auth::id(),
                'vendor_id' => $request->vendor_id,
                'product_id' => $request->product_id,
                'type' => 'usage',
                'quantity' => $request->quantity,
                'previous_quantity' => $previousQuantity,
                'new_quantity' => $newQuantity,
                'notes' => $request->notes,
            ]);
        });

        $stock->load(['vendor', 'product']);

        return response()->json([
            'success' => true,
            'message' => 'Stock usage recorded successfully',
            'data' => [
                'stock' => $stock
            ]
        ]);
    }

    /**
     * Transfer stock between vendors
     */
    public function transfer(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'from_vendor_id' => 'required|exists:vendors,id',
            'to_vendor_id' => 'required|exists:vendors,id',
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        if ($request->from_vendor_id === $request->to_vendor_id) {
            return response()->json([
                'success' => false,
                'message' => 'Source and destination vendors cannot be the same'
            ], 400);
        }

        $fromStock = Stock::where('vendor_id', $request->from_vendor_id)
            ->where('product_id', $request->product_id)
            ->first();

        if (!$fromStock) {
            return response()->json([
                'success' => false,
                'message' => 'Stock not found in source vendor'
            ], 404);
        }

        if ($fromStock->current_quantity < $request->quantity) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient stock in source vendor. Available: ' . $fromStock->current_quantity
            ], 400);
        }

        DB::transaction(function () use ($request, $fromStock) {
            // Update source stock
            $fromPreviousQuantity = $fromStock->current_quantity;
            $fromNewQuantity = $fromPreviousQuantity - $request->quantity;
            $fromStock->update(['current_quantity' => $fromNewQuantity]);
            $fromStock->updateStatus();

            // Get or create destination stock
            $toStock = Stock::firstOrCreate(
                [
                    'vendor_id' => $request->to_vendor_id,
                    'product_id' => $request->product_id,
                ],
                [
                    'current_quantity' => 0,
                    'minimum_threshold' => 0,
                ]
            );

            $toPreviousQuantity = $toStock->current_quantity;
            $toNewQuantity = $toPreviousQuantity + $request->quantity;
            $toStock->update(['current_quantity' => $toNewQuantity]);
            $toStock->updateStatus();

            // Record transactions
            StockTransaction::create([
                'user_id' => Auth::id(),
                'vendor_id' => $request->from_vendor_id,
                'product_id' => $request->product_id,
                'type' => 'transfer_out',
                'quantity' => $request->quantity,
                'previous_quantity' => $fromPreviousQuantity,
                'new_quantity' => $fromNewQuantity,
                'from_vendor_id' => $request->from_vendor_id,
                'to_vendor_id' => $request->to_vendor_id,
                'notes' => $request->notes,
            ]);

            StockTransaction::create([
                'user_id' => Auth::id(),
                'vendor_id' => $request->to_vendor_id,
                'product_id' => $request->product_id,
                'type' => 'transfer_in',
                'quantity' => $request->quantity,
                'previous_quantity' => $toPreviousQuantity,
                'new_quantity' => $toNewQuantity,
                'from_vendor_id' => $request->from_vendor_id,
                'to_vendor_id' => $request->to_vendor_id,
                'notes' => $request->notes,
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Stock transfer completed successfully'
        ]);
    }

    /**
     * Get stock alerts (low stock, out of stock)
     */
    public function alerts(): JsonResponse
    {
        $lowStock = Stock::with(['vendor', 'product'])
            ->lowStock()
            ->get();

        $outOfStock = Stock::with(['vendor', 'product'])
            ->outOfStock()
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'low_stock' => $lowStock,
                'out_of_stock' => $outOfStock,
                'total_alerts' => $lowStock->count() + $outOfStock->count()
            ]
        ]);
    }

    /**
     * Get stock transactions
     */
    public function transactions(Request $request): JsonResponse
    {
        $query = StockTransaction::with(['user', 'vendor', 'product', 'fromVendor', 'toVendor']);

        // Filter by vendor
        if ($request->has('vendor_id')) {
            $query->where('vendor_id', $request->get('vendor_id'));
        }

        // Filter by product
        if ($request->has('product_id')) {
            $query->where('product_id', $request->get('product_id'));
        }

        // Filter by type
        if ($request->has('type')) {
            $query->where('type', $request->get('type'));
        }

        // Filter by date range
        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('created_at', [$request->get('start_date'), $request->get('end_date')]);
        }

        $transactions = $query->orderBy('created_at', 'desc')
                            ->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $transactions
        ]);
    }

    /**
     * Get stock-in records
     */
    public function stockIn(Request $request): JsonResponse
    {
        try {
            // Get stock-in records (customer deliveries and transfers)
            // Include both regular stock-in (from_cust_id is NULL) and transfer stock-in (from_cust_id is NOT NULL)
            // Must have stockProducts with stock_type = 'in'
            $query = Stock::with(['customer.customerGroup:id,name', 'customer', 'fromCustomer', 'stockProducts.product', 'creator'])
                ->whereHas('stockProducts', function($q) {
                    $q->where('stock_type', 'in')
                      ->whereNull('deleted_at'); // Exclude soft-deleted stock products
                });

            // Search functionality
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->whereHas('customer', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                          ->orWhere('company_name', 'like', "%{$search}%")
                          ->orWhereHas('customerGroup', function ($gq) use ($search) {
                              $gq->where('name', 'like', "%{$search}%");
                          });
                    })->orWhereHas('stockProducts.product', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                          ->orWhere('code', 'like', "%{$search}%");
                    });
                });
            }

            // Filters
            if ($request->has('customer_id') && $request->customer_id) {
                $query->where('customer_id', $request->customer_id);
            }
            if ($request->has('date_from') && $request->date_from) {
                $query->whereDate('t_date', '>=', $request->date_from);
            }
            if ($request->has('date_to') && $request->date_to) {
                $query->whereDate('t_date', '<=', $request->date_to);
            }
            if ($request->has('status') && $request->status) {
                $query->where('status', $request->status);
            }

            // Check if all data is requested (no pagination)
            if ($request->has('all') && $request->get('all') === 'true') {
                $stocks = $query->orderBy('t_date', 'desc')->orderBy('created_at', 'desc')->get();
                return response()->json([
                    'success' => true,
                    'message' => 'Stock-in records retrieved successfully',
                    'data' => $stocks
                ]);
            }

            $perPage = $request->get('per_page', 15);
            $stocks = $query->orderBy('t_date', 'desc')->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'Stock-in records retrieved successfully',
                'data' => $stocks
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve stock-in records',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create stock-in record
     */
    public function createStockIn(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'customer_id' => 'required|exists:customers,id',
                't_date' => 'required|date',
                'notes' => 'nullable|string|max:1000',
                'products' => 'required|array|min:1',
                'products.*.product_id' => 'required|exists:products,id',
                'products.*.stock_qty' => 'required|integer|min:1'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $data = $request->all();
            $data['created_by'] = Auth::id();

            DB::transaction(function () use ($data, &$stock) {
                // Create stock record
                $stock = Stock::create([
                    'customer_id' => $data['customer_id'],
                    'transfer_status' => 'stock_in',
                    'from_cust_id' => null,
                    't_date' => $data['t_date'],
                    'stock_type' => 'in',
                    'created_by' => Auth::id(),
                    'status' => 'active'
                ]);

                // Create stock product records
                foreach ($data['products'] as $product) {
                    \App\Models\StockProduct::create([
                        'stock_id' => $stock->id,
                        'product_id' => $product['product_id'],
                        'stock_qty' => $product['stock_qty'],
                        'stock_type' => 'in'
                    ]);
                }
            });

            // Stock availability is now calculated directly from stocks_product table
            // No need to update stock_availability table - it's calculated on-the-fly

            return response()->json([
                'success' => true,
                'message' => 'Stock-in record created successfully',
                'data' => $stock->load(['customer', 'stockProducts.product', 'creator'])
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create stock-in record',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update stock-in record
     */
    public function updateStockIn(Request $request, $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'customer_id' => 'sometimes|required|exists:customers,id',
                't_date' => 'sometimes|required|date',
                'notes' => 'nullable|string|max:1000',
                'products' => 'sometimes|array|min:1',
                'products.*.product_id' => 'required_with:products|exists:products,id',
                'products.*.stock_qty' => 'required_with:products|integer|min:1'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::transaction(function () use ($request, $id, &$stock) {
                $stock = Stock::with('stockProducts')->findOrFail($id);
                
                // Store original data
                $originalCustomerId = $stock->customer_id;
                $originalDate = $stock->t_date;
                $originalProducts = $stock->stockProducts->pluck('product_id')->toArray();

                // Update the stock record
                $stock->update([
                    'customer_id' => $request->customer_id ?? $originalCustomerId,
                    't_date' => $request->t_date ?? $originalDate,
                    'modified_by' => Auth::id()
                ]);

                // Delete existing stock products
                \App\Models\StockProduct::where('stock_id', $stock->id)->delete();

                // Create new stock product records
                $products = $request->products ?? $stock->stockProducts->map(function($stockProduct) {
                    return [
                        'product_id' => $stockProduct->product_id,
                        'stock_qty' => $stockProduct->stock_qty
                    ];
                })->toArray();

                foreach ($products as $product) {
                    \App\Models\StockProduct::create([
                        'stock_id' => $stock->id,
                        'product_id' => $product['product_id'],
                        'stock_qty' => $product['stock_qty'],
                        'stock_type' => 'in'
                    ]);
                }
            });

            // Stock availability is now calculated directly from stocks_product table
            // No need to update stock_availability table - it's calculated on-the-fly

            return response()->json([
                'success' => true,
                'message' => 'Stock-in record updated successfully',
                'data' => $stock->load(['customer', 'stockProducts.product', 'creator'])
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update stock-in record',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete stock-in record
     */
    public function deleteStockIn($id): JsonResponse
    {
        try {
            DB::transaction(function () use ($id) {
                $stock = Stock::with('stockProducts')->findOrFail($id);
                
                // Store data
                $customerId = $stock->customer_id;
                $date = $stock->t_date;
                $products = $stock->stockProducts->pluck('product_id')->toArray();
                
                // Delete stock products
                \App\Models\StockProduct::where('stock_id', $stock->id)->delete();
                
                // Delete the stock record
                $stock->delete(); // Soft delete
            });

            return response()->json([
                'success' => true,
                'message' => 'Stock-in record deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete stock-in record',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get customers list for stock-in
     */
    public function getStockInCustomers(): JsonResponse
    {
        try {
            $customers = \App\Models\Customer::select('id', 'name', 'company_name')
                ->where('status', 'active')
                ->orderBy('company_name')
                ->orderBy('name')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Customers retrieved successfully',
                'data' => $customers
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve customers',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get products list for stock-in
     */
    public function getStockInProducts(): JsonResponse
    {
        try {
            $products = \App\Models\Product::select('id', 'name', 'code', 'size', 'unit')
                ->where('status', 'active')
                ->orderBy('name')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Products retrieved successfully',
                'data' => $products
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve products',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Debug endpoint to check what data exists
     */
    public function debugStockIn(): JsonResponse
    {
        try {
            $allStocks = Stock::with(['customer', 'stockProducts.product', 'creator'])->get();
            
            $debugInfo = [
                'total_stocks' => $allStocks->count(),
                'transfer_statuses' => $allStocks->pluck('transfer_status')->unique()->values(),
                'from_cust_id_values' => $allStocks->pluck('from_cust_id')->unique()->values(),
                'sample_data' => $allStocks->take(5)->map(function($stock) {
                    return [
                        'id' => $stock->id,
                        'transfer_status' => $stock->transfer_status,
                        'from_cust_id' => $stock->from_cust_id,
                        'customer_id' => $stock->customer_id,
                        't_date' => $stock->t_date,
                        'status' => $stock->status,
                        'stock_products_count' => $stock->stockProducts->count(),
                        'customer_name' => $stock->customer ? $stock->customer->name : 'No customer'
                    ];
                })
            ];

            return response()->json([
                'success' => true,
                'message' => 'Debug information retrieved',
                'data' => $debugInfo
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve debug information',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export customer stock-in records to Excel (CSV format)
     */
    public function exportStockInExcel(Request $request): StreamedResponse
    {
        try {
            // Get stock-in records with relationships (same query as stockIn method)
            // Include both regular stock-in and transfer stock-in
            $query = Stock::with(['customer', 'fromCustomer', 'stockProducts.product', 'creator'])
                ->whereHas('stockProducts', function($q) {
                    $q->where('stock_type', 'in')
                      ->whereNull('deleted_at'); // Exclude soft-deleted stock products
                });

            // Apply same filters as stockIn method
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->whereHas('customer', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                          ->orWhere('company_name', 'like', "%{$search}%");
                    })->orWhereHas('stockProducts.product', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                          ->orWhere('code', 'like', "%{$search}%");
                    });
                });
            }

            if ($request->has('customer_id') && $request->customer_id) {
                $query->where('customer_id', $request->customer_id);
            }
            if ($request->has('date_from') && $request->date_from) {
                $query->whereDate('t_date', '>=', $request->date_from);
            }
            if ($request->has('date_to') && $request->date_to) {
                $query->whereDate('t_date', '<=', $request->date_to);
            }
            if ($request->has('status') && $request->status) {
                $query->where('status', $request->status);
            }

            $stocks = $query->orderBy('t_date', 'desc')->orderBy('created_at', 'desc')->get();
            
            // Debug: Log the count of stocks retrieved
            Log::info('Export Stock-In Excel: Retrieved ' . $stocks->count() . ' stock records');

            $filename = 'customer_stock_in_' . date('Y-m-d_His') . '.csv';

            $headers = [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Cache-Control' => 'max-age=0',
            ];

            $callback = function () use ($stocks) {
                $output = fopen('php://output', 'w');
                
                // Add BOM for UTF-8
                fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

                // Helper function to escape CSV values
                $escapeCsv = function ($value) {
                    if (is_string($value) && (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n") || str_contains($value, "\r"))) {
                        return '"' . str_replace('"', '""', $value) . '"';
                    }
                    return $value;
                };

                // Header row
                $headers = [
                    'S.No',
                    'Stock ID',
                    'Customer',
                    'Date',
                    'Status',
                    'Product Name',
                    'Product Code',
                    'Quantity',
                    'Unit',
                    'Notes'
                ];
                fputcsv($output, array_map($escapeCsv, $headers));

                // Data rows - each product gets its own row with incrementing serial number
                $sno = 1;
                foreach ($stocks as $stock) {
                    // Ensure customer relationship is loaded
                    if (!$stock->relationLoaded('customer')) {
                        $stock->load('customer');
                    }
                    
                    $customerName = 'N/A';
                    if ($stock->customer) {
                        $customerName = $stock->customer->company_name ?? $stock->customer->name ?? 'N/A';
                    }
                    
                    $stockDate = $stock->t_date ? date('d/m/Y', strtotime($stock->t_date)) : 'N/A';
                    $status = $stock->status ?? 'N/A';
                    $stockId = 'STK-' . str_pad($stock->id, 6, '0', STR_PAD_LEFT);
                    $notes = $stock->notes ?? '';

                    // Ensure stockProducts relationship is loaded
                    if (!$stock->relationLoaded('stockProducts')) {
                        $stock->load('stockProducts.product');
                    }

                    if ($stock->stockProducts && count($stock->stockProducts) > 0) {
                        // Each product gets its own row with incrementing serial number
                        foreach ($stock->stockProducts as $product) {
                            $productName = $product->product ? $product->product->name : 'N/A';
                            $productCode = $product->product ? ($product->product->code ?? 'N/A') : 'N/A';
                            $quantity = $product->stock_qty ?? 0;
                            $unit = $product->product ? ($product->product->unit ?? '') : '';

                            $row = [
                                $sno,
                                $stockId,
                                $customerName,
                                $stockDate,
                                $status,
                                $productName,
                                $productCode,
                                $quantity,
                                $unit,
                                $notes
                            ];
                            fputcsv($output, array_map($escapeCsv, $row));
                            $sno++; // Increment serial number for each product row
                        }
                    } else {
                        // No products, just one row
                        $row = [
                            $sno,
                            $stockId,
                            $customerName,
                            $stockDate,
                            $status,
                            'No Products',
                            '',
                            '',
                            '',
                            $notes
                        ];
                        fputcsv($output, array_map($escapeCsv, $row));
                        $sno++; // Increment serial number
                    }
                }
                fclose($output);
            };

            return new StreamedResponse($callback, 200, $headers);

        } catch (\Exception $e) {
            Log::error('Error exporting customer stock-in to Excel: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to export customer stock-in',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export customer stock-in records to PDF
     */
    public function exportStockInPdf(Request $request): Response
    {
        try {
            // Get stock-in records with relationships (same query as stockIn method)
            // Include both regular stock-in and transfer stock-in
            $query = Stock::with(['customer', 'fromCustomer', 'stockProducts.product', 'creator'])
                ->whereHas('stockProducts', function($q) {
                    $q->where('stock_type', 'in')
                      ->whereNull('deleted_at'); // Exclude soft-deleted stock products
                });

            // Apply same filters as stockIn method
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->whereHas('customer', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                          ->orWhere('company_name', 'like', "%{$search}%");
                    })->orWhereHas('stockProducts.product', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                          ->orWhere('code', 'like', "%{$search}%");
                    });
                });
            }

            if ($request->has('customer_id') && $request->customer_id) {
                $query->where('customer_id', $request->customer_id);
            }
            if ($request->has('date_from') && $request->date_from) {
                $query->whereDate('t_date', '>=', $request->date_from);
            }
            if ($request->has('date_to') && $request->date_to) {
                $query->whereDate('t_date', '<=', $request->date_to);
            }
            if ($request->has('status') && $request->status) {
                $query->where('status', $request->status);
            }

            $stocks = $query->orderBy('t_date', 'desc')->orderBy('created_at', 'desc')->get();

            // Generate HTML content for PDF
            $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>EBMS - Customer Stock In Report</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 10px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { border: 1px solid #ddd; padding: 6px; text-align: left; }
        th { background-color: #343a40; color: white; font-weight: bold; }
        .main-row { background-color: #f8f9fa; font-weight: bold; }
        .child-row { background-color: #ffffff; }
        .child-row td:first-child { border-left: 3px solid #007bff; }
        .report-title { text-align: center; font-size: 18px; font-weight: bold; margin-bottom: 5px; }
        .report-subtitle { text-align: center; font-size: 14px; color: #6c757d; margin-bottom: 10px; }
        .report-meta { text-align: center; font-size: 9px; color: #6c757d; margin-bottom: 15px; }
    </style>
</head>
<body>
    <div class="report-title">EBMS</div>
    <div class="report-subtitle">Customer Stock In Report</div>
    <div class="report-meta">Generated on: ' . date('d/m/Y H:i:s') . '</div>
    <table>
        <thead>
            <tr>
                <th>S.No</th>
                <th>Stock ID</th>
                <th>Customer</th>
                <th>Date</th>
                <th>Status</th>
                <th>Product Name</th>
                <th>Product Code</th>
                <th>Quantity</th>
                <th>Unit</th>
                <th>Notes</th>
            </tr>
        </thead>
        <tbody>';

            $sno = 1;
            foreach ($stocks as $stock) {
                $customerName = htmlspecialchars($stock->customer ? ($stock->customer->company_name ?? $stock->customer->name) : 'N/A');
                $stockDate = $stock->t_date ? date('d/m/Y', strtotime($stock->t_date)) : 'N/A';
                $status = htmlspecialchars($stock->status ?? 'N/A');
                $stockId = 'STK-' . str_pad($stock->id, 6, '0', STR_PAD_LEFT);
                $notes = htmlspecialchars($stock->notes ?? '');

                if ($stock->stockProducts && count($stock->stockProducts) > 0) {
                    // Each product gets its own row with incrementing serial number
                    foreach ($stock->stockProducts as $product) {
                        $productName = htmlspecialchars($product->product ? $product->product->name : 'N/A');
                        $productCode = htmlspecialchars($product->product ? ($product->product->code ?? 'N/A') : 'N/A');
                        $quantity = $product->stock_qty ?? 0;
                        $unit = htmlspecialchars($product->product ? ($product->product->unit ?? '') : '');

                        $html .= '<tr class="main-row">
                            <td>' . $sno . '</td>
                            <td>' . $stockId . '</td>
                            <td>' . $customerName . '</td>
                            <td>' . $stockDate . '</td>
                            <td>' . $status . '</td>
                            <td>' . $productName . '</td>
                            <td>' . $productCode . '</td>
                            <td>' . $quantity . '</td>
                            <td>' . $unit . '</td>
                            <td>' . $notes . '</td>
                        </tr>';
                        $sno++; // Increment serial number for each product row
                    }
                } else {
                    $html .= '<tr class="main-row">
                        <td>' . $sno . '</td>
                        <td>' . $stockId . '</td>
                        <td>' . $customerName . '</td>
                        <td>' . $stockDate . '</td>
                        <td>' . $status . '</td>
                        <td>No Products</td>
                        <td></td><td></td><td></td>
                        <td>' . $notes . '</td>
                    </tr>';
                    $sno++; // Increment serial number
                }
            }

            $html .= '      </tbody>
        </table>
    </body>
    </html>';

            return response($html, 200)
                ->header('Content-Type', 'text/html')
                ->header('Content-Disposition', 'inline; filename="customer_stock_in_' . date('Y-m-d_His') . '.pdf"');

        } catch (\Exception $e) {
            Log::error('Error exporting customer stock-in to PDF: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to export customer stock-in to PDF',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
