<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Models\StockProduct;
use App\Models\StockAvailability;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Delivery;
use App\Models\DeliveryProduct;
use App\Models\CustomerReturn;
use App\Services\StockAvailabilityService;
use App\Http\Controllers\Api\StockAlertController;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\Response;

class StockTransferController extends Controller
{
    /**
     * Display a listing of stock transfers
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Get stock transfers from delivery table with delivery_type = 'transfer'
            $query = Delivery::with(['customer', 'fromCustomer', 'deliveryProducts.product', 'creator'])
                ->where('delivery_type', 'transfer');

            // Search functionality
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->whereHas('fromCustomer', function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('company_name', 'like', "%{$search}%");
                    })->orWhereHas('customer', function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('company_name', 'like', "%{$search}%");
                    })->orWhereHas('deliveryProducts.product', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%");
                    });
                });
            }

            // Filters
            if ($request->has('from_customer_id') && $request->from_customer_id) {
                $query->where('from_cust_id', $request->from_customer_id);
            }
            if ($request->has('to_customer_id') && $request->to_customer_id) {
                $query->where('customer_id', $request->to_customer_id);
            }
            if ($request->has('product_id') && $request->product_id) {
                $query->whereHas('deliveryProducts', function ($q) use ($request) {
                    $q->where('product_id', $request->product_id);
                });
            }
            if ($request->has('date_from') && $request->date_from) {
                $query->whereDate('prepare_date', '>=', $request->date_from);
            }
            if ($request->has('date_to') && $request->date_to) {
                $query->whereDate('prepare_date', '<=', $request->date_to);
            }

            // Check if all data is requested (no pagination)
            if ($request->has('all') && $request->get('all') === 'true') {
                $transfers = $query->orderBy('prepare_date', 'desc')->orderBy('created_at', 'desc')->get();
                return response()->json([
                    'success' => true,
                    'message' => 'Stock transfers retrieved successfully',
                    'data' => $transfers
                ]);
            }

            $perPage = $request->get('per_page', 15);
            $transfers = $query->orderBy('prepare_date', 'desc')->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'Stock transfers retrieved successfully',
                'data' => $transfers
            ]);

        } catch (\Exception $e) {
            Log::error('Error retrieving stock transfers: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve stock transfers',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created stock transfer
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'from_customer_id' => 'required|exists:customers,id',
                'to_customer_id' => 'required|exists:customers,id|different:from_customer_id',
                't_date' => 'required|date',
                'products' => 'required|array|min:1',
                'products.*.product_id' => 'required|exists:products,id',
                'products.*.stock_qty' => 'required|integer|min:1',
                'notes' => 'nullable|string|max:1000'
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

            DB::transaction(function () use ($data, &$delivery) {
                // Create delivery record with delivery_type = 'transfer'
                $delivery = Delivery::create([
                    'customer_id' => $data['to_customer_id'], // Receiving customer
                    'from_cust_id' => $data['from_customer_id'], // Sending customer
                    'prepare_date' => $data['t_date'],
                    'delivery_date' => null, // Will be set when accepted
                    'delivery_status' => 'pending', // Pending until accepted
                    'delivery_type' => 'transfer',
                    'created_by' => Auth::id(),
                    'status' => 'active'
                ]);

                // Create delivery product records
                $deliveryProducts = [];
                foreach ($data['products'] as $product) {
                    $deliveryProduct = DeliveryProduct::create([
                        'delivery_id' => $delivery->id,
                        'product_id' => $product['product_id'],
                        'delivery_qty' => $product['stock_qty']
                    ]);
                    $deliveryProducts[$product['product_id']] = $deliveryProduct;
                }

                // Create stock record for sending customer (from customer) - stock out immediately
                $stockOut = Stock::create([
                    'customer_id' => $data['from_customer_id'], // From customer
                    'delivery_id' => $delivery->id,
                    'transfer_status' => 'customer_transfer',
                    'from_cust_id' => $data['from_customer_id'],
                    't_date' => $data['t_date'],
                    'created_by' => Auth::id(),
                    'status' => 'active'
                ]);

                // Create stock product records for from customer (stock out)
                foreach ($data['products'] as $product) {
                    $deliveryProduct = $deliveryProducts[$product['product_id']];
                    
                    StockProduct::create([
                        'stock_id' => $stockOut->id,
                        'delivery_products_id' => $deliveryProduct->id,
                        'product_id' => $product['product_id'],
                        'stock_qty' => $product['stock_qty'],
                        'stock_type' => 'out'
                    ]);
                }

                // Stock in for receiving customer will be created when delivery is accepted
            });

            // Load the created delivery with relationships
            $delivery->load(['customer', 'fromCustomer', 'deliveryProducts.product', 'creator']);

            // Trigger automatic threshold check after transfer is created
            // Check both from_customer (stock out) and to_customer (will receive stock)
            try {
                $stockAlertController = new StockAlertController();
                foreach ($data['products'] as $product) {
                    // Check from customer (stock decreased)
                    $stockAlertController->checkAndTriggerThresholdAlerts($data['from_customer_id'], $product['product_id']);
                    // Check to customer (will receive stock, but check current status)
                    $stockAlertController->checkAndTriggerThresholdAlerts($data['to_customer_id'], $product['product_id']);
                }
            } catch (\Exception $e) {
                // Log error but don't fail the request
                Log::error('Error triggering threshold alerts after transfer', [
                    'error' => $e->getMessage(),
                    'from_customer_id' => $data['from_customer_id'] ?? null,
                    'to_customer_id' => $data['to_customer_id'] ?? null
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Stock transfer created successfully. Waiting for customer acceptance.',
                'data' => $delivery
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error creating stock transfer: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create stock transfer',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified stock transfer
     */
    public function show($id): JsonResponse
    {
        try {
            $delivery = Delivery::with(['customer', 'fromCustomer', 'deliveryProducts.product', 'creator'])
                ->where('delivery_type', 'transfer')
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'message' => 'Stock transfer retrieved successfully',
                'data' => $delivery
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving stock transfer: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve stock transfer',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified stock transfer
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'from_customer_id' => 'sometimes|required|exists:customers,id',
                'to_customer_id' => 'sometimes|required|exists:customers,id|different:from_customer_id',
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

            DB::transaction(function () use ($request, $id, &$stockTransfer) {
                // Get the original transfer
                $stockTransfer = Stock::where('transfer_status', 'customer_transfer')
                    ->with(['stockProducts.product', 'customer', 'fromCustomer'])
                    ->findOrFail($id);
                
                // Store original data for stock availability updates
                $originalFromCustomerId = $stockTransfer->from_cust_id;
                $originalToCustomerId = $stockTransfer->customer_id;
                $originalDate = $stockTransfer->t_date;
                $originalProducts = $stockTransfer->stockProducts->pluck('product_id')->unique()->toArray();

                // Delete all related stock product records
                StockProduct::where('stock_id', $stockTransfer->id)->delete();

                // Update the stock record
                $stockTransfer->update([
                    'customer_id' => $request->to_customer_id ?? $originalToCustomerId,
                    'from_cust_id' => $request->from_customer_id ?? $originalFromCustomerId,
                    't_date' => $request->t_date ?? $originalDate,
                    'modified_by' => Auth::id()
                ]);

                // Create new stock product records for both customers
                if ($request->has('products')) {
                    foreach ($request->products as $product) {
                        // Create stock product record for receiving customer (stock in)
                        StockProduct::create([
                            'stock_id' => $stockTransfer->id,
                            'product_id' => $product['product_id'],
                            'stock_qty' => $product['stock_qty'],
                            'stock_type' => 'in'
                        ]);

                        // Create stock product record for sending customer (stock out)
                        StockProduct::create([
                            'stock_id' => $stockTransfer->id,
                            'product_id' => $product['product_id'],
                            'stock_qty' => $product['stock_qty'],
                            'stock_type' => 'out'
                        ]);
                    }
                }

                // Stock availability will be handled separately through stock availability screen
            });

            return response()->json([
                'success' => true,
                'message' => 'Stock transfer updated successfully',
                'data' => $stockTransfer->load(['customer', 'fromCustomer', 'stockProducts.product', 'creator'])
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating stock transfer: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update stock transfer',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified stock transfer from storage
     */
    public function destroy($id): JsonResponse
    {
        try {
            DB::transaction(function () use ($id) {
                // Get the delivery record with delivery_type = 'transfer'
                $delivery = Delivery::where('delivery_type', 'transfer')
                    ->with(['deliveryProducts', 'customer', 'fromCustomer'])
                    ->findOrFail($id);
                
                // Get all stock records related to this delivery
                $stockRecords = Stock::where('delivery_id', $delivery->id)->get();
                
                // Delete all related stock_product records
                foreach ($stockRecords as $stock) {
                    StockProduct::where('stock_id', $stock->id)->delete();
                }
                
                // Delete all stock records (both IN and OUT)
                foreach ($stockRecords as $stock) {
                    $stock->delete(); // Soft delete
                }
                
                // Delete all delivery_product records
                DeliveryProduct::where('delivery_id', $delivery->id)->delete();
                
                // Delete customer return records related to this delivery
                CustomerReturn::where('delivery_id', $delivery->id)->delete();
                
                // Delete the delivery record (soft delete)
                $delivery->delete();
            });

            return response()->json([
                'success' => true,
                'message' => 'Stock transfer deleted successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting stock transfer: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete stock transfer',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all products in a transfer for editing
     */
    public function getTransferProducts($id): JsonResponse
    {
        try {
            $stockTransfer = Stock::where('transfer_status', 'customer_transfer')
                ->with(['stockProducts.product', 'customer', 'fromCustomer'])
                ->findOrFail($id);

            $products = $stockTransfer->stockProducts->map(function($stockProduct) {
                return [
                    'product_id' => $stockProduct->product_id,
                    'stock_qty' => $stockProduct->stock_qty,
                    'product' => $stockProduct->product
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Transfer products retrieved successfully',
                'data' => [
                    'transfer_info' => [
                        'from_customer_id' => $stockTransfer->from_cust_id,
                        'to_customer_id' => $stockTransfer->customer_id,
                        'transfer_date' => $stockTransfer->t_date,
                        'notes' => $stockTransfer->notes
                    ],
                    'products' => $products
                ]
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
     * Get latest stock availability for a customer and product
     */
    public function getStockAvailability(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'customer_id' => 'required|exists:customers,id',
                'product_id' => 'required|exists:products,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $customerId = $request->customer_id;
            $productId = $request->product_id;

            // Get the latest stock availability record
            $stockAvailability = StockAvailability::where('customer_id', $customerId)
                ->where('product_id', $productId)
                ->orderBy('date', 'desc')
                ->orderBy('created_at', 'desc')
                ->first();

            $availableQty = 0;
            if ($stockAvailability) {
                $availableQty = $stockAvailability->calculated_available_qty ?? 0;
            }

            return response()->json([
                'success' => true,
                'message' => 'Stock availability retrieved successfully',
                'data' => [
                    'customer_id' => $customerId,
                    'product_id' => $productId,
                    'available_qty' => $availableQty,
                    'last_updated' => $stockAvailability ? $stockAvailability->date : null
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error retrieving stock availability: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve stock availability',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get customers list for dropdowns
     */
    public function getCustomers(): JsonResponse
    {
        try {
            $customers = Customer::query()
                ->with(['customerGroup:id,name'])
                ->whereNull('deleted_at')
                ->orderBy('company_name')
                ->orderBy('name')
                ->get(['id', 'name', 'company_name', 'customer_group_id']);

            $data = $customers->map(function (Customer $c) {
                return [
                    'id' => $c->id,
                    'name' => $c->name,
                    'company_name' => $c->company_name,
                    'customer_group_id' => $c->customer_group_id,
                    'customer_group_name' => $c->customerGroup?->name,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Customers retrieved successfully',
                'data' => $data,
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
     * Get products list for dropdowns
     */
    public function getProducts(): JsonResponse
    {
        try {
            $products = Product::select('id', 'name', 'code', 'size', 'unit')->orderBy('name')->get();
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
     * Get available stock for transfer including all current date transactions
     * This endpoint calculates available stock including all transactions up to and including the given date
     */
    public function getTransferAvailableStock(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'customer_id' => 'required|exists:customers,id',
                'date' => 'required|date_format:Y-m-d'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $customerId = $request->customer_id;
            $date = $request->date;

            // Get all products assigned to this customer
            $customer = Customer::with(['customerProducts' => function($query) {
                $query->where('status', 'active');
            }])->findOrFail($customerId);

            $assignedProductIds = $customer->customerProducts->pluck('product_id')->toArray();

            // Get all products
            $products = Product::whereIn('id', $assignedProductIds)
                ->where('status', 'active')
                ->orderBy('name')
                ->get();

            $availabilityData = [];

            foreach ($products as $product) {
                // Calculate available stock including all current date transactions
                $availableQty = StockAvailabilityService::calculateAvailableStockForTransfer(
                    $customerId,
                    $product->id,
                    $date
                );

                // Only include products with available stock > 0
                if ($availableQty > 0) {
                    $availabilityData[] = [
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'product_code' => $product->code,
                        'product_size' => $product->size,
                        'product_unit' => $product->unit,
                        'available_qty' => $availableQty
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Transfer available stock retrieved successfully',
                'data' => [
                    'customer_id' => $customerId,
                    'date' => $date,
                    'products' => $availabilityData
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error retrieving transfer available stock: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve transfer available stock',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export stock transfers to Excel (CSV format)
     */
    public function exportExcel(Request $request): StreamedResponse
    {
        try {
            // Get stock transfers with relationships (same query as index)
            $query = Stock::with(['customer', 'fromCustomer', 'stockProducts.product', 'creator'])
                ->where('transfer_status', 'customer_transfer');

            // Apply same filters as index method
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->whereHas('fromCustomer', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                          ->orWhere('company_name', 'like', "%{$search}%");
                    })->orWhereHas('customer', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                          ->orWhere('company_name', 'like', "%{$search}%");
                    })->orWhereHas('stockProducts.product', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%");
                    });
                });
            }

            if ($request->has('from_customer_id') && $request->from_customer_id) {
                $query->where('from_cust_id', $request->from_customer_id);
            }
            if ($request->has('to_customer_id') && $request->to_customer_id) {
                $query->where('customer_id', $request->to_customer_id);
            }
            if ($request->has('product_id') && $request->product_id) {
                $query->whereHas('stockProducts', function ($q) use ($request) {
                    $q->where('product_id', $request->product_id);
                });
            }
            if ($request->has('date_from') && $request->date_from) {
                $query->whereDate('t_date', '>=', $request->date_from);
            }
            if ($request->has('date_to') && $request->date_to) {
                $query->whereDate('t_date', '<=', $request->date_to);
            }

            $transfers = $query->orderBy('t_date', 'desc')->orderBy('created_at', 'desc')->get();

            $filename = 'stock_transfer_' . date('Y-m-d_His') . '.csv';

            $headers = [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Cache-Control' => 'max-age=0',
            ];

            $callback = function () use ($transfers) {
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
                    'Transfer ID',
                    'From Customer',
                    'To Customer',
                    'Date',
                    'Product Name',
                    'Product Code',
                    'Quantity',
                    'Unit',
                    'Notes'
                ];
                fputcsv($output, array_map($escapeCsv, $headers));

                // Data rows
                $sno = 1;
                foreach ($transfers as $transfer) {
                    $fromCustomerName = $transfer->fromCustomer ? ($transfer->fromCustomer->company_name ?? $transfer->fromCustomer->name) : 'N/A';
                    $toCustomerName = $transfer->customer ? ($transfer->customer->company_name ?? $transfer->customer->name) : 'N/A';
                    $transferDate = $transfer->t_date ? date('d/m/Y', strtotime($transfer->t_date)) : 'N/A';
                    $transferId = 'TRF-' . str_pad($transfer->id, 6, '0', STR_PAD_LEFT);
                    $notes = $transfer->notes ?? '';

                    if ($transfer->stockProducts && count($transfer->stockProducts) > 0) {
                        // Each product gets its own row with incrementing serial number
                        foreach ($transfer->stockProducts as $product) {
                            $productName = $product->product ? $product->product->name : 'N/A';
                            $productCode = $product->product ? ($product->product->code ?? 'N/A') : 'N/A';
                            $quantity = $product->stock_qty ?? 0;
                            $unit = $product->product ? ($product->product->unit ?? '') : '';

                            $row = [
                                $sno,
                                $transferId,
                                $fromCustomerName,
                                $toCustomerName,
                                $transferDate,
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
                            $transferId,
                            $fromCustomerName,
                            $toCustomerName,
                            $transferDate,
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
            Log::error('Error exporting stock transfers to Excel: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to export stock transfers',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export stock transfers to PDF
     */
    public function exportPdf(Request $request): Response
    {
        try {
            // Get stock transfers with relationships (same query as index)
            $query = Stock::with(['customer', 'fromCustomer', 'stockProducts.product', 'creator'])
                ->where('transfer_status', 'customer_transfer');

            // Apply same filters as index method
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->whereHas('fromCustomer', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                          ->orWhere('company_name', 'like', "%{$search}%");
                    })->orWhereHas('customer', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                          ->orWhere('company_name', 'like', "%{$search}%");
                    })->orWhereHas('stockProducts.product', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%");
                    });
                });
            }

            if ($request->has('from_customer_id') && $request->from_customer_id) {
                $query->where('from_cust_id', $request->from_customer_id);
            }
            if ($request->has('to_customer_id') && $request->to_customer_id) {
                $query->where('customer_id', $request->to_customer_id);
            }
            if ($request->has('product_id') && $request->product_id) {
                $query->whereHas('stockProducts', function ($q) use ($request) {
                    $q->where('product_id', $request->product_id);
                });
            }
            if ($request->has('date_from') && $request->date_from) {
                $query->whereDate('t_date', '>=', $request->date_from);
            }
            if ($request->has('date_to') && $request->date_to) {
                $query->whereDate('t_date', '<=', $request->date_to);
            }

            $transfers = $query->orderBy('t_date', 'desc')->orderBy('created_at', 'desc')->get();

            // Generate HTML content for PDF
            $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>EBMS - Stock Transfer Report</title>
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
    <div class="report-subtitle">Stock Transfer Report</div>
    <div class="report-meta">Generated on: ' . date('d/m/Y H:i:s') . '</div>
    <table>
        <thead>
            <tr>
                <th>S.No</th>
                <th>Transfer ID</th>
                <th>From Customer</th>
                <th>To Customer</th>
                <th>Date</th>
                <th>Product Name</th>
                <th>Product Code</th>
                <th>Quantity</th>
                <th>Unit</th>
                <th>Notes</th>
            </tr>
        </thead>
        <tbody>';

            $sno = 1;
            foreach ($transfers as $transfer) {
                $fromCustomerName = htmlspecialchars($transfer->fromCustomer ? ($transfer->fromCustomer->company_name ?? $transfer->fromCustomer->name) : 'N/A');
                $toCustomerName = htmlspecialchars($transfer->customer ? ($transfer->customer->company_name ?? $transfer->customer->name) : 'N/A');
                $transferDate = $transfer->t_date ? date('d/m/Y', strtotime($transfer->t_date)) : 'N/A';
                $transferId = 'TRF-' . str_pad($transfer->id, 6, '0', STR_PAD_LEFT);
                $notes = htmlspecialchars($transfer->notes ?? '');

                if ($transfer->stockProducts && count($transfer->stockProducts) > 0) {
                    // Each product gets its own row with incrementing serial number
                    foreach ($transfer->stockProducts as $product) {
                        $productName = htmlspecialchars($product->product ? $product->product->name : 'N/A');
                        $productCode = htmlspecialchars($product->product ? ($product->product->code ?? 'N/A') : 'N/A');
                        $quantity = $product->stock_qty ?? 0;
                        $unit = htmlspecialchars($product->product ? ($product->product->unit ?? '') : '');

                        $html .= '<tr class="main-row">
                            <td>' . $sno . '</td>
                            <td>' . $transferId . '</td>
                            <td>' . $fromCustomerName . '</td>
                            <td>' . $toCustomerName . '</td>
                            <td>' . $transferDate . '</td>
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
                        <td>' . $transferId . '</td>
                        <td>' . $fromCustomerName . '</td>
                        <td>' . $toCustomerName . '</td>
                        <td>' . $transferDate . '</td>
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
                ->header('Content-Disposition', 'inline; filename="stock_transfer_' . date('Y-m-d_His') . '.pdf"');

        } catch (\Exception $e) {
            Log::error('Error exporting stock transfers to PDF: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to export stock transfers to PDF',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Accept a stock transfer (create stock IN for to_customer)
     */
    public function accept($id): JsonResponse
    {
        try {
            DB::transaction(function () use ($id, &$delivery) {
                // Get the delivery record with delivery_type = 'transfer'
                $delivery = Delivery::where('delivery_type', 'transfer')
                    ->with(['deliveryProducts', 'customer', 'fromCustomer'])
                    ->findOrFail($id);

                // Check if already accepted
                if ($delivery->delivery_status === 'delivered') {
                    throw new \Exception('Transfer is already accepted');
                }

                // Check if already rejected
                if ($delivery->delivery_status === 'rejected') {
                    throw new \Exception('Transfer is already rejected. Cannot accept a rejected transfer.');
                }

                $userId = Auth::id();
                $currentDate = now();

                // Check if stock IN already exists for to_customer
                $existingStockIn = Stock::where('delivery_id', $delivery->id)
                    ->where('customer_id', $delivery->customer_id) // To customer
                    ->whereHas('stockProducts', function($query) {
                        $query->where('stock_type', 'in');
                    })
                    ->first();

                if (!$existingStockIn) {
                    // Create stock IN for to_customer (receiving customer)
                    $stockIn = Stock::create([
                        'customer_id' => $delivery->customer_id, // To customer
                        'delivery_id' => $delivery->id,
                        'transfer_status' => 'customer_transfer',
                        'from_cust_id' => $delivery->from_cust_id,
                        't_date' => $currentDate->format('Y-m-d'),
                        'created_by' => $userId,
                        'status' => 'active'
                    ]);

                    // Create stock_product records for to_customer (stock IN)
                    foreach ($delivery->deliveryProducts as $deliveryProduct) {
                        StockProduct::create([
                            'stock_id' => $stockIn->id,
                            'delivery_products_id' => $deliveryProduct->id,
                            'product_id' => $deliveryProduct->product_id,
                            'stock_qty' => $deliveryProduct->delivery_qty,
                            'stock_type' => 'in'
                        ]);
                    }
                }

                // Update delivery status
                $delivery->update([
                    'delivery_status' => 'delivered',
                    'delivery_date' => $currentDate,
                    'modified_by' => $userId
                ]);

                // Load relationships
                $delivery->load(['customer', 'fromCustomer', 'deliveryProducts.product', 'creator']);
            });

            // Trigger automatic threshold check after transfer is accepted
            // Stock IN is created for to_customer, so check both customers
            try {
                $stockAlertController = new StockAlertController();
                foreach ($delivery->deliveryProducts as $deliveryProduct) {
                    // Check to_customer (stock increased)
                    $stockAlertController->checkAndTriggerThresholdAlerts($delivery->customer_id, $deliveryProduct->product_id);
                    // Check from_customer (stock decreased earlier, but check again)
                    $stockAlertController->checkAndTriggerThresholdAlerts($delivery->from_cust_id, $deliveryProduct->product_id);
                }
            } catch (\Exception $e) {
                // Log error but don't fail the request
                Log::error('Error triggering threshold alerts after transfer accept', [
                    'error' => $e->getMessage(),
                    'delivery_id' => $delivery->id ?? null
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Stock transfer accepted successfully',
                'data' => $delivery
            ]);

        } catch (\Exception $e) {
            Log::error('Error accepting stock transfer: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to accept stock transfer',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reject a stock transfer (rollback stock OUT for from_customer by creating stock IN)
     */
    public function reject($id): JsonResponse
    {
        try {
            DB::transaction(function () use ($id, &$delivery) {
                // Get the delivery record with delivery_type = 'transfer'
                $delivery = Delivery::where('delivery_type', 'transfer')
                    ->with(['deliveryProducts', 'customer', 'fromCustomer'])
                    ->findOrFail($id);

                // Check if already rejected
                if ($delivery->delivery_status === 'rejected') {
                    throw new \Exception('Transfer is already rejected');
                }

                $userId = Auth::id();
                $currentDate = now();

                // If transfer was already accepted, delete stock IN for to_customer
                if ($delivery->delivery_status === 'delivered') {
                    $existingStockIn = Stock::where('delivery_id', $delivery->id)
                        ->where('customer_id', $delivery->customer_id) // To customer
                        ->whereHas('stockProducts', function($query) {
                            $query->where('stock_type', 'in');
                        })
                        ->first();

                    if ($existingStockIn) {
                        // Delete stock_product records
                        StockProduct::where('stock_id', $existingStockIn->id)->delete();
                        // Delete stock record
                        $existingStockIn->delete();
                    }
                }

                // Find existing stock OUT for from_customer
                $existingStockOut = Stock::where('delivery_id', $delivery->id)
                    ->where('customer_id', $delivery->from_cust_id) // From customer
                    ->whereHas('stockProducts', function($query) {
                        $query->where('stock_type', 'out');
                    })
                    ->first();

                if ($existingStockOut) {
                    // Check if rollback stock already exists
                    $rollbackStock = Stock::where('delivery_id', $delivery->id)
                        ->where('customer_id', $delivery->from_cust_id)
                        ->where('transfer_status', 'customer_transfer_rejected')
                        ->first();

                    if (!$rollbackStock) {
                        // Create rollback stock IN for from_customer
                        $rollbackStock = Stock::create([
                            'customer_id' => $delivery->from_cust_id,
                            'delivery_id' => $delivery->id,
                            'transfer_status' => 'customer_transfer_rejected',
                            'from_cust_id' => $delivery->from_cust_id,
                            't_date' => $currentDate->format('Y-m-d'),
                            'created_by' => $userId,
                            'status' => 'active'
                        ]);

                        // Create stock IN records to rollback
                        foreach ($delivery->deliveryProducts as $deliveryProduct) {
                            StockProduct::create([
                                'stock_id' => $rollbackStock->id,
                                'delivery_products_id' => $deliveryProduct->id,
                                'product_id' => $deliveryProduct->product_id,
                                'stock_qty' => $deliveryProduct->delivery_qty,
                                'stock_type' => 'in'
                            ]);
                        }
                    }
                }

                // Update delivery status
                $delivery->update([
                    'delivery_status' => 'rejected',
                    'modified_by' => $userId
                ]);

                // Load relationships
                $delivery->load(['customer', 'fromCustomer', 'deliveryProducts.product', 'creator']);
            });

            return response()->json([
                'success' => true,
                'message' => 'Stock transfer rejected successfully. Stock has been returned to the from customer.',
                'data' => $delivery
            ]);

        } catch (\Exception $e) {
            Log::error('Error rejecting stock transfer: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to reject stock transfer',
                'error' => $e->getMessage()
            ], 500);
        }
    }

}
