<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InternalStock;
use App\Models\InternalStockProduct;
use App\Models\Vendor;
use App\Models\Product;
use App\Models\CustomerReturn;
use App\Http\Controllers\Api\StockAlertController;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\Response;

class InternalStockInController extends Controller
{
    /**
     * Display a listing of internal stock-in records
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Get internal stock-in records with relationships
            $query = InternalStock::with(['vendor', 'internalStockProducts.product', 'creator'])
                ->whereNotNull('from_vendor_id'); // Internal stocks have from_vendor_id

            // Search functionality
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->whereHas('vendor', function ($q) use ($search) {
                        $q->where('vendor_name', 'like', "%{$search}%");
                    })->orWhereHas('internalStockProducts.product', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                          ->orWhere('code', 'like', "%{$search}%");
                    });
                });
            }

            // Filters
            if ($request->has('vendor_id') && $request->vendor_id) {
                $query->where('from_vendor_id', $request->vendor_id);
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
                    'message' => 'Internal stock-in records retrieved successfully',
                    'data' => $stocks
                ]);
            }

            $perPage = $request->get('per_page', 15);
            $stocks = $query->orderBy('t_date', 'desc')->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'Internal stock-in records retrieved successfully',
                'data' => $stocks
            ]);

        } catch (\Exception $e) {
            Log::error('Error retrieving internal stock-in records: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve internal stock-in records',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created internal stock-in record
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'vendor_id' => 'required|exists:vendors,id',
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

            DB::transaction(function () use ($data, &$stock) {
                // Create internal stock record for internal stock-in
                $stock = InternalStock::create([
                    'from_vendor_id' => $data['vendor_id'],
                    't_date' => $data['t_date'],
                    'notes' => $data['notes'] ?? null,
                    'created_by' => Auth::id(),
                    'status' => 'active'
                ]);

                // Create internal stock product records
                foreach ($data['products'] as $product) {
                    InternalStockProduct::create([
                        'internal_stock_id' => $stock->id,
                        'product_id' => $product['product_id'],
                        'stock_qty' => $product['stock_qty']
                    ]);
                }
            });

            // Trigger automatic threshold check after internal stock is added
            try {
                $stockAlertController = new StockAlertController();
                foreach ($data['products'] as $product) {
                    $stockAlertController->checkAndTriggerThresholdAlerts(null, $product['product_id']);
                }
            } catch (\Exception $e) {
                // Log error but don't fail the request
                Log::error('Error triggering threshold alerts after internal stock save', [
                    'error' => $e->getMessage()
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Internal stock-in record created successfully',
                'data' => $stock->load(['vendor', 'internalStockProducts.product', 'creator'])
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error creating internal stock-in record: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create internal stock-in record',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified internal stock-in record
     */
    public function show($id): JsonResponse
    {
        try {
            $stock = InternalStock::with(['vendor', 'internalStockProducts.product', 'creator'])
                ->where('id', $id)
                ->whereNotNull('from_vendor_id')
                ->first();

            if (!$stock) {
                return response()->json([
                    'success' => false,
                    'message' => 'Internal stock-in record not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Internal stock-in record retrieved successfully',
                'data' => $stock
            ]);

        } catch (\Exception $e) {
            Log::error('Error retrieving internal stock-in record: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve internal stock-in record',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified internal stock-in record
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'vendor_id' => 'sometimes|required|exists:vendors,id',
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
                $stock = InternalStock::with('internalStockProducts')
                    ->where('id', $id)
                    ->whereNotNull('from_vendor_id')
                    ->firstOrFail();
                
                // Update the internal stock record
                $stock->update([
                    'from_vendor_id' => $request->vendor_id ?? $stock->from_vendor_id,
                    't_date' => $request->t_date ?? $stock->t_date,
                    'notes' => $request->has('notes') ? $request->notes : $stock->notes,
                    'modified_by' => Auth::id()
                ]);

                // Update products if provided
                if ($request->has('products')) {
                    // Delete existing internal stock products
                    InternalStockProduct::where('internal_stock_id', $stock->id)->delete();

                    // Create new internal stock product records
                    foreach ($request->products as $product) {
                        InternalStockProduct::create([
                            'internal_stock_id' => $stock->id,
                            'product_id' => $product['product_id'],
                            'stock_qty' => $product['stock_qty']
                        ]);
                    }
                }
            });

            // Trigger automatic threshold check after internal stock is updated
            try {
                $stockAlertController = new StockAlertController();
                if ($request->has('products')) {
                    foreach ($request->products as $product) {
                        $stockAlertController->checkAndTriggerThresholdAlerts(null, $product['product_id']);
                    }
                } else {
                    // Check all products in the existing stock record
                    foreach ($stock->internalStockProducts as $product) {
                        $stockAlertController->checkAndTriggerThresholdAlerts(null, $product->product_id);
                    }
                }
            } catch (\Exception $e) {
                // Log error but don't fail the request
                Log::error('Error triggering threshold alerts after internal stock update', [
                    'error' => $e->getMessage()
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Internal stock-in record updated successfully',
                'data' => $stock->load(['vendor', 'internalStockProducts.product', 'creator'])
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating internal stock-in record: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update internal stock-in record',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete the specified internal stock-in record
     */
    public function destroy($id): JsonResponse
    {
        try {
            DB::transaction(function () use ($id) {
                $stock = InternalStock::with('internalStockProducts')
                    ->where('id', $id)
                    ->whereNotNull('from_vendor_id')
                    ->firstOrFail();
                
                // Delete internal stock products
                InternalStockProduct::where('internal_stock_id', $stock->id)->delete();
                
                // Delete the internal stock record (soft delete)
                $stock->delete();
            });

            return response()->json([
                'success' => true,
                'message' => 'Internal stock-in record deleted successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting internal stock-in record: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete internal stock-in record',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get vendors list for internal stock-in
     */
    public function getVendors(): JsonResponse
    {
        try {
            $vendors = Vendor::select('id', 'vendor_name')
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->orderBy('vendor_name')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Vendors retrieved successfully',
                'data' => $vendors
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve vendors',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get products list for internal stock-in
     * If vendor_id is provided, only return products assigned to that vendor
     */
    public function getProducts(Request $request): JsonResponse
    {
        try {
            $query = Product::select('id', 'name', 'code', 'size', 'unit')
                ->where('status', 'active');

            // Filter by vendor if vendor_id is provided
            if ($request->has('vendor_id') && $request->vendor_id) {
                $vendorId = $request->vendor_id;
                $query->whereHas('vendorProducts', function ($q) use ($vendorId) {
                    $q->where('vendor_id', $vendorId)
                      ->where('status', 'active');
                });
            }

            $products = $query->orderBy('name')->get();

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
     * Get internal stock availability report
     * Shows current available stock for each product
     */
    public function getAvailabilityReport(Request $request): JsonResponse
    {
        try {
            $productId = $request->get('product_id');
            
            // Get all products or specific product (include minimum_threshold)
            // Use get() to ensure all attributes are loaded, including minimum_threshold
            $productsQuery = Product::where('status', 'active')
                ->orderBy('name');
            
            if ($productId) {
                $productsQuery->where('id', $productId);
            }
            
            $products = $productsQuery->get();
            
            // Calculate availability for each product
            $availabilityReport = $products->map(function ($product) {
                // Stock In: sum of stock_qty from internal_stock_products for this product
                $stockIn = InternalStockProduct::where('product_id', $product->id)
                    ->whereNull('deleted_at')
                    ->sum('stock_qty');
                
                // Stock Out: sum of (delivery_qty - return_qty) from delivery_products for this product
                $stockOut = DB::table('delivery_products')
                    ->where('product_id', $product->id)
                    ->whereNull('deleted_at')
                    ->selectRaw('SUM(COALESCE(delivery_qty, 0) - COALESCE(return_qty, 0)) as net_delivery')
                    ->value('net_delivery') ?? 0;
                
                // Available Stock: stock_in - stock_out
                $availableStock = max(0, $stockIn - $stockOut);
                
                // Get minimum_threshold - ensure it's a number
                // Access the attribute directly from the model
                $minimumThreshold = $product->getAttribute('minimum_threshold');
                if ($minimumThreshold === null || $minimumThreshold === '' || $minimumThreshold === false) {
                    $minimumThreshold = 0;
                } else {
                    $minimumThreshold = (float)$minimumThreshold;
                }
                
                return [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_code' => $product->code,
                    'product_size' => $product->size,
                    'product_unit' => $product->unit,
                    'minimum_threshold' => $minimumThreshold,
                    'stock_in' => (int)$stockIn,
                    'stock_out' => (int)$stockOut,
                    'available_stock' => (int)$availableStock
                ];
            });
            
            return response()->json([
                'success' => true,
                'message' => 'Availability report retrieved successfully',
                'data' => $availabilityReport
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving availability report: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve availability report',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export internal stock-in records to Excel (CSV format)
     */
    public function exportExcel(Request $request): StreamedResponse
    {
        try {
            // Get internal stock-in records with relationships (same query as index)
            $query = InternalStock::with(['vendor', 'internalStockProducts.product', 'creator'])
                ->whereNotNull('from_vendor_id');

            // Apply same filters as index method
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->whereHas('vendor', function ($q) use ($search) {
                        $q->where('vendor_name', 'like', "%{$search}%");
                    })->orWhereHas('internalStockProducts.product', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                          ->orWhere('code', 'like', "%{$search}%");
                    });
                });
            }

            if ($request->has('vendor_id') && $request->vendor_id) {
                $query->where('from_vendor_id', $request->vendor_id);
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

            $filename = 'internal_stock_in_' . date('Y-m-d_His') . '.csv';

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
                    'Vendor',
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
                    $vendorName = $stock->vendor ? $stock->vendor->vendor_name : 'N/A';
                    $stockDate = $stock->t_date ? date('d/m/Y', strtotime($stock->t_date)) : 'N/A';
                    $status = $stock->status ?? 'N/A';
                    $stockId = 'STK-' . str_pad($stock->id, 6, '0', STR_PAD_LEFT);
                    $notes = $stock->notes ?? '';

                    if ($stock->internalStockProducts && count($stock->internalStockProducts) > 0) {
                        // Each product gets its own row with incrementing serial number
                        foreach ($stock->internalStockProducts as $product) {
                            $productName = $product->product ? $product->product->name : 'N/A';
                            $productCode = $product->product ? ($product->product->code ?? 'N/A') : 'N/A';
                            $quantity = $product->stock_qty ?? 0;
                            $unit = $product->product ? ($product->product->unit ?? '') : '';

                            $row = [
                                $sno,
                                $stockId,
                                $vendorName,
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
                            $vendorName,
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
            Log::error('Error exporting internal stock-in to Excel: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to export internal stock-in',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export internal stock-in records to PDF
     */
    public function exportPdf(Request $request): Response
    {
        try {
            // Get internal stock-in records with relationships (same query as index)
            $query = InternalStock::with(['vendor', 'internalStockProducts.product', 'creator'])
                ->whereNotNull('from_vendor_id');

            // Apply same filters as index method
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->whereHas('vendor', function ($q) use ($search) {
                        $q->where('vendor_name', 'like', "%{$search}%");
                    })->orWhereHas('internalStockProducts.product', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                          ->orWhere('code', 'like', "%{$search}%");
                    });
                });
            }

            if ($request->has('vendor_id') && $request->vendor_id) {
                $query->where('from_vendor_id', $request->vendor_id);
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
    <title>EBMS - Internal Stock In Report</title>
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
    <div class="report-subtitle">Internal Stock In Report</div>
    <div class="report-meta">Generated on: ' . date('d/m/Y H:i:s') . '</div>
    <table>
        <thead>
            <tr>
                <th>S.No</th>
                <th>Stock ID</th>
                <th>Vendor</th>
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
                $vendorName = htmlspecialchars($stock->vendor ? $stock->vendor->vendor_name : 'N/A');
                $stockDate = $stock->t_date ? date('d/m/Y', strtotime($stock->t_date)) : 'N/A';
                $status = htmlspecialchars($stock->status ?? 'N/A');
                $stockId = 'STK-' . str_pad($stock->id, 6, '0', STR_PAD_LEFT);
                $notes = htmlspecialchars($stock->notes ?? '');

                if ($stock->internalStockProducts && count($stock->internalStockProducts) > 0) {
                    // Each product gets its own row with incrementing serial number
                    foreach ($stock->internalStockProducts as $product) {
                        $productName = htmlspecialchars($product->product ? $product->product->name : 'N/A');
                        $productCode = htmlspecialchars($product->product ? ($product->product->code ?? 'N/A') : 'N/A');
                        $quantity = $product->stock_qty ?? 0;
                        $unit = htmlspecialchars($product->product ? ($product->product->unit ?? '') : '');

                        $html .= '<tr class="main-row">
                            <td>' . $sno . '</td>
                            <td>' . $stockId . '</td>
                            <td>' . $vendorName . '</td>
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
                        <td>' . $vendorName . '</td>
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
                ->header('Content-Disposition', 'inline; filename="internal_stock_in_' . date('Y-m-d_His') . '.pdf"');

        } catch (\Exception $e) {
            Log::error('Error exporting internal stock-in to PDF: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to export internal stock-in to PDF',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Return internal stock to vendor
     * Creates internal stock out records and return records
     */
    public function returnToVendor(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'vendor_id' => 'required|exists:vendors,id',
                'return_date' => 'required|date',
                'return_reason' => 'required|string|max:255',
                'notes' => 'nullable|string|max:1000',
                'products' => 'required|array|min:1',
                'products.*.product_id' => 'required|exists:products,id',
                'products.*.return_qty' => 'required|integer|min:1'
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

            DB::transaction(function () use ($request, $userId, $currentDate, &$stock, &$returnRecords) {
                // Create internal stock out record
                // For returns to vendor, from_vendor_id should be the vendor we're returning to
                $stock = InternalStock::create([
                    'from_vendor_id' => $request->vendor_id, // Vendor we're returning to
                    't_date' => $request->return_date,
                    'notes' => $request->notes,
                    'created_by' => $userId,
                    'status' => 'active'
                ]);

                $returnRecords = [];

                // Create internal stock product records (stock out)
                foreach ($request->products as $productData) {
                    $productId = $productData['product_id'];
                    $returnQty = $productData['return_qty'];

                    // Create stock out record
                    // Since internal_stocks_products doesn't have stock_type column,
                    // we use negative stock_qty to represent stock out (returns to vendor)
                    // This reduces the available stock when summed in availability calculations
                    InternalStockProduct::create([
                        'internal_stock_id' => $stock->id,
                        'product_id' => $productId,
                        'stock_qty' => -$returnQty // Negative to represent stock out
                    ]);

                    // Create customer return record for tracking (with vendor_id)
                    $returnRecord = CustomerReturn::create([
                        'customer_id' => null, // No customer for internal stock returns
                        'product_id' => $productId,
                        'vendor_id' => $request->vendor_id,
                        'stock_id' => null, // No customer stock record
                        'internal_stock_id' => $stock->id,
                        'return_qty' => $returnQty,
                        'return_reason' => $request->return_reason, // Use return reason from request
                        'return_date' => $request->return_date,
                        'status' => 'returned_to_vendor', // Directly marked as returned
                        'action_taken' => 'return_to_vendor',
                        'admin_notes' => $request->notes,
                        'approved_by' => $userId,
                        'approved_at' => $currentDate,
                        'created_by' => $userId
                    ]);

                    $returnRecords[] = $returnRecord;
                }
            });

            // Trigger automatic threshold check after internal stock is returned to vendor
            try {
                $stockAlertController = new StockAlertController();
                foreach ($request->products as $productData) {
                    $stockAlertController->checkAndTriggerThresholdAlerts(null, $productData['product_id']);
                }
            } catch (\Exception $e) {
                // Log error but don't fail the request
                Log::error('Error triggering threshold alerts after internal stock return', [
                    'error' => $e->getMessage()
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Internal stock returned to vendor successfully',
                'data' => [
                    'stock' => $stock->load(['vendor', 'internalStockProducts.product']),
                    'returns' => $returnRecords
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error returning internal stock to vendor: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to return stock to vendor',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get available stock for a product from internal stocks
     */
    private function getAvailableStock($productId): int
    {
        // Stock In: sum of stock_qty from internal_stock_products for this product
        // All records in internal_stocks_products are stock in (from vendors)
        $stockIn = InternalStockProduct::where('product_id', $productId)
            ->whereNull('deleted_at')
            ->sum('stock_qty');

        // Stock Out: sum of (delivery_qty - return_qty) from delivery_products for this product
        // This represents stock delivered to customers
        $stockOut = DB::table('delivery_products')
            ->where('product_id', $productId)
            ->whereNull('deleted_at')
            ->selectRaw('SUM(COALESCE(delivery_qty, 0) - COALESCE(return_qty, 0)) as net_delivery')
            ->value('net_delivery') ?? 0;

        // Available = Stock In - Stock Out
        return max(0, $stockIn - $stockOut);
    }
}

