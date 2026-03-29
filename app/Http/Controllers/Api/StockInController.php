<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Models\StockProduct;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class StockInController extends Controller
{
    /**
     * Display a listing of stock-in records
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Get stock-in records with relationships
            $query = Stock::with(['customer', 'customerFloor', 'stockProducts.product', 'creator'])
                ->where('stock_type', 'in')
                ->whereNull('from_cust_id'); // Stock-in only (not transfers)

            // Search functionality
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

            $perPage = $request->get('per_page', 15);
            $stocks = $query->orderBy('t_date', 'desc')->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'Stock-in records retrieved successfully',
                'data' => $stocks
            ]);

        } catch (\Exception $e) {
            Log::error('Error retrieving stock-in records: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve stock-in records',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created stock-in record
     */
    public function store(Request $request): JsonResponse
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
                    StockProduct::create([
                        'stock_id' => $stock->id,
                        'product_id' => $product['product_id'],
                        'stock_qty' => $product['stock_qty'],
                        'stock_type' => 'in'
                    ]);
                }
            });

            return response()->json([
                'success' => true,
                'message' => 'Stock-in record created successfully',
                'data' => $stock->load(['customer', 'stockProducts.product', 'creator'])
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error creating stock-in record: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create stock-in record',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified stock-in record
     */
    public function show($id): JsonResponse
    {
        try {
            $stock = Stock::with(['customer', 'customerFloor', 'stockProducts.product', 'creator'])
                ->where('stock_type', 'in')
                ->whereNull('from_cust_id')
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'message' => 'Stock-in record retrieved successfully',
                'data' => $stock
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving stock-in record: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve stock-in record',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified stock-in record
     */
    public function update(Request $request, $id): JsonResponse
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
                StockProduct::where('stock_id', $stock->id)->delete();

                // Create new stock product records
                $products = $request->products ?? $stock->stockProducts->map(function($stockProduct) {
                    return [
                        'product_id' => $stockProduct->product_id,
                        'stock_qty' => $stockProduct->stock_qty
                    ];
                })->toArray();

                foreach ($products as $product) {
                    StockProduct::create([
                        'stock_id' => $stock->id,
                        'product_id' => $product['product_id'],
                        'stock_qty' => $product['stock_qty'],
                        'stock_type' => 'in'
                    ]);
                }
            });

            return response()->json([
                'success' => true,
                'message' => 'Stock-in record updated successfully',
                'data' => $stock->load(['customer', 'customerFloor', 'stockProducts.product', 'creator'])
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating stock-in record: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update stock-in record',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified stock-in record
     */
    public function destroy($id): JsonResponse
    {
        try {
            DB::transaction(function () use ($id) {
                $stock = Stock::with('stockProducts')->findOrFail($id);
                
                // Store data
                $customerId = $stock->customer_id;
                $date = $stock->t_date;
                $products = $stock->stockProducts->pluck('product_id')->toArray();
                
                // Delete stock products
                StockProduct::where('stock_id', $stock->id)->delete();
                
                // Delete the stock record
                $stock->delete(); // Soft delete
            });

            return response()->json([
                'success' => true,
                'message' => 'Stock-in record deleted successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting stock-in record: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete stock-in record',
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
            $customers = Customer::select('id', 'name', 'company_name')
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
     * Get products list for dropdown
     */
    public function getProducts(): JsonResponse
    {
        try {
            $products = Product::select('id', 'name', 'code', 'size', 'unit')
                ->where('status', 'active')
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
}
