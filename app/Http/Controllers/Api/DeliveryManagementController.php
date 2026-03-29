<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\DeliveryProduct;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class DeliveryManagementController extends Controller
{
    /**
     * Display a listing of deliveries
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Delivery::with(['customer', 'fromCustomer', 'deliveryProducts.product', 'creator']);

            // Search functionality
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->whereHas('customer', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                          ->orWhere('company_name', 'like', "%{$search}%");
                    })->orWhereHas('fromCustomer', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                          ->orWhere('company_name', 'like', "%{$search}%");
                    })->orWhereHas('deliveryProducts.product', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%");
                    });
                });
            }

            // Filters
            if ($request->has('customer_id') && $request->customer_id) {
                $query->where('customer_id', $request->customer_id);
            }
            if ($request->has('from_customer_id') && $request->from_customer_id) {
                $query->where('from_cust_id', $request->from_customer_id);
            }
            if ($request->has('delivery_type') && $request->delivery_type) {
                $query->where('delivery_type', $request->delivery_type);
            }
            if ($request->has('delivery_status') && $request->delivery_status) {
                $query->where('delivery_status', $request->delivery_status);
            }
            if ($request->has('date_from') && $request->date_from) {
                $query->whereDate('delivery_date', '>=', $request->date_from);
            }
            if ($request->has('date_to') && $request->date_to) {
                $query->whereDate('delivery_date', '<=', $request->date_to);
            }

            $perPage = $request->get('per_page', 15);
            $deliveries = $query->orderBy('delivery_date', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'Deliveries retrieved successfully',
                'data' => $deliveries
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching deliveries: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve deliveries',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created delivery in storage
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'customer_id' => 'required|exists:customers,id',
                'delivery_status' => 'nullable|string|max:255',
                'from_cust_id' => 'nullable|exists:customers,id',
                'delivery_date' => 'required|date',
                'delivery_type' => 'required|in:in,out',
                'products' => 'required|array|min:1',
                'products.*.product_id' => 'required|exists:products,id',
                'products.*.delivery_qty' => 'required|integer|min:1',
                'products.*.return_qty' => 'nullable|integer|min:0',
                'products.*.return_reason' => 'nullable|string|max:1000',
                'products.*.return_date' => 'nullable|date',
                'products.*.return_status' => 'nullable|in:pending,approved,rejected'
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
                // Create delivery record
                $delivery = Delivery::create([
                    'customer_id' => $data['customer_id'],
                    'delivery_status' => $data['delivery_status'] ?? null,
                    'from_cust_id' => $data['from_cust_id'] ?? null,
                    'delivery_date' => $data['delivery_date'],
                    'delivery_type' => $data['delivery_type'],
                    'created_by' => Auth::id(),
                    'status' => 'active'
                ]);

                // Create delivery product records
                foreach ($data['products'] as $product) {
                    DeliveryProduct::create([
                        'delivery_id' => $delivery->id,
                        'product_id' => $product['product_id'],
                        'delivery_qty' => $product['delivery_qty'],
                        'return_qty' => $product['return_qty'] ?? 0,
                        'return_reason' => $product['return_reason'] ?? null,
                        'return_date' => $product['return_date'] ?? null,
                        'return_status' => $product['return_status'] ?? null
                    ]);
                }
            });

            // Load the created delivery with relationships
            $delivery->load(['customer', 'fromCustomer', 'deliveryProducts.product', 'creator']);

            return response()->json([
                'success' => true,
                'message' => 'Delivery created successfully',
                'data' => $delivery
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error creating delivery: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create delivery',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified delivery
     */
    public function show(Delivery $delivery): JsonResponse
    {
        try {
            $delivery->load(['customer', 'fromCustomer', 'deliveryProducts.product', 'creator', 'modifier']);
            return response()->json([
                'success' => true,
                'message' => 'Delivery retrieved successfully',
                'data' => $delivery
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching delivery: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve delivery',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified delivery in storage
     */
    public function update(Request $request, Delivery $delivery): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'customer_id' => 'required|exists:customers,id',
                'delivery_status' => 'nullable|string|max:255',
                'from_cust_id' => 'nullable|exists:customers,id',
                'delivery_date' => 'required|date',
                'delivery_type' => 'required|in:in,out',
                'products' => 'required|array|min:1',
                'products.*.id' => 'nullable|exists:delivery_products,id',
                'products.*.product_id' => 'required|exists:products,id',
                'products.*.delivery_qty' => 'required|integer|min:1',
                'products.*.return_qty' => 'nullable|integer|min:0',
                'products.*.return_reason' => 'nullable|string|max:1000',
                'products.*.return_date' => 'nullable|date',
                'products.*.return_status' => 'nullable|in:pending,approved,rejected'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::transaction(function () use ($request, $delivery) {
                // Update delivery record
                $delivery->update([
                    'customer_id' => $request->customer_id,
                    'delivery_status' => $request->delivery_status,
                    'from_cust_id' => $request->from_cust_id,
                    'delivery_date' => $request->delivery_date,
                    'delivery_type' => $request->delivery_type,
                    'modified_by' => Auth::id()
                ]);

                // Get existing product IDs
                $existingProductIds = $delivery->deliveryProducts->pluck('id')->toArray();
                $updatedProductIds = [];

                // Update or create delivery products
                foreach ($request->products as $product) {
                    if (isset($product['id']) && in_array($product['id'], $existingProductIds)) {
                        // Update existing product
                        DeliveryProduct::where('id', $product['id'])->update([
                            'product_id' => $product['product_id'],
                            'delivery_qty' => $product['delivery_qty'],
                            'return_qty' => $product['return_qty'] ?? 0,
                            'return_reason' => $product['return_reason'] ?? null,
                            'return_date' => $product['return_date'] ?? null,
                            'return_status' => $product['return_status'] ?? null
                        ]);
                        $updatedProductIds[] = $product['id'];
                    } else {
                        // Create new product
                        $newProduct = DeliveryProduct::create([
                            'delivery_id' => $delivery->id,
                            'product_id' => $product['product_id'],
                            'delivery_qty' => $product['delivery_qty'],
                            'return_qty' => $product['return_qty'] ?? 0,
                            'return_reason' => $product['return_reason'] ?? null,
                            'return_date' => $product['return_date'] ?? null,
                            'return_status' => $product['return_status'] ?? null
                        ]);
                        $updatedProductIds[] = $newProduct->id;
                    }
                }

                // Delete products that are no longer in the request
                $productsToDelete = array_diff($existingProductIds, $updatedProductIds);
                if (!empty($productsToDelete)) {
                    DeliveryProduct::whereIn('id', $productsToDelete)->delete();
                }
            });

            // Load the updated delivery with relationships
            $delivery->load(['customer', 'fromCustomer', 'deliveryProducts.product', 'creator', 'modifier']);

            return response()->json([
                'success' => true,
                'message' => 'Delivery updated successfully',
                'data' => $delivery
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating delivery: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update delivery',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified delivery from storage
     */
    public function destroy(Delivery $delivery): JsonResponse
    {
        try {
            $delivery->delete(); // Soft delete

            return response()->json([
                'success' => true,
                'message' => 'Delivery deleted successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting delivery: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete delivery',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update return information for a delivery product
     */
    public function updateReturn(Request $request, DeliveryProduct $deliveryProduct): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'return_qty' => 'required|integer|min:0',
                'return_reason' => 'required|string|max:1000',
                'return_date' => 'required|date',
                'return_status' => 'required|in:pending,approved,rejected'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $deliveryProduct->update([
                'return_qty' => $request->return_qty,
                'return_reason' => $request->return_reason,
                'return_date' => $request->return_date,
                'return_status' => $request->return_status
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Return information updated successfully',
                'data' => $deliveryProduct->load('product')
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating return: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update return information',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get deliveries with returns
     */
    public function getReturns(Request $request): JsonResponse
    {
        try {
            $query = DeliveryProduct::with(['delivery.customer', 'product'])
                ->where('return_qty', '>', 0);

            // Filters
            if ($request->has('return_status') && $request->return_status) {
                $query->where('return_status', $request->return_status);
            }
            if ($request->has('date_from') && $request->date_from) {
                $query->whereDate('return_date', '>=', $request->date_from);
            }
            if ($request->has('date_to') && $request->date_to) {
                $query->whereDate('return_date', '<=', $request->date_to);
            }

            $perPage = $request->get('per_page', 15);
            $returns = $query->orderBy('return_date', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'Returns retrieved successfully',
                'data' => $returns
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching returns: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve returns',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a list of customers for dropdowns
     */
    public function getCustomers(): JsonResponse
    {
        try {
            $customers = Customer::select('id', 'name', 'company_name')->get();
            return response()->json([
                'success' => true,
                'message' => 'Customers retrieved successfully',
                'data' => $customers
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching customers: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve customers',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a list of products for dropdowns
     */
    public function getProducts(): JsonResponse
    {
        try {
            $products = Product::select('id', 'name', 'code', 'size', 'unit')->get();
            return response()->json([
                'success' => true,
                'message' => 'Products retrieved successfully',
                'data' => $products
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching products: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve products',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get delivery statistics
     */
    public function getStatistics(Request $request): JsonResponse
    {
        try {
            $query = Delivery::query();

            // Apply date filters if provided
            if ($request->has('date_from') && $request->date_from) {
                $query->whereDate('delivery_date', '>=', $request->date_from);
            }
            if ($request->has('date_to') && $request->date_to) {
                $query->whereDate('delivery_date', '<=', $request->date_to);
            }

            $totalDeliveries = $query->count();
            $deliveriesIn = $query->clone()->where('delivery_type', 'in')->count();
            $deliveriesOut = $query->clone()->where('delivery_type', 'out')->count();

            $totalReturns = DeliveryProduct::where('return_qty', '>', 0)->count();
            $pendingReturns = DeliveryProduct::where('return_status', 'pending')->count();
            $approvedReturns = DeliveryProduct::where('return_status', 'approved')->count();

            return response()->json([
                'success' => true,
                'message' => 'Statistics retrieved successfully',
                'data' => [
                    'total_deliveries' => $totalDeliveries,
                    'deliveries_in' => $deliveriesIn,
                    'deliveries_out' => $deliveriesOut,
                    'total_returns' => $totalReturns,
                    'pending_returns' => $pendingReturns,
                    'approved_returns' => $approvedReturns
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching statistics: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
