<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Models\StockAvailability;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class DeliveryController extends Controller
{
    /**
     * Display a listing of deliveries (stocks with type 'in' and from_cust_id = 0).
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Stock::with(['customer', 'product', 'creator', 'updater'])
                         ->customerDeliveries(); // stock_type = 'in' AND from_cust_id = 0

            // Search filter
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->whereHas('customer', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                          ->orWhere('company_name', 'like', "%{$search}%");
                    })->orWhereHas('product', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                          ->orWhere('code', 'like', "%{$search}%");
                    });
                });
            }

            // Filter by customer
            if ($request->has('customer_id') && $request->customer_id) {
                $query->where('customer_id', $request->customer_id);
            }

            // Filter by product
            if ($request->has('product_id') && $request->product_id) {
                $query->where('product_id', $request->product_id);
            }

            // Filter by date range
            if ($request->has('date_from') && $request->date_from) {
                $query->where('t_date', '>=', $request->date_from);
            }

            if ($request->has('date_to') && $request->date_to) {
                $query->where('t_date', '<=', $request->date_to);
            }

            // Pagination
            $perPage = $request->get('per_page', 15);
            $deliveries = $query->orderBy('t_date', 'desc')->paginate($perPage);

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
     * Store a newly created delivery or multiple deliveries in storage.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            // Check if request contains multiple records (array) or single record
            if ($request->has('records') && is_array($request->records)) {
                // Handle multiple records
                $validator = Validator::make($request->all(), [
                    'records' => 'required|array|min:1',
                    'records.*.customer_id' => 'required|exists:customers,id',
                    'records.*.t_date' => 'required|date',
                    'records.*.product_id' => 'required|exists:products,id',
                    'records.*.qty' => 'required|integer|min:1',
                    'records.*.notes' => 'nullable|string'
                ]);

                if ($validator->fails()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Validation failed',
                        'errors' => $validator->errors()
                    ], 422);
                }

                $createdDeliveries = [];
                foreach ($request->records as $recordData) {
                    $delivery = Stock::createCustomerDelivery(
                        $recordData['customer_id'],
                        $recordData['product_id'],
                        $recordData['qty'],
                        $recordData['t_date'],
                        $recordData['notes'] ?? null,
                        Auth::id()
                    );
                    
                    Log::info("Delivery created with ID: " . $delivery->id);
                    
                    // Create stock availability record directly
                    $this->createStockAvailabilityRecord(
                        $recordData['customer_id'],
                        $recordData['product_id'],
                        $recordData['t_date']
                    );
                    
                    $createdDeliveries[] = $delivery->load(['customer', 'product', 'creator', 'updater']);
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Deliveries created successfully',
                    'data' => $createdDeliveries
                ], 201);

            } else {
                // Handle single record (backward compatibility)
                $validator = Validator::make($request->all(), [
                    'customer_id' => 'required|exists:customers,id',
                    't_date' => 'required|date',
                    'product_id' => 'required|exists:products,id',
                    'qty' => 'required|integer|min:1',
                    'notes' => 'nullable|string'
                ]);

                if ($validator->fails()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Validation errors',
                        'errors' => $validator->errors()
                    ], 422);
                }

                $delivery = Stock::createCustomerDelivery(
                    $request->customer_id,
                    $request->product_id,
                    $request->qty,
                    $request->t_date,
                    $request->notes,
                    Auth::id()
                );

                // Create stock availability record directly
                $this->createStockAvailabilityRecord(
                    $request->customer_id,
                    $request->product_id,
                    $request->t_date
                );

                $delivery->load(['customer', 'product', 'creator', 'updater']);

                return response()->json([
                    'success' => true,
                    'message' => 'Delivery created successfully',
                    'data' => $delivery
                ], 201);
            }
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
     * Display the specified delivery.
     */
    public function show(Stock $delivery): JsonResponse
    {
        try {
            $delivery->load(['customer', 'product', 'creator', 'updater']);
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
     * Update the specified delivery in storage.
     */
    public function update(Request $request, Stock $delivery): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'customer_id' => 'required|exists:customers,id',
                't_date' => 'required|date',
                'product_id' => 'required|exists:products,id',
                'qty' => 'required|integer|min:1',
                'notes' => 'nullable|string'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation errors',
                    'errors' => $validator->errors()
                ], 422);
            }

            $delivery->update([
                'customer_id' => $request->customer_id,
                'from_cust_id' => null, // Ensure it remains a customer delivery
                't_date' => $request->t_date,
                'product_id' => $request->product_id,
                'qty' => $request->qty,
                'stock_type' => 'in', // Ensure it remains stock in
                'notes' => $request->notes,
                'updated_by' => Auth::id(),
            ]);
            $this->createStockAvailabilityRecord(
                $request->customer_id,
                $request->product_id,
                $request->t_date
            );

            $delivery->load(['customer', 'product', 'creator', 'updater']);

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
     * Remove the specified delivery from storage.
     */
    public function destroy(Stock $delivery): JsonResponse
    {
        try {
            // Store the data before deletion for stock availability update
            $customerId = $delivery->customer_id;
            $productId = $delivery->product_id;
            $date = $delivery->t_date;
            
            $delivery->delete(); // Soft delete
            
            // Update stock availability record after deletion
            $this->createStockAvailabilityRecord($customerId, $productId, $date);

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
     * Get a list of customers for dropdowns.
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
     * Get a list of products for dropdowns.
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
     * Create or update stock availability record
     */
    private function createStockAvailabilityRecord($customerId, $productId, $date)
    {
        Log::info("=== createStockAvailabilityRecord called ===");
        Log::info("Parameters: customerId={$customerId}, productId={$productId}, date={$date}");
        
        try {
            // Get previous day's closing stock as opening quantity
            $previousDate = \Carbon\Carbon::parse($date)->subDay()->format('Y-m-d');
            $openingStock = StockAvailability::where('customer_id', $customerId)
                ->where('product_id', $productId)
                ->where('date', $previousDate)
                ->value('closing_qty') ?? 0;

            // Calculate stock movements for this date
            $stockInQty = Stock::where('customer_id', $customerId)
                ->where('product_id', $productId)
                ->where('t_date', $date)
                ->where('stock_type', 'in')
                ->sum('qty');

            $stockOutQty = Stock::where('customer_id', $customerId)
                ->where('product_id', $productId)
                ->where('t_date', $date)
                ->where('stock_type', 'out')
                ->sum('qty');

            // Calculate available quantity
            $calculatedAvailable = $openingStock + $stockInQty - $stockOutQty;
            // Create or update stock availability record
            $stockAvailability = StockAvailability::updateOrCreate(
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
                    'created_by' => Auth::id()
                ]
            );
            
            // Log the status
            $status = $stockAvailability->wasRecentlyCreated ? 'CREATED' : 'UPDATED';
            Log::info("Stock availability record {$status} for customer {$customerId}, product {$productId}, date {$date}");
            Log::info("Stock availability data: " . json_encode([
                'id' => $stockAvailability->id,
                'open_qty' => $openingStock,
                'stock_in_qty' => $stockInQty,
                'stock_out_qty' => $stockOutQty,
                'calculated_available_qty' => $calculatedAvailable,
                'closing_qty' => $calculatedAvailable
            ]));
            
            return $stockAvailability;

        } catch (\Exception $e) {
            Log::error("Error creating stock availability record: " . $e->getMessage());
            return null;
        }
    }
}