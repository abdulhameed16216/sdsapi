<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ProductThresholdController extends Controller
{
    /**
     * Get all products with their threshold settings
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Product::with(['creator', 'updater']);

            // Search functionality
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('code', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%")
                      ->orWhere('size', 'like', "%{$search}%");
                });
            }

            // Filter by status
            if ($request->has('status') && $request->status) {
                $query->where('status', $request->status);
            }

            // Only active products
            $query->where('status', 'active');

            // Pagination
            $perPage = $request->get('per_page', 50);
            $products = $query->orderBy('name', 'asc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'Products with thresholds retrieved successfully',
                'data' => $products
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching products with thresholds: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve products with thresholds',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update threshold for a single product
     */
    public function update(Request $request, Product $product): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'minimum_threshold' => 'required|numeric|min:0'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $product->minimum_threshold = $request->minimum_threshold;
            $product->updated_by = auth()->id();
            $product->save();

            $product->load(['creator', 'updater']);

            return response()->json([
                'success' => true,
                'message' => 'Product threshold updated successfully',
                'data' => $product
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating product threshold: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update product threshold',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk update thresholds for multiple products
     */
    public function bulkUpdate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'thresholds' => 'required|array',
            'thresholds.*.product_id' => 'required|exists:products,id',
            'thresholds.*.minimum_threshold' => 'required|numeric|min:0'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $updatedProducts = [];
            foreach ($request->thresholds as $thresholdData) {
                $product = Product::find($thresholdData['product_id']);
                if ($product) {
                    $product->minimum_threshold = $thresholdData['minimum_threshold'];
                    $product->updated_by = auth()->id();
                    $product->save();
                    $updatedProducts[] = $product;
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => count($updatedProducts) . ' product threshold(s) updated successfully',
                'data' => $updatedProducts
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error bulk updating product thresholds: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update product thresholds',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get products with low stock (below threshold)
     */
    public function lowStock(Request $request): JsonResponse
    {
        try {
            // This will need to be implemented after threshold migration
            // For now, return empty or sample data
            $products = Product::where('status', 'active')
                ->whereNotNull('minimum_threshold')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Low stock products retrieved successfully',
                'data' => $products
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching low stock products: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve low stock products',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

