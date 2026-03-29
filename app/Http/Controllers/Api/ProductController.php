<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    /**
     * Display a listing of products
     */
    public function index(Request $request): JsonResponse
    {
        try {
            Log::info('Products index called with request:', $request->all());
            
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

            // Filter by unit
            if ($request->has('unit') && $request->unit) {
                $query->where('unit', $request->unit);
            }

            // Check if all data is requested (no pagination)
            if ($request->has('all') && $request->get('all') === 'true') {
                $products = $query->orderBy('created_at', 'desc')->get();
                return response()->json([
                    'success' => true,
                    'message' => 'Products retrieved successfully',
                    'data' => $products
                ]);
            }

            // Pagination
            $perPage = $request->get('per_page', 15);
            $products = $query->orderBy('created_at', 'desc')->paginate($perPage);

            Log::info('Products query result:', [
                'total' => $products->total(),
                'count' => $products->count(),
                'current_page' => $products->currentPage(),
                'first_product' => $products->count() > 0 ? $products->first()->toArray() : null
            ]);

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
     * Store a newly created product
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:255|unique:products,code',
            'description' => 'nullable|string',
            'size' => 'required|string|max:255',
            'unit' => 'required|string|max:255',
            'price' => 'nullable|numeric|min:0',
            'product_image' => 'nullable|image|mimes:jpeg,png,jpg|max:5120', // 5MB max
            'status' => 'nullable|in:active,inactive',
            'notes' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $productData = $request->except(['product_image']);
            $productData['created_by'] = auth()->id();
            $productData['updated_by'] = auth()->id();

            $product = Product::create($productData);
            
            // Handle product image upload after product creation
            if ($request->hasFile('product_image')) {
                $image = $request->file('product_image');
                $imagePath = $this->storeProductImage($image, $product->id);
                $product->update(['product_image' => $imagePath]);
            }
            
            $product->load(['creator', 'updater']);

            return response()->json([
                'success' => true,
                'message' => 'Product created successfully',
                'data' => $product
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error creating product: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create product',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified product
     */
    public function show(Product $product): JsonResponse
    {
        try {
            $product->load(['creator', 'updater']);

            return response()->json([
                'success' => true,
                'message' => 'Product retrieved successfully',
                'data' => $product
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching product: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve product',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified product
     */
    public function update(Request $request, Product $product): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'code' => 'sometimes|required|string|max:255|unique:products,code,' . $product->id,
            'description' => 'nullable|string',
            'size' => 'sometimes|required|string|max:255',
            'unit' => 'sometimes|required|string|max:255',
            'price' => 'nullable|numeric|min:0',
            'product_image' => 'nullable|image|mimes:jpeg,png,jpg|max:5120',
            'status' => 'nullable|in:active,inactive',
            'notes' => 'nullable|string',
            'remove_image' => 'nullable|in:true,false,1,0,"true","false"'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $productData = $request->except(['product_image', 'remove_image']);
            $productData['updated_by'] = auth()->id();

            // Handle image removal
            $removeImage = $request->get('remove_image');
            $shouldRemoveImage = $removeImage === true || $removeImage === 'true' || $removeImage === '1' || $removeImage === 1;
            
            if ($request->has('remove_image') && $shouldRemoveImage && $product->product_image) {
                // Delete the existing image file
                if (Storage::disk('public')->exists($product->product_image)) {
                    Storage::disk('public')->delete($product->product_image);
                }
                $productData['product_image'] = null;
                Log::info('Image removed for product: ' . $product->id);
            }

            // Handle new image upload
            if ($request->hasFile('product_image')) {
                // Delete old image if exists
                if ($product->product_image && Storage::disk('public')->exists($product->product_image)) {
                    Storage::disk('public')->delete($product->product_image);
                }

                $image = $request->file('product_image');
                $imagePath = $this->storeProductImage($image, $product->id);
                $productData['product_image'] = $imagePath;
                Log::info('Image uploaded for product: ' . $product->id . ', path: ' . $imagePath);
            }

            $product->update($productData);
            $product->load(['creator', 'updater']);

            return response()->json([
                'success' => true,
                'message' => 'Product updated successfully',
                'data' => $product
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating product: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update product',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified product
     */
    public function destroy(Product $product): JsonResponse
    {
        try {
            // Delete product image if exists
            if ($product->product_image && Storage::disk('public')->exists($product->product_image)) {
                Storage::disk('public')->delete($product->product_image);
            }

            // Soft delete the product (mark as deleted)
            $product->delete();

            return response()->json([
                'success' => true,
                'message' => 'Product deleted successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Error deleting product: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete product',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get product image URL
     */
    public function getProductImageUrl(Product $product): JsonResponse
    {
        try {
            if (!$product->product_image) {
                return response()->json([
                    'success' => false,
                    'message' => 'No image found for this product'
                ], 404);
            }

            $baseUrl = url('/');
            $imageUrl = $baseUrl . '/storage/' . $product->product_image;

            return response()->json([
                'success' => true,
                'data' => [
                    'image_url' => $imageUrl
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting product image URL: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to get product image URL',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get product statistics
     */
    public function stats(Product $product): JsonResponse
    {
        try {
            $stats = [
                'total_vendors' => $product->stocks()->count(),
                'total_quantity' => $product->stocks()->sum('current_quantity'),
                'low_stock_vendors' => $product->stocks()->lowStock()->count(),
                'out_of_stock_vendors' => $product->stocks()->outOfStock()->count(),
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting product stats: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to get product statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get available units
     */
    public function units(): JsonResponse
    {
        try {
            $units = Product::distinct()
                ->pluck('unit')
                ->filter()
                ->values();

            return response()->json([
                'success' => true,
                'data' => [
                    'units' => $units
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting units: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to get units',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export products to Excel (CSV format)
     */
    public function exportExcel(Request $request): StreamedResponse
    {
        try {
            $query = Product::whereNull('deleted_at');

            // Apply filters if provided
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('code', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%")
                      ->orWhere('size', 'like', "%{$search}%");
                });
            }

            if ($request->has('status') && $request->status) {
                $query->where('status', $request->status);
            }

            if ($request->has('unit') && $request->unit) {
                $query->where('unit', $request->unit);
            }

            $products = $query->orderBy('name', 'asc')->get();

            $filename = 'products_report_' . date('Y-m-d_His') . '.csv';

            $headers = [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Cache-Control' => 'max-age=0',
            ];

            $callback = function () use ($products) {
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
                $headers = ['SI No', 'Product Name', 'Code', 'Size', 'Unit', 'Price', 'Status', 'Description'];
                fputcsv($output, array_map($escapeCsv, $headers));

                // Data rows
                $sno = 1;
                foreach ($products as $product) {
                    fputcsv($output, array_map($escapeCsv, [
                        $sno++,
                        $product->name ?? '',
                        $product->code ?? '',
                        $product->size ?? '',
                        $product->unit ?? '',
                        $product->price ?? '0.00',
                        ucfirst($product->status ?? 'active'),
                        $product->description ?? ''
                    ]));
                }

                fclose($output);
            };

            return response()->stream($callback, 200, $headers);

        } catch (\Exception $e) {
            Log::error('Error exporting products to Excel: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to export products',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export products to PDF (HTML format for printing)
     */
    public function exportPdf(Request $request): Response
    {
        try {
            $query = Product::whereNull('deleted_at');

            // Apply filters if provided
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('code', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%")
                      ->orWhere('size', 'like', "%{$search}%");
                });
            }

            if ($request->has('status') && $request->status) {
                $query->where('status', $request->status);
            }

            if ($request->has('unit') && $request->unit) {
                $query->where('unit', $request->unit);
            }

            $products = $query->orderBy('name', 'asc')->get();

            // Generate HTML content for PDF
            $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>EBMS - Products Report</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 10px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { border: 1px solid #ddd; padding: 6px; text-align: left; }
        th { background-color: #dc3545; color: white; font-weight: bold; }
        .report-title { text-align: center; font-size: 18px; font-weight: bold; margin-bottom: 5px; }
        .report-subtitle { text-align: center; font-size: 14px; color: #6c757d; margin-bottom: 10px; }
        .report-meta { text-align: center; font-size: 9px; color: #6c757d; margin-bottom: 15px; }
        tr:nth-child(even) { background-color: #f2f2f2; }
    </style>
</head>
<body>
    <div class="report-title">EBMS - Products Report</div>
    <div class="report-subtitle">Generated on ' . date('d M Y, h:i A') . '</div>
    <div class="report-meta">Total Products: ' . $products->count() . '</div>
    <table>
        <thead>
            <tr>
                <th>SI No</th>
                <th>Product Name</th>
                <th>Code</th>
                <th>Size</th>
                <th>Unit</th>
                <th>Price</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>';

            $sno = 1;
            foreach ($products as $product) {
                $html .= '<tr>
                    <td>' . $sno++ . '</td>
                    <td>' . htmlspecialchars($product->name ?? '') . '</td>
                    <td>' . htmlspecialchars($product->code ?? '') . '</td>
                    <td>' . htmlspecialchars($product->size ?? '') . '</td>
                    <td>' . htmlspecialchars($product->unit ?? '') . '</td>
                    <td>?' . number_format($product->price ?? 0, 2) . '</td>
                    <td>' . ucfirst($product->status ?? 'active') . '</td>
                </tr>';
            }

            $html .= '</tbody>
    </table>
</body>
</html>';

            return response($html, 200)
                ->header('Content-Type', 'text/html; charset=UTF-8')
                ->header('Content-Disposition', 'inline; filename="products_report_' . date('Y-m-d_His') . '.html"');

        } catch (\Exception $e) {
            Log::error('Error exporting products to PDF: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to export products',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store product image
     */
    private function storeProductImage($file, $productId): string
    {
        // Create folder structure in public: files/products/product_{id}/
        $folderPath = public_path("files/products/product_{$productId}");
        
        // Create directory if it doesn't exist
        if (!file_exists($folderPath)) {
            mkdir($folderPath, 0755, true);
        }
        
        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $extension = $file->getClientOriginalExtension();
        $timestamp = now()->setTimezone('Asia/Kolkata')->format('d_m_Y_H_i_s');
        $filename = $originalName . '_' . $timestamp . '.' . $extension;
        
        // Move file to public folder
        $file->move($folderPath, $filename);
        
        // Return relative path from public folder
        return "files/products/product_{$productId}/{$filename}";
    }
}
