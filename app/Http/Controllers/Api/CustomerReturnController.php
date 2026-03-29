<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerReturn;
use App\Models\Customer;
use App\Models\Product;
use App\Models\InternalStock;
use App\Models\InternalStockProduct;
use App\Models\Vendor;
use App\Http\Controllers\Api\StockAlertController;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class CustomerReturnController extends Controller
{
    /**
     * Display a listing of customer returns
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = CustomerReturn::with([
                'customer',
                'product',
                'delivery',
                'stock',
                'approver',
                'internalStock',
                'vendor',
                'creator'
            ]);

            // Search functionality
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->whereHas('customer', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                          ->orWhere('company_name', 'like', "%{$search}%");
                    })->orWhereHas('product', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                          ->orWhere('code', 'like', "%{$search}%");
                    })->orWhereHas('vendor', function ($q) use ($search) {
                        $q->where('vendor_name', 'like', "%{$search}%");
                    });
                });
            }

            // Filters
            if ($request->has('customer_id') && $request->customer_id) {
                $query->where('customer_id', $request->customer_id);
            }
            if ($request->has('product_id') && $request->product_id) {
                $query->where('product_id', $request->product_id);
            }
            if ($request->has('status') && $request->status) {
                $query->where('status', $request->status);
            }
            if ($request->has('return_reason') && $request->return_reason) {
                $query->where('return_reason', $request->return_reason);
            }
            if ($request->has('date_from') && $request->date_from) {
                $query->whereDate('return_date', '>=', $request->date_from);
            }
            if ($request->has('date_to') && $request->date_to) {
                $query->whereDate('return_date', '<=', $request->date_to);
            }

            $perPage = $request->get('per_page', 15);
            $returns = $query->orderBy('return_date', 'desc')
                           ->orderBy('created_at', 'desc')
                           ->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'Customer returns retrieved successfully',
                'data' => $returns
            ]);

        } catch (\Exception $e) {
            Log::error('Error retrieving customer returns: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve customer returns',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created customer return
     * Returns are created in 'pending' status and do NOT affect stock until approved
     * Supports both single product and multiple products
     */
    public function store(Request $request): JsonResponse
    {
        try {
            // Check if products array is provided (bulk create) or single product
            if ($request->has('products') && is_array($request->products)) {
                // Bulk create for multiple products
                $validator = Validator::make($request->all(), [
                    'customer_id' => 'required|exists:customers,id',
                    'return_date' => 'required|date',
                    'products' => 'required|array|min:1',
                    'products.*.product_id' => 'required|exists:products,id',
                    'products.*.return_qty' => 'required|integer|min:1',
                    'products.*.return_reason' => 'required|string|max:255',
                    'products.*.return_reason_details' => 'nullable|string|max:1000',
                    'delivery_id' => 'nullable|exists:deliveries,id',
                    'stock_id' => 'nullable|exists:stocks,id'
                ]);

                if ($validator->fails()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Validation failed',
                        'errors' => $validator->errors()
                    ], 422);
                }

                $returns = [];
                DB::transaction(function () use ($request, &$returns) {
                    foreach ($request->products as $productData) {
                        $return = CustomerReturn::create([
                            'customer_id' => $request->customer_id,
                            'product_id' => $productData['product_id'],
                            'return_qty' => $productData['return_qty'],
                            'return_reason' => $productData['return_reason'],
                            'return_reason_details' => $productData['return_reason_details'] ?? null,
                            'return_date' => $request->return_date,
                            'delivery_id' => $request->delivery_id ?? null,
                            'stock_id' => $request->stock_id ?? null,
                            'status' => 'pending', // Always starts as pending
                            'created_by' => Auth::id()
                        ]);
                        $returns[] = $return->load(['customer', 'product', 'creator']);
                    }
                });

                return response()->json([
                    'success' => true,
                    'message' => count($returns) . ' customer return(s) created successfully. Waiting for admin approval.',
                    'data' => $returns
                ], 201);
            } else {
                // Single product create (backward compatibility)
            $validator = Validator::make($request->all(), [
                'customer_id' => 'required|exists:customers,id',
                'product_id' => 'required|exists:products,id',
                'return_qty' => 'required|integer|min:1',
                'return_reason' => 'required|string|max:255',
                'return_reason_details' => 'nullable|string|max:1000',
                'return_date' => 'required|date',
                'delivery_id' => 'nullable|exists:deliveries,id',
                'stock_id' => 'nullable|exists:stocks,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $return = CustomerReturn::create([
                'customer_id' => $request->customer_id,
                'product_id' => $request->product_id,
                'return_qty' => $request->return_qty,
                'return_reason' => $request->return_reason,
                'return_reason_details' => $request->return_reason_details,
                'return_date' => $request->return_date,
                'delivery_id' => $request->delivery_id,
                'stock_id' => $request->stock_id,
                'status' => 'pending', // Always starts as pending
                'created_by' => Auth::id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Customer return created successfully. Waiting for admin approval.',
                'data' => $return->load(['customer', 'product', 'creator'])
            ], 201);
            }

        } catch (\Exception $e) {
            Log::error('Error creating customer return: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create customer return',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified customer return
     */
    public function show($id): JsonResponse
    {
        try {
            $return = CustomerReturn::with([
                'customer',
                'product',
                'delivery',
                'stock',
                'approver',
                'internalStock',
                'vendor',
                'creator',
                'modifier'
            ])->findOrFail($id);

            return response()->json([
                'success' => true,
                'message' => 'Customer return retrieved successfully',
                'data' => $return
            ]);

        } catch (\Exception $e) {
            Log::error('Error retrieving customer return: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve customer return',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified customer return
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $return = CustomerReturn::findOrFail($id);

            // Only allow updates if status is pending
            if ($return->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot update return that is not pending'
                ], 422);
            }

            $validator = Validator::make($request->all(), [
                'return_qty' => 'sometimes|required|integer|min:1',
                'return_reason' => 'sometimes|required|string|max:255',
                'return_reason_details' => 'nullable|string|max:1000',
                'return_date' => 'sometimes|required|date'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $return->update([
                'return_qty' => $request->get('return_qty', $return->return_qty),
                'return_reason' => $request->get('return_reason', $return->return_reason),
                'return_reason_details' => $request->get('return_reason_details', $return->return_reason_details),
                'return_date' => $request->get('return_date', $return->return_date),
                'modified_by' => Auth::id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Customer return updated successfully',
                'data' => $return->load(['customer', 'product', 'creator'])
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating customer return: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update customer return',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Approve a customer return (Admin only)
     * Stock-out was already created during delivery acceptance, so we just change status to approved
     * Return stays in return table for further actions (move to internal, etc.)
     */
    public function approve(Request $request, $id): JsonResponse
    {
        try {
            $return = CustomerReturn::findOrFail($id);

            if ($return->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only pending returns can be approved'
                ], 422);
            }

            $validator = Validator::make($request->all(), [
                'admin_notes' => 'nullable|string|max:1000'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $userId = Auth::id();
            $currentDate = now();

            // Stock-out was already created during delivery acceptance
            // Just change status to approved (no additional stock changes needed)
            $return->update([
                'status' => 'approved',
                'approved_by' => $userId,
                'approved_at' => $currentDate,
                'admin_notes' => $request->admin_notes,
                'modified_by' => $userId
            ]);

            // Trigger automatic threshold check after return is approved
            // Stock OUT was already created, so check customer stock
            try {
                $stockAlertController = new StockAlertController();
                $stockAlertController->checkAndTriggerThresholdAlerts($return->customer_id, $return->product_id);
            } catch (\Exception $e) {
                // Log error but don't fail the request
                Log::error('Error triggering threshold alerts after return approval', [
                    'error' => $e->getMessage(),
                    'return_id' => $return->id
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Customer return approved successfully. Return is now available for further actions.',
                'data' => $return->load(['customer', 'product', 'approver'])
            ]);

        } catch (\Exception $e) {
            Log::error('Error approving customer return: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to approve customer return',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reject a customer return (Admin only)
     * Reverses the return and adds stock back to customer
     */
    public function reject(Request $request, $id): JsonResponse
    {
        try {
            $return = CustomerReturn::findOrFail($id);

            // Allow rejection of pending or approved returns
            if (!in_array($return->status, ['pending', 'approved'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only pending or approved returns can be rejected'
                ], 422);
            }

            $validator = Validator::make($request->all(), [
                'rejection_reason' => 'required|string|max:1000',
                'rejection_date' => 'required|date'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            $userId = Auth::id();
            $currentDate = now();

            // Stock-out was already created during delivery acceptance (for both pending and approved)
            // We need to reverse it by creating stock-in to add stock back to customer
            
            // If return was moved to internal stocks, reverse that first
            if ($return->action_taken === 'move_to_internal' && $return->internal_stock_id) {
                $internalStock = InternalStock::find($return->internal_stock_id);
                if ($internalStock) {
                    // Delete internal stock products
                    \App\Models\InternalStockProduct::where('internal_stock_id', $internalStock->id)->delete();
                    // Delete internal stock
                    $internalStock->delete();
                }
            }

            // Reverse the stock-out by creating stock-in (add stock back to customer)
            // Stock-out was created during delivery acceptance, so we need to reverse it
            $reversalStock = \App\Models\Stock::create([
                'customer_id' => $return->customer_id,
                'from_cust_id' => null,
                't_date' => $request->rejection_date,
                'notes' => $request->rejection_reason ?? "Return #{$return->id} rejected - stock added back",
                'transfer_status' => 'customer_return_rejected',
                'created_by' => $userId,
                'status' => 'active'
            ]);

            // Create stock in record to add back to customer stock
            \App\Models\StockProduct::create([
                'stock_id' => $reversalStock->id,
                'product_id' => $return->product_id,
                'stock_qty' => $return->return_qty,
                'stock_type' => 'in' // Stock in adds back to customer stock
            ]);

            // Update return record
            $return->update([
                'status' => 'rejected',
                'rejection_reason' => $request->rejection_reason,
                'action_taken' => null, // Clear action taken
                'internal_stock_id' => null, // Clear internal stock reference
                'modified_by' => $userId
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Customer return rejected successfully. Stock has been returned to customer.',
                'data' => $return->load(['customer', 'product', 'approver'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error rejecting customer return: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to reject customer return',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Move approved return to internal stocks (Admin only)
     */
    public function moveToInternal(Request $request, $id): JsonResponse
    {
        try {
            $return = CustomerReturn::findOrFail($id);

            if (!$return->canMoveToInternal()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Return must be approved and not yet processed'
                ], 422);
            }

            $validator = Validator::make($request->all(), [
                'internal_stock_date' => 'required|date',
                'notes' => 'nullable|string|max:1000'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            $userId = Auth::id();
            $currentDate = now();

            // Build notes from customer return (return_reason and return_reason_details)
            $internalStockNotes = $request->notes ?? '';
            if ($return->return_reason) {
                $internalStockNotes .= ($internalStockNotes ? "\n" : '') . "Return reason: " . $return->return_reason;
            }
            if ($return->return_reason_details) {
                $internalStockNotes .= ($internalStockNotes ? "\n" : '') . "Details: " . $return->return_reason_details;
            }
            if (!$internalStockNotes) {
                $internalStockNotes = "Moved from customer return #{$return->id}";
            }

            // Create internal stock record with notes from customer return
            $internalStock = InternalStock::create([
                'from_vendor_id' => null, // Customer return, not from vendor
                't_date' => $request->internal_stock_date,
                'notes' => $internalStockNotes,
                'created_by' => $userId,
                'status' => 'active'
            ]);

            // Create internal stock product (stock in for internal stocks)
            InternalStockProduct::create([
                'internal_stock_id' => $internalStock->id,
                'product_id' => $return->product_id,
                'stock_qty' => $return->return_qty
            ]);

            // Stock-out was already created during delivery acceptance, so no need to create another one

            // Update return record
            $return->update([
                'status' => 'moved_to_internal',
                'action_taken' => 'move_to_internal',
                'internal_stock_id' => $internalStock->id,
                'modified_by' => $userId
            ]);

            DB::commit();

            // Trigger automatic threshold check after return is moved to internal stock
            try {
                $stockAlertController = new StockAlertController();
                $stockAlertController->checkAndTriggerThresholdAlerts(null, $return->product_id);
            } catch (\Exception $e) {
                // Log error but don't fail the request
                Log::error('Error triggering threshold alerts after return moved to internal', [
                    'error' => $e->getMessage(),
                    'return_id' => $return->id
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Return moved to internal stocks successfully',
                'data' => [
                    'return' => $return->load(['customer', 'product', 'internalStock']),
                    'internal_stock' => $internalStock->load('internalStockProducts.product')
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error moving return to internal stocks: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to move return to internal stocks',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Return to vendor is NOT allowed directly from customer returns
     * Must first move to internal stocks, then return to vendor from internal stocks
     * This method is disabled/removed
     */
    public function returnToVendor(Request $request, $id): JsonResponse
    {
                return response()->json([
                    'success' => false,
            'message' => 'Return to vendor is not allowed directly from customer returns. Please move to internal stocks first, then return to vendor from internal stocks.'
                ], 422);
    }

    /**
     * Dispose approved return (Admin only)
     */
    public function dispose(Request $request, $id): JsonResponse
    {
        try {
            $return = CustomerReturn::findOrFail($id);

            if ($return->status !== 'approved' || $return->action_taken !== null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Return must be approved and not yet processed'
                ], 422);
            }

            $validator = Validator::make($request->all(), [
                'notes' => 'nullable|string|max:1000'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $return->update([
                'status' => 'disposed',
                'action_taken' => 'dispose',
                'admin_notes' => ($return->admin_notes ? $return->admin_notes . "\n" : '') . ($request->notes ?? ''),
                'modified_by' => Auth::id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Return disposed successfully',
                'data' => $return->load(['customer', 'product'])
            ]);

        } catch (\Exception $e) {
            Log::error('Error disposing return: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to dispose return',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get pending returns count (for admin dashboard)
     */
    public function getPendingCount(): JsonResponse
    {
        try {
            $count = CustomerReturn::where('status', 'pending')->count();

            return response()->json([
                'success' => true,
                'data' => ['pending_count' => $count]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get pending count',
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
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve products',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get return balance for a customer and product on a specific date
     * Return balance = Available stock - Already returned quantities (pending + approved)
     */
    public function getReturnBalance(Request $request): JsonResponse
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

            $customerId = $request->customer_id;
            $productId = $request->product_id;
            $date = \Carbon\Carbon::parse($request->date)->format('Y-m-d');

            // Get available stock from stock availability
            $availableStock = \App\Models\StockAvailability::calculateAvailableQty(
                $customerId,
                $productId,
                $date
            );

            // Get already returned quantities (pending + approved returns)
            // Only count returns that are pending or approved (not rejected, not processed)
            $alreadyReturnedQty = CustomerReturn::where('customer_id', $customerId)
                ->where('product_id', $productId)
                ->whereDate('return_date', '<=', $date) // Returns up to and including the date
                ->whereIn('status', ['pending', 'approved']) // Only pending and approved returns
                ->sum('return_qty');

            // Calculate return balance
            $returnBalance = max(0, $availableStock - $alreadyReturnedQty);

            // Get product unit
            $product = \App\Models\Product::find($productId);
            $productUnit = $product ? $product->unit : '';

            return response()->json([
                'success' => true,
                'message' => 'Return balance retrieved successfully',
                'data' => [
                    'customer_id' => $customerId,
                    'product_id' => $productId,
                    'date' => $date,
                    'available_stock' => $availableStock,
                    'already_returned_qty' => $alreadyReturnedQty,
                    'return_balance' => $returnBalance,
                    'product_unit' => $productUnit
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting return balance: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to get return balance',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified customer return
     */
    public function destroy($id): JsonResponse
    {
        try {
            $return = CustomerReturn::findOrFail($id);

            // Only allow deletion if status is pending or rejected
            if (!in_array($return->status, ['pending', 'rejected'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete return that has been processed'
                ], 422);
            }

            $return->delete();

            return response()->json([
                'success' => true,
                'message' => 'Customer return deleted successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Error deleting customer return: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete customer return',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
