<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\DeliveryProduct;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use App\Models\InternalStockProduct;
use App\Models\CustomerReturn;
use App\Services\StockAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class DeliveryToCustomerController extends Controller
{
    /**
     * Display a listing of deliveries to customers
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Get deliveries with relationships
            $query = Delivery::with(['customer.customerGroup:id,name', 'customer', 'deliveryProducts.product', 'creator'])
                ->customerDeliveries(); // delivery_type = 'in' AND from_cust_id = null

            // Filter by current user's assigned customers if mobile app request
            if ($request->has('my_assignments') && $request->my_assignments == '1') {
                $user = Auth::user();
                
                if ($user && $user->employee) {
                    $employeeId = $user->employee->id;
                    
                    // Get assigned customer IDs for this employee
                    $assignedCustomerIds = \App\Models\EmployeeCustomerMachineAssignment::where('employee_id', $employeeId)
                        ->where('status', 'active')
                        ->whereNull('deleted_at')
                        ->pluck('customer_id')
                        ->unique()
                        ->toArray();
                    
                    if (!empty($assignedCustomerIds)) {
                        $query->whereIn('customer_id', $assignedCustomerIds);
                    } else {
                        // No assigned customers, return empty result
                        $query->whereRaw('1 = 0'); // Force no results
                    }
                } else {
                    // No employee found, return empty result
                    $query->whereRaw('1 = 0'); // Force no results
                }
            }

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
                    })->orWhereHas('deliveryProducts.product', function ($q) use ($search) {
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
                $query->whereDate('delivery_date', '>=', $request->date_from);
            }
            if ($request->has('date_to') && $request->date_to) {
                $query->whereDate('delivery_date', '<=', $request->date_to);
            }
            if ($request->has('status') && $request->status) {
                $query->where('status', $request->status);
            }

            // Check if all data is requested (no pagination)
            if ($request->has('all') && $request->get('all') === 'true') {
                // Order by prepare_date (ascending - oldest first), then by id (descending - newest first)
                $deliveries = $query->orderBy('prepare_date', 'asc')->orderBy('id', 'desc')->get();
                // Format dates to YYYY-MM-DD
                $formattedDeliveries = $deliveries->map(function($delivery) {
                    return $this->formatDeliveryDates($delivery);
                });
                return response()->json([
                    'success' => true,
                    'message' => 'Deliveries retrieved successfully',
                    'data' => $formattedDeliveries
                ]);
            }

            $perPage = $request->get('per_page', 15);
            // Order by prepare_date (ascending - oldest first), then by id (descending - newest first)
            $deliveries = $query->orderBy('prepare_date', 'asc')->orderBy('id', 'desc')->paginate($perPage);
            
            // Format dates for paginated deliveries
            $deliveries->getCollection()->transform(function($delivery) {
                return $this->formatDeliveryDates($delivery);
            });

            return response()->json([
                'success' => true,
                'message' => 'Deliveries to customers retrieved successfully',
                'data' => $deliveries
            ]);

        } catch (\Exception $e) {
            Log::error('Error retrieving deliveries to customers: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve deliveries to customers',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Calculate available stock for a product from internal stocks
     * @param int $productId Product ID
     * @param int|null $excludeDeliveryId Optional delivery ID to exclude from stock_out calculation (for edit mode)
     */
    private function getAvailableStock($productId, $excludeDeliveryId = null): int
    {
        // stock_in_i_prod = sum of stock_qty from internal_stocks_products for this product
        $stockIn = InternalStockProduct::where('product_id', $productId)
            ->whereNull('deleted_at')
            ->sum('stock_qty');

        // stock_out_i_prod = sum of (delivery_qty - return_qty) from delivery_products for this product
        $stockOutQuery = DeliveryProduct::where('product_id', $productId)
            ->whereNull('deleted_at');
        
        // Exclude current delivery's products when editing
        if ($excludeDeliveryId !== null) {
            $stockOutQuery->where('delivery_id', '!=', $excludeDeliveryId);
        }
        
        $stockOut = $stockOutQuery->selectRaw('SUM(COALESCE(delivery_qty, 0) - COALESCE(return_qty, 0)) as net_delivery')
            ->value('net_delivery') ?? 0;

        // available = stock_in - stock_out
        return max(0, $stockIn - $stockOut);
    }

    /**
     * Validate stock availability for products
     */
    private function validateStockAvailability(array $products): array
    {
        $errors = [];
        
        foreach ($products as $index => $product) {
            $productId = $product['product_id'];
            $requestedQty = $product['stock_qty'];
            $availableStock = $this->getAvailableStock($productId);
            
            if ($requestedQty > $availableStock) {
                $productName = Product::find($productId)->name ?? "Product ID: {$productId}";
                $errors[] = "Insufficient stock for {$productName}. Available: {$availableStock}, Requested: {$requestedQty}";
            }
        }
        
        return $errors;
    }

    /**
     * Store a newly created delivery to customer
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'customer_id' => 'required|exists:customers,id',
                't_date' => 'required|date',
                'delivery_date' => 'nullable|date',
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

            // Validate stock availability
            $stockErrors = $this->validateStockAvailability($request->products);
            if (!empty($stockErrors)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient stock availability',
                    'errors' => $stockErrors
                ], 422);
            }

            $data = $request->all();
            $data['created_by'] = Auth::id();

            DB::transaction(function () use ($data, &$delivery) {
                // Create delivery record
                $delivery = Delivery::create([
                    'customer_id' => $data['customer_id'],
                    'delivery_status' => isset($data['delivery_date']) && $data['delivery_date'] ? 'delivered' : 'pending',
                    'from_cust_id' => null,
                    'prepare_date' => $data['t_date'],
                    'delivery_date' => isset($data['delivery_date']) ? $data['delivery_date'] : null, // Accepted delivery date from UI
                    'delivery_type' => 'in',
                    'created_by' => Auth::id(),
                    'status' => 'active'
                ]);

                // Create delivery product records
                foreach ($data['products'] as $product) {
                    DeliveryProduct::create([
                        'delivery_id' => $delivery->id,
                        'product_id' => $product['product_id'],
                        'delivery_qty' => $product['stock_qty']
                    ]);
                }
            });

            $delivery->load(['customer', 'deliveryProducts.product', 'creator']);
            $formattedDelivery = $this->formatDeliveryDates($delivery);

            return response()->json([
                'success' => true,
                'message' => 'Delivery to customer created successfully',
                'data' => $formattedDelivery
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error creating delivery to customer: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create delivery to customer',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified delivery to customer
     */
    public function show($id): JsonResponse
    {
        try {
            $delivery = Delivery::with(['customer', 'deliveryProducts.product', 'creator'])
                ->customerDeliveries()
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'message' => 'Delivery to customer retrieved successfully',
                'data' => $delivery
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving delivery to customer: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve delivery to customer',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified delivery to customer
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'customer_id' => 'sometimes|required|exists:customers,id',
                't_date' => 'sometimes|required|date',
                'delivery_date' => 'nullable|date',
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

            DB::transaction(function () use ($request, $id, &$delivery) {
                $delivery = Delivery::with('deliveryProducts')->findOrFail($id);
                
                // Store original data
                $originalCustomerId = $delivery->customer_id;
                $originalDate = $delivery->prepare_date;
                $originalProducts = $delivery->deliveryProducts->pluck('product_id')->toArray();

                // Get products to update (new or existing)
                $products = $request->has('products') ? $request->products : $delivery->deliveryProducts->map(function($deliveryProduct) {
                    return [
                        'product_id' => $deliveryProduct->product_id,
                        'stock_qty' => $deliveryProduct->delivery_qty
                    ];
                })->toArray();

                // Validate stock availability - exclude current delivery's products
                $stockErrors = [];
                foreach ($products as $product) {
                    $productId = $product['product_id'];
                    $requestedQty = $product['stock_qty'];
                    
                    // Get available stock excluding current delivery
                    $availableStock = $this->getAvailableStock($productId, $delivery->id);
                    
                    if ($requestedQty > $availableStock) {
                        $productName = Product::find($productId)->name ?? "Product ID: {$productId}";
                        $stockErrors[] = "Insufficient stock for {$productName}. Available: {$availableStock}, Requested: {$requestedQty}";
                    }
                }
                
                if (!empty($stockErrors)) {
                    throw new \Exception(implode('; ', $stockErrors));
                }

                // Update the delivery record
                $updateData = [
                    'customer_id' => $request->customer_id ?? $originalCustomerId,
                    'prepare_date' => $request->t_date ?? $originalDate,
                    'modified_by' => Auth::id()
                ];
                
                // Update delivery_date if provided
                if ($request->has('delivery_date')) {
                    $updateData['delivery_date'] = $request->delivery_date;
                    $updateData['delivery_status'] = $request->delivery_date ? 'delivered' : 'pending';
                }
                
                $delivery->update($updateData);

                // Delete existing delivery products
                DeliveryProduct::where('delivery_id', $delivery->id)->delete();

                // Create new delivery product records
                foreach ($products as $product) {
                    DeliveryProduct::create([
                        'delivery_id' => $delivery->id,
                        'product_id' => $product['product_id'],
                        'delivery_qty' => $product['stock_qty']
                    ]);
                }
            });

            $delivery->load(['customer', 'deliveryProducts.product', 'creator']);
            $formattedDelivery = $this->formatDeliveryDates($delivery);

            return response()->json([
                'success' => true,
                'message' => 'Delivery to customer updated successfully',
                'data' => $formattedDelivery
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating delivery to customer: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update delivery to customer',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified delivery to customer
     */
    public function destroy($id): JsonResponse
    {
        try {
            $isDelivered = false;
            
            DB::transaction(function () use ($id, &$isDelivered) {
                $delivery = Delivery::with('deliveryProducts')->findOrFail($id);
                
                // Store data for logging
                $customerId = $delivery->customer_id;
                $date = $delivery->delivery_date;
                $products = $delivery->deliveryProducts->pluck('product_id')->toArray();
                $isDelivered = !!$delivery->delivery_date;
                
                // If delivery was accepted (has delivery_date), also delete related stock records
                if ($isDelivered) {
                    // Find and delete related stock records
                    $relatedStocks = \App\Models\Stock::where('delivery_id', $delivery->id)->get();
                    
                    foreach ($relatedStocks as $stock) {
                        // Delete stock_product records first
                        \App\Models\StockProduct::where('stock_id', $stock->id)->delete();
                        
                        // Delete the stock record
                        $stock->delete(); // Soft delete
                    }
                    
                    \Log::info("Deleted delivery {$id} and related stock records. Stock records deleted: " . $relatedStocks->count());
                }
                
                // Delete delivery products
                DeliveryProduct::where('delivery_id', $delivery->id)->delete();
                
                // Delete the delivery record
                $delivery->delete(); // Soft delete
            });

            $message = 'Delivery to customer deleted successfully';
            if ($isDelivered) {
                $message .= ' (including related stock records)';
            }

            return response()->json([
                'success' => true,
                'message' => $message
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting delivery to customer: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete delivery to customer',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get customers list for dropdown
     */
    public function getCustomers(): JsonResponse
    {
        try {
            $customers = Customer::select('id', 'name', 'company_name', 'customer_group_id')
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
            Log::error('Error retrieving customers: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve customers',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get products list for dropdown with available stock
     */
    public function getProducts(): JsonResponse
    {
        try {
            $products = Product::select('id', 'name', 'code', 'size', 'unit')
                ->where('status', 'active')
                ->orderBy('name')
                ->get()
                ->map(function($product) {
                    $availableStock = $this->getAvailableStock($product->id);
                    $product->available_stock = $availableStock;
                    return $product;
                });

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
     * Get available stock for a specific product
     * Can optionally exclude a delivery_id when editing
     */
    public function getAvailableStockForProduct(Request $request, $productId): JsonResponse
    {
        try {
            $product = Product::findOrFail($productId);
            $excludeDeliveryId = $request->query('exclude_delivery_id');
            $availableStock = $this->getAvailableStock($productId, $excludeDeliveryId ? (int)$excludeDeliveryId : null);

            return response()->json([
                'success' => true,
                'message' => 'Available stock retrieved successfully',
                'data' => [
                    'product_id' => $productId,
                    'product_name' => $product->name,
                    'available_stock' => $availableStock
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving available stock: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve available stock',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get available stock for products when editing a delivery
     * Excludes the current delivery's products from calculation
     */
    public function getProductsForEdit($deliveryId): JsonResponse
    {
        try {
            $delivery = Delivery::findOrFail($deliveryId);
            
            $products = Product::select('id', 'name', 'code', 'size', 'unit')
                ->where('status', 'active')
                ->orderBy('name')
                ->get()
                ->map(function($product) use ($deliveryId) {
                    // Get available stock excluding current delivery
                    $availableStock = $this->getAvailableStock($product->id, $deliveryId);
                    $product->available_stock = $availableStock;
                    return $product;
                });

            return response()->json([
                'success' => true,
                'message' => 'Products retrieved successfully for edit',
                'data' => $products
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving products for edit: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve products',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Accept delivery and create stock records
     */
    public function acceptDelivery(Request $request, $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'products' => 'required|array|min:1',
                'products.*.delivery_products_id' => 'required|exists:delivery_products,id',
                'products.*.return_qty' => 'required|integer|min:0',
                'products.*.return_reason' => 'nullable|string|max:500',
                'delivery_date' => 'nullable|date'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Get the delivery record
            $delivery = Delivery::with('deliveryProducts.product')->findOrFail($id);
            
            // Check if this is an update (delivery already accepted) or new acceptance
            $isUpdate = !!$delivery->delivery_date;
            
            // Initialize change tracking variables
            $newlyApprovedProducts = [];
            $updatedProducts = [];
            $newlyRejectedProducts = [];

            $allProductsRejected = true;
            
            // Use delivery_date from request if provided, otherwise use current date
            // Parse the date string to ensure it's stored correctly without timezone conversion
            if ($request->has('delivery_date') && $request->delivery_date) {
                // Parse the date string and format it as YYYY-MM-DD to avoid timezone issues
                $acceptedDate = \Carbon\Carbon::createFromFormat('Y-m-d', $request->delivery_date)->format('Y-m-d');
            } else {
                $acceptedDate = now()->format('Y-m-d');
            }
            
            // Create a map of delivery_product_id => request data for easy lookup (outside transaction for use in closure)
            $productDataMap = [];
            foreach ($request->products as $productData) {
                $productDataMap[$productData['delivery_products_id']] = $productData;
            }

            DB::transaction(function () use ($request, $delivery, $isUpdate, $acceptedDate, $productDataMap, &$newlyApprovedProducts, &$updatedProducts, &$newlyRejectedProducts, &$allProductsRejected) {
                $currentDate = now();
                $userId = Auth::id();

                // Update delivery_products with return quantities
                foreach ($request->products as $productData) {
                    $deliveryProduct = DeliveryProduct::findOrFail($productData['delivery_products_id']);
                    
                    // Validate that return_qty doesn't exceed delivery_qty
                    if ($productData['return_qty'] > $deliveryProduct->delivery_qty) {
                        throw new \Exception("Return quantity cannot exceed delivery quantity for product ID: {$deliveryProduct->product_id}");
                    }

                    // Check if this product is fully rejected (return_qty equals delivery_qty)
                    $isFullyRejected = ($productData['return_qty'] == $deliveryProduct->delivery_qty);
                    if (!$isFullyRejected) {
                        $allProductsRejected = false;
                    }

                    $deliveryProduct->update([
                        'return_qty' => $productData['return_qty'],
                        'return_reason' => $productData['return_reason'] ?? null,
                        'return_date' => $acceptedDate, // Use the same accepted date
                        'return_status' => 'approved'
                    ]);
                }

                // Determine delivery status based on whether all products are rejected
                $deliveryStatus = $allProductsRejected ? 'rejected' : 'delivered';

                // Update delivery record - use DB::raw to set date directly without timezone conversion
                $delivery->update([
                    'delivery_date' => $acceptedDate,
                    'delivery_status' => $deliveryStatus,
                    'modified_by' => $userId
                ]);

                // Refresh delivery products relationship to get updated return_qty values
                $delivery->load('deliveryProducts');

                if ($isUpdate) {
                    // For updates: Update existing stock and stock_product records
                    if ($delivery->delivery_type === 'transfer') {
                        // Handle transfer updates
                        if ($allProductsRejected) {
                            // Transfer fully rejected: Need to rollback
                            // Find existing stock in for to_customer (if any) and delete it
                            $existingStockIn = \App\Models\Stock::where('delivery_id', $delivery->id)
                                ->where('customer_id', $delivery->customer_id) // Receiving customer
                                ->whereHas('stockProducts', function($query) {
                                    $query->where('stock_type', 'in');
                                })
                                ->first();
                            
                            if ($existingStockIn) {
                                // Delete stock in records for to_customer
                                \App\Models\StockProduct::where('stock_id', $existingStockIn->id)->delete();
                                $existingStockIn->delete();
                            }
                            
                            // Create rollback stock in for from_customer
                            $existingStockOut = \App\Models\Stock::where('delivery_id', $delivery->id)
                                ->where('customer_id', $delivery->from_cust_id) // From customer
                                ->whereHas('stockProducts', function($query) {
                                    $query->where('stock_type', 'out');
                                })
                                ->first();
                            
                            if ($existingStockOut) {
                                // Check if rollback stock already exists
                                $rollbackStock = \App\Models\Stock::where('delivery_id', $delivery->id)
                                    ->where('customer_id', $delivery->from_cust_id)
                                    ->where('transfer_status', 'customer_transfer_rejected')
                                    ->first();
                                
                                if (!$rollbackStock) {
                                    // Create rollback stock in for from_customer
                                    $rollbackStock = \App\Models\Stock::create([
                                        'customer_id' => $delivery->from_cust_id,
                                        'delivery_id' => $delivery->id,
                                        'transfer_status' => 'customer_transfer_rejected',
                                        'from_cust_id' => $delivery->from_cust_id,
                                        't_date' => $delivery->delivery_date,
                                        'created_by' => $userId,
                                        'status' => 'active'
                                    ]);
                                    
                                    // Create stock in records to rollback
                                    foreach ($delivery->deliveryProducts as $deliveryProduct) {
                                        \App\Models\StockProduct::create([
                                            'stock_id' => $rollbackStock->id,
                                            'delivery_products_id' => $deliveryProduct->id,
                                            'product_id' => $deliveryProduct->product_id,
                                            'stock_qty' => $deliveryProduct->delivery_qty,
                                            'stock_type' => 'in'
                                        ]);
                                    }
                                }
                            }
                        } else {
                            // Transfer approved/partially approved: Update stock in for to_customer
                            $existingStock = \App\Models\Stock::where('delivery_id', $delivery->id)
                                ->where('customer_id', $delivery->customer_id) // Receiving customer
                                ->whereHas('stockProducts', function($query) {
                                    $query->where('stock_type', 'in');
                                })
                                ->first();
                            
                            // If no stock in exists, create it
                            if (!$existingStock) {
                                $existingStock = \App\Models\Stock::create([
                                    'customer_id' => $delivery->customer_id,
                                    'delivery_id' => $delivery->id,
                                    'transfer_status' => 'customer_transfer',
                                    'from_cust_id' => $delivery->from_cust_id,
                                    't_date' => $delivery->delivery_date,
                                    'created_by' => $userId,
                                    'status' => 'active'
                                ]);
                            } else {
                                // Update existing stock record
                                $existingStock->update([
                                    't_date' => $delivery->delivery_date,
                                    'modified_by' => $userId
                                ]);
                            }
                            
                            // Delete existing stock_product records
                            \App\Models\StockProduct::where('stock_id', $existingStock->id)->delete();
                            CustomerReturn::where('delivery_id', $delivery->id)->delete();
                            
                            // Create new stock_product records
                            foreach ($delivery->deliveryProducts as $deliveryProduct) {
                                $requestData = $productDataMap[$deliveryProduct->id] ?? null;
                                $returnQty = $requestData ? $requestData['return_qty'] : $deliveryProduct->return_qty;
                                $returnReason = $requestData ? ($requestData['return_reason'] ?? null) : $deliveryProduct->return_reason;
                                
                                $receivedQty = $deliveryProduct->delivery_qty - $returnQty;
                                
                                // Create stock in for accepted quantity
                                if ($receivedQty > 0) {
                                    \App\Models\StockProduct::create([
                                        'stock_id' => $existingStock->id,
                                        'delivery_products_id' => $deliveryProduct->id,
                                        'product_id' => $deliveryProduct->product_id,
                                        'stock_qty' => $receivedQty,
                                        'stock_type' => 'in'
                                    ]);
                                }
                                
                                // Create stock out for returned quantity
                                if ($returnQty > 0) {
                                    \App\Models\StockProduct::create([
                                        'stock_id' => $existingStock->id,
                                        'delivery_products_id' => $deliveryProduct->id,
                                        'product_id' => $deliveryProduct->product_id,
                                        'stock_qty' => $returnQty,
                                        'stock_type' => 'out'
                                    ]);
                                    
                                    CustomerReturn::create([
                                        'customer_id' => $delivery->customer_id,
                                        'product_id' => $deliveryProduct->product_id,
                                        'delivery_id' => $delivery->id,
                                        'stock_id' => $existingStock->id,
                                        'return_qty' => $returnQty,
                                        'return_reason' => $returnReason ?? 'other',
                                        'return_date' => $delivery->delivery_date,
                                        'status' => 'pending',
                                        'created_by' => $userId
                                    ]);
                                }
                            }
                        }
                        } else {
                            // Handle regular deliveries (non-transfer)
                            $existingStock = \App\Models\Stock::where('delivery_id', $delivery->id)->first();
                            
                            if ($existingStock) {
                                // Update existing stock record
                                $existingStock->update([
                                    't_date' => $delivery->delivery_date,
                                    'modified_by' => $userId
                                ]);

                                // Get existing stock_product records for comparison
                                $existingStockProducts = \App\Models\StockProduct::where('stock_id', $existingStock->id)->get();
                                $existingProductIds = $existingStockProducts->pluck('delivery_products_id')->toArray();
                                
                                // Delete existing stock_product records for this delivery (both in and out)
                                \App\Models\StockProduct::where('stock_id', $existingStock->id)->delete();
                                
                                // Delete existing customer return records for this delivery (will recreate if needed)
                                CustomerReturn::where('delivery_id', $delivery->id)->delete();

                                // Create new stock_product records with updated quantities
                                foreach ($delivery->deliveryProducts as $deliveryProduct) {
                                    // Use request data if available, otherwise use model data
                                    $requestData = $productDataMap[$deliveryProduct->id] ?? null;
                                    $returnQty = $requestData ? $requestData['return_qty'] : $deliveryProduct->return_qty;
                                    $returnReason = $requestData ? ($requestData['return_reason'] ?? null) : $deliveryProduct->return_reason;
                                    
                                    $receivedQty = $deliveryProduct->delivery_qty - $returnQty;
                                    
                                    // Check if this product was previously in stock_product table
                                    $wasPreviouslyApproved = in_array($deliveryProduct->id, $existingProductIds);
                                    
                                    // Create stock in for accepted quantity
                                    if ($receivedQty > 0) {
                                        // Product is approved (has received quantity)
                                        \App\Models\StockProduct::create([
                                            'stock_id' => $existingStock->id,
                                            'delivery_products_id' => $deliveryProduct->id,
                                            'product_id' => $deliveryProduct->product_id,
                                            'stock_qty' => $receivedQty,
                                            'stock_type' => 'in'
                                        ]);
                                        
                                        if (!$wasPreviouslyApproved) {
                                            // Product was previously rejected and is now approved
                                            $newlyApprovedProducts[] = $deliveryProduct->product_id;
                                        } else {
                                            // Product was previously approved and quantity might have changed
                                            $updatedProducts[] = $deliveryProduct->product_id;
                                        }
                                    } else {
                                        // Product is now rejected (no received quantity)
                                        if ($wasPreviouslyApproved) {
                                            // Product was previously approved and is now rejected
                                            $newlyRejectedProducts[] = $deliveryProduct->product_id;
                                        }
                                    }
                                    
                                    // Create stock out for returned quantity (reduces customer stock)
                                    if ($returnQty > 0) {
                                        \App\Models\StockProduct::create([
                                            'stock_id' => $existingStock->id,
                                            'delivery_products_id' => $deliveryProduct->id,
                                            'product_id' => $deliveryProduct->product_id,
                                            'stock_qty' => $returnQty,
                                            'stock_type' => 'out' // Stock out reduces customer stock
                                        ]);
                                        
                                        // Create customer return record for tracking (PENDING status - waiting for admin approval)
                                        CustomerReturn::create([
                                            'customer_id' => $delivery->customer_id,
                                            'product_id' => $deliveryProduct->product_id,
                                            'delivery_id' => $delivery->id,
                                            'stock_id' => $existingStock->id,
                                            'return_qty' => $returnQty,
                                            'return_reason' => $returnReason ?? 'other',
                                            'return_date' => $delivery->delivery_date,
                                            'status' => 'pending', // Pending - waiting for admin approval
                                            'created_by' => $userId
                                        ]);
                                    }
                                }
                                
                                // Log the changes
                                if (!empty($newlyApprovedProducts)) {
                                    \Log::info("Products newly approved for delivery {$delivery->id}: " . implode(', ', $newlyApprovedProducts));
                                }
                                if (!empty($updatedProducts)) {
                                    \Log::info("Products with updated quantities for delivery {$delivery->id}: " . implode(', ', $updatedProducts));
                                }
                                if (!empty($newlyRejectedProducts)) {
                                    \Log::info("Products newly rejected for delivery {$delivery->id}: " . implode(', ', $newlyRejectedProducts));
                                }
                            }
                        }
                } else {
                    // For new acceptance
                    if ($delivery->delivery_type === 'transfer') {
                        // Handle transfer deliveries
                        if ($allProductsRejected) {
                            // Transfer fully rejected: Rollback stock out for from_customer by creating stock in
                            // Find the existing stock out record for from_customer
                            $existingStockOut = \App\Models\Stock::where('delivery_id', $delivery->id)
                                ->where('customer_id', $delivery->from_cust_id) // From customer
                                ->whereHas('stockProducts', function($query) {
                                    $query->where('stock_type', 'out');
                                })
                                ->first();
                            
                            if ($existingStockOut) {
                                // Create stock in record to rollback the stock out for from_customer
                                $rollbackStock = \App\Models\Stock::create([
                                    'customer_id' => $delivery->from_cust_id, // From customer
                                    'delivery_id' => $delivery->id,
                                    'transfer_status' => 'customer_transfer_rejected',
                                    'from_cust_id' => $delivery->from_cust_id,
                                    't_date' => $delivery->delivery_date,
                                    'created_by' => $userId,
                                    'status' => 'active'
                                ]);
                                
                                // Create stock in records to rollback all products
                                foreach ($delivery->deliveryProducts as $deliveryProduct) {
                                    \App\Models\StockProduct::create([
                                        'stock_id' => $rollbackStock->id,
                                        'delivery_products_id' => $deliveryProduct->id,
                                        'product_id' => $deliveryProduct->product_id,
                                        'stock_qty' => $deliveryProduct->delivery_qty,
                                        'stock_type' => 'in' // Stock in to rollback the stock out
                                    ]);
                                }
                            }
                        } else {
                            // Transfer partially or fully approved: Create stock in for to_customer
                            $stock = \App\Models\Stock::create([
                                'customer_id' => $delivery->customer_id, // To customer (receiving)
                                'delivery_id' => $delivery->id,
                                'transfer_status' => 'customer_transfer',
                                'from_cust_id' => $delivery->from_cust_id,
                                't_date' => $delivery->delivery_date,
                                'created_by' => $userId,
                                'status' => 'active'
                            ]);

                            // Create stock_product records for accepted products
                            foreach ($delivery->deliveryProducts as $deliveryProduct) {
                                $requestData = $productDataMap[$deliveryProduct->id] ?? null;
                                $returnQty = $requestData ? $requestData['return_qty'] : $deliveryProduct->return_qty;
                                $returnReason = $requestData ? ($requestData['return_reason'] ?? null) : $deliveryProduct->return_reason;
                                
                                $receivedQty = $deliveryProduct->delivery_qty - $returnQty;
                                
                                // Create stock in for accepted quantity (to customer)
                                if ($receivedQty > 0) {
                                    \App\Models\StockProduct::create([
                                        'stock_id' => $stock->id,
                                        'delivery_products_id' => $deliveryProduct->id,
                                        'product_id' => $deliveryProduct->product_id,
                                        'stock_qty' => $receivedQty,
                                        'stock_type' => 'in'
                                    ]);
                                }
                                
                                // Create stock out for returned quantity (reduces to_customer stock)
                                if ($returnQty > 0) {
                                    \App\Models\StockProduct::create([
                                        'stock_id' => $stock->id,
                                        'delivery_products_id' => $deliveryProduct->id,
                                        'product_id' => $deliveryProduct->product_id,
                                        'stock_qty' => $returnQty,
                                        'stock_type' => 'out'
                                    ]);
                                    
                                    // Create customer return record for tracking
                                    CustomerReturn::create([
                                        'customer_id' => $delivery->customer_id,
                                        'product_id' => $deliveryProduct->product_id,
                                        'delivery_id' => $delivery->id,
                                        'stock_id' => $stock->id,
                                        'return_qty' => $returnQty,
                                        'return_reason' => $returnReason ?? 'other',
                                        'return_date' => $delivery->delivery_date,
                                        'status' => 'pending',
                                        'created_by' => $userId
                                    ]);
                                }
                            }
                        }
                    } else {
                        // Handle regular deliveries (non-transfer)
                        if (!$allProductsRejected) {
                            $stock = \App\Models\Stock::create([
                                'customer_id' => $delivery->customer_id,
                                'delivery_id' => $delivery->id,
                                'transfer_status' => 'delivery_received',
                                't_date' => $delivery->delivery_date,
                                'created_by' => $userId,
                                'status' => 'active'
                            ]);

                            // Create stock_product records for accepted products and returns
                            foreach ($delivery->deliveryProducts as $deliveryProduct) {
                                $requestData = $productDataMap[$deliveryProduct->id] ?? null;
                                $returnQty = $requestData ? $requestData['return_qty'] : $deliveryProduct->return_qty;
                                $returnReason = $requestData ? ($requestData['return_reason'] ?? null) : $deliveryProduct->return_reason;
                                
                                $receivedQty = $deliveryProduct->delivery_qty - $returnQty;
                                
                                // Create stock in for accepted quantity
                                if ($receivedQty > 0) {
                                    \App\Models\StockProduct::create([
                                        'stock_id' => $stock->id,
                                        'delivery_products_id' => $deliveryProduct->id,
                                        'product_id' => $deliveryProduct->product_id,
                                        'stock_qty' => $receivedQty,
                                        'stock_type' => 'in'
                                    ]);
                                }
                                
                                // Create stock out for returned quantity (reduces customer stock)
                                if ($returnQty > 0) {
                                    \App\Models\StockProduct::create([
                                        'stock_id' => $stock->id,
                                        'delivery_products_id' => $deliveryProduct->id,
                                        'product_id' => $deliveryProduct->product_id,
                                        'stock_qty' => $returnQty,
                                        'stock_type' => 'out'
                                    ]);
                                    
                                    // Create customer return record for tracking
                                    CustomerReturn::create([
                                        'customer_id' => $delivery->customer_id,
                                        'product_id' => $deliveryProduct->product_id,
                                        'delivery_id' => $delivery->id,
                                        'stock_id' => $stock->id,
                                        'return_qty' => $returnQty,
                                        'return_reason' => $returnReason ?? 'other',
                                        'return_date' => $delivery->delivery_date,
                                        'status' => 'pending',
                                        'created_by' => $userId
                                    ]);
                                }
                            }
                        }
                    }
                }
            });

            // Stock availability is now calculated directly from stocks_product table
            // No need to update stock_availability table - it's calculated on-the-fly

            if ($isUpdate) {
                $message = 'Delivery acceptance updated successfully and stock records updated';
                
                // Add details about changes if available
                if (!empty($newlyApprovedProducts)) {
                    $message .= '. Newly approved products: ' . count($newlyApprovedProducts);
                }
                if (!empty($updatedProducts)) {
                    $message .= '. Updated products: ' . count($updatedProducts);
                }
                if (!empty($newlyRejectedProducts)) {
                    $message .= '. Newly rejected products: ' . count($newlyRejectedProducts);
                }
            } else {
                if ($allProductsRejected) {
                    $message = 'Delivery rejected successfully. No stock records created.';
            } else {
                $message = 'Delivery accepted successfully and stock records created';
                }
            }

            // Reload delivery to get fresh data
            $delivery->refresh();
            $delivery->load(['customer', 'deliveryProducts.product', 'creator']);
            
            // Get raw delivery_date from database to avoid timezone conversion
            $rawDeliveryDate = \DB::table('delivery')->where('id', $delivery->id)->value('delivery_date');
            $rawPrepareDate = \DB::table('delivery')->where('id', $delivery->id)->value('prepare_date');
            
            // Convert to array and format delivery_date to YYYY-MM-DD to avoid timezone issues
            $deliveryData = $delivery->toArray();
            
            // Override with raw database values formatted as YYYY-MM-DD
            if ($rawDeliveryDate) {
                $deliveryData['delivery_date'] = \Carbon\Carbon::parse($rawDeliveryDate)->format('Y-m-d');
            }
            if ($rawPrepareDate) {
                $deliveryData['prepare_date'] = \Carbon\Carbon::parse($rawPrepareDate)->format('Y-m-d');
            }
            
            // Also format return_date in delivery_products if present
            if (isset($deliveryData['delivery_products']) && is_array($deliveryData['delivery_products'])) {
                foreach ($deliveryData['delivery_products'] as &$product) {
                    if (isset($product['return_date']) && $product['return_date']) {
                        $product['return_date'] = \Carbon\Carbon::parse($product['return_date'])->format('Y-m-d');
                    }
                }
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => $deliveryData
            ]);

        } catch (\Exception $e) {
            Log::error('Error accepting delivery: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to accept delivery',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export deliveries to Excel
     * Format: Main row with delivery info, child rows with product details
     */
    public function exportExcel(Request $request)
    {
        try {
            // Get deliveries with relationships (same query as index)
            $query = Delivery::with(['customer', 'deliveryProducts.product', 'creator'])
                ->customerDeliveries();

            // Apply same filters as index method
            if ($request->has('my_assignments') && $request->my_assignments == '1') {
                $user = Auth::user();
                if ($user && $user->employee) {
                    $employeeId = $user->employee->id;
                    $assignedCustomerIds = \App\Models\EmployeeCustomerMachineAssignment::where('employee_id', $employeeId)
                        ->where('status', 'active')
                        ->whereNull('deleted_at')
                        ->pluck('customer_id')
                        ->unique()
                        ->toArray();
                    
                    if (!empty($assignedCustomerIds)) {
                        $query->whereIn('customer_id', $assignedCustomerIds);
                    } else {
                        $query->whereRaw('1 = 0');
                    }
                }
            }

            $deliveries = $query->orderBy('created_at', 'desc')->get();

            // Generate Excel content
            $filename = 'delivery_to_customer_' . date('Y-m-d_His') . '.csv';
            
            // Build Excel content using proper CSV format with semicolon separator (better Excel compatibility)
            $content = '';
            
            // Add BOM for UTF-8
            $content .= chr(0xEF).chr(0xBB).chr(0xBF);

            // Helper function to escape CSV values
            $escapeCsv = function($value) {
                if ($value === null || $value === '') {
                    return '';
                }
                // Convert to string and escape quotes
                $value = (string)$value;
                // If value contains comma, quote, or newline, wrap in quotes and escape quotes
                if (strpos($value, ',') !== false || strpos($value, '"') !== false || strpos($value, "\n") !== false) {
                    $value = '"' . str_replace('"', '""', $value) . '"';
                }
                return $value;
            };

            // Header row
            $headers = [
                'S.No',
                'Delivery ID',
                'Customer',
                'Prepare Date',
                'Delivery Date',
                'Status',
                'Product Name',
                'Product Code',
                'Quantity',
                'Unit',
                'Notes'
            ];
            $content .= implode(',', array_map($escapeCsv, $headers)) . "\r\n";

            // Data rows - each product gets its own row with incrementing serial number
            $sno = 1;
            foreach ($deliveries as $delivery) {
                $customerName = $delivery->customer ? ($delivery->customer->company_name ?? $delivery->customer->name) : 'N/A';
                $prepareDate = $delivery->prepare_date ? date('d/m/Y', strtotime($delivery->prepare_date)) : 'N/A';
                $deliveryDate = $delivery->delivery_date ? date('d/m/Y', strtotime($delivery->delivery_date)) : 'Not Delivered';
                $status = $delivery->delivery_date ? 'Delivered' : 'Pending';
                $deliveryId = 'DEL-' . str_pad($delivery->id, 6, '0', STR_PAD_LEFT);
                $notes = $delivery->notes ?? '';

                if ($delivery->deliveryProducts && count($delivery->deliveryProducts) > 0) {
                    // Each product gets its own row with incrementing serial number
                    foreach ($delivery->deliveryProducts as $product) {
                        $productName = $product->product ? $product->product->name : 'N/A';
                        $productCode = $product->product ? ($product->product->code ?? 'N/A') : 'N/A';
                        $quantity = $product->delivery_qty ?? 0;
                        $unit = $product->product ? ($product->product->unit ?? '') : '';

                        $row = [
                            $sno,
                            $deliveryId,
                            $customerName,
                            $prepareDate,
                            $deliveryDate,
                            $status,
                            $productName,
                            $productCode,
                            $quantity,
                            $unit,
                            $notes
                        ];
                        $content .= implode(',', array_map($escapeCsv, $row)) . "\r\n";
                        $sno++; // Increment serial number for each product row
                    }
                } else {
                    // No products, just one row
                    $row = [
                        $sno,
                        $deliveryId,
                        $customerName,
                        $prepareDate,
                        $deliveryDate,
                        $status,
                        'No Products',
                        '',
                        '',
                        '',
                        $notes
                    ];
                    $content .= implode(',', array_map($escapeCsv, $row)) . "\r\n";
                    $sno++; // Increment serial number
                }
            }

            return response($content, 200)
                ->header('Content-Type', 'text/csv; charset=UTF-8')
                ->header('Content-Disposition', 'attachment; filename="' . $filename . '"')
                ->header('Cache-Control', 'max-age=0')
                ->header('Pragma', 'public');

        } catch (\Exception $e) {
            Log::error('Error exporting deliveries to Excel: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to export deliveries',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export deliveries to PDF
     */
    public function exportPdf(Request $request): Response
    {
        try {
            // Get deliveries with relationships (same query as index)
            $query = Delivery::with(['customer', 'deliveryProducts.product', 'creator'])
                ->customerDeliveries();

            // Apply same filters as index method
            if ($request->has('my_assignments') && $request->my_assignments == '1') {
                $user = Auth::user();
                if ($user && $user->employee) {
                    $employeeId = $user->employee->id;
                    $assignedCustomerIds = \App\Models\EmployeeCustomerMachineAssignment::where('employee_id', $employeeId)
                        ->where('status', 'active')
                        ->whereNull('deleted_at')
                        ->pluck('customer_id')
                        ->unique()
                        ->toArray();
                    
                    if (!empty($assignedCustomerIds)) {
                        $query->whereIn('customer_id', $assignedCustomerIds);
                    } else {
                        $query->whereRaw('1 = 0');
                    }
                }
            }

            $deliveries = $query->orderBy('created_at', 'desc')->get();

            // Generate HTML content for PDF
            $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>EBMS - Delivery to Customer Report</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 10px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { border: 1px solid #ddd; padding: 6px; text-align: left; }
        th { background-color: #343a40; color: white; font-weight: bold; }
        .main-row { background-color: #f8f9fa; font-weight: bold; }
        .child-row { background-color: #ffffff; }
        .child-row td:first-child { border-left: 3px solid #007bff; }
        h2 { text-align: center; margin-bottom: 10px; color: #343a40; }
        .report-title { text-align: center; font-size: 18px; font-weight: bold; margin-bottom: 5px; }
        .report-subtitle { text-align: center; font-size: 14px; color: #6c757d; margin-bottom: 10px; }
        .report-meta { text-align: center; font-size: 9px; color: #6c757d; margin-bottom: 15px; }
    </style>
</head>
<body>
    <div class="report-title">EBMS</div>
    <div class="report-subtitle">Delivery to Customer Report</div>
    <div class="report-meta">Generated on: ' . date('d/m/Y H:i:s') . '</div>
    <table>
        <thead>
            <tr>
                <th>S.No</th>
                <th>Delivery ID</th>
                <th>Customer</th>
                <th>Prepare Date</th>
                <th>Delivery Date</th>
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
            foreach ($deliveries as $delivery) {
                $customerName = $delivery->customer ? ($delivery->customer->company_name ?? $delivery->customer->name) : 'N/A';
                $prepareDate = $delivery->prepare_date ? date('d/m/Y', strtotime($delivery->prepare_date)) : 'N/A';
                $deliveryDate = $delivery->delivery_date ? date('d/m/Y', strtotime($delivery->delivery_date)) : 'Not Delivered';
                $status = $delivery->delivery_date ? 'Delivered' : 'Pending';
                $deliveryId = 'DEL-' . str_pad($delivery->id, 6, '0', STR_PAD_LEFT);

                if ($delivery->deliveryProducts && count($delivery->deliveryProducts) > 0) {
                    // Each product gets its own row with incrementing serial number
                    foreach ($delivery->deliveryProducts as $product) {
                        $productName = $product->product ? htmlspecialchars($product->product->name) : 'N/A';
                        $productCode = $product->product ? ($product->product->code ?? 'N/A') : 'N/A';
                        $quantity = $product->delivery_qty ?? 0;
                        $unit = $product->product ? ($product->product->unit ?? '') : '';
                        $notes = htmlspecialchars($delivery->notes ?? '');

                        $html .= '<tr class="main-row">
                            <td>' . $sno . '</td>
                            <td>' . $deliveryId . '</td>
                            <td>' . htmlspecialchars($customerName) . '</td>
                            <td>' . $prepareDate . '</td>
                            <td>' . $deliveryDate . '</td>
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
                    // No products
                    $html .= '<tr class="main-row">
                        <td>' . $sno . '</td>
                        <td>' . $deliveryId . '</td>
                        <td>' . htmlspecialchars($customerName) . '</td>
                        <td>' . $prepareDate . '</td>
                        <td>' . $deliveryDate . '</td>
                        <td>' . $status . '</td>
                        <td>No Products</td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td>' . htmlspecialchars($delivery->notes ?? '') . '</td>
                    </tr>';
                    $sno++; // Increment serial number
                }
            }

            $html .= '</tbody>
    </table>
</body>
</html>';

            $filename = 'delivery_to_customer_' . date('Y-m-d_His') . '.pdf';
            
            // Return HTML (can be converted to PDF using browser print or server-side PDF library)
            return response($html)
                ->header('Content-Type', 'text/html')
                ->header('Content-Disposition', 'inline; filename="' . $filename . '"');

        } catch (\Exception $e) {
            Log::error('Error exporting deliveries to PDF: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to export deliveries',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Format delivery dates to YYYY-MM-DD format to avoid timezone issues
     * 
     * @param \App\Models\Delivery $delivery
     * @return array
     */
    private function formatDeliveryDates($delivery)
    {
        // Get raw date values from database to avoid timezone conversion
        $rawDeliveryDate = \DB::table('delivery')->where('id', $delivery->id)->value('delivery_date');
        $rawPrepareDate = \DB::table('delivery')->where('id', $delivery->id)->value('prepare_date');
        
        // Convert to array
        $deliveryData = $delivery->toArray();
        
        // Override with raw database values formatted as YYYY-MM-DD
        if ($rawDeliveryDate) {
            $deliveryData['delivery_date'] = \Carbon\Carbon::parse($rawDeliveryDate)->format('Y-m-d');
        } else {
            $deliveryData['delivery_date'] = null;
        }
        
        if ($rawPrepareDate) {
            $deliveryData['prepare_date'] = \Carbon\Carbon::parse($rawPrepareDate)->format('Y-m-d');
        } else {
            $deliveryData['prepare_date'] = null;
        }
        
        // Also format return_date in delivery_products if present
        if (isset($deliveryData['delivery_products']) && is_array($deliveryData['delivery_products'])) {
            foreach ($deliveryData['delivery_products'] as &$product) {
                if (isset($product['return_date']) && $product['return_date']) {
                    $product['return_date'] = \Carbon\Carbon::parse($product['return_date'])->format('Y-m-d');
                }
            }
        }
        
        return $deliveryData;
    }

}