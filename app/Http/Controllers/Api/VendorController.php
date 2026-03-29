<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vendor;
use App\Models\VendorProduct;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class VendorController extends Controller
{
    /**
     * Display a listing of vendors
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // SoftDeletes trait automatically filters out deleted records
            $query = Vendor::query();

            // Search functionality
            if ($request->has('search') && $request->get('search')) {
                $search = $request->get('search');
                $query->where(function ($q) use ($search) {
                    $q->where('vendor_name', 'like', "%{$search}%")
                      ->orWhere('contact_person', 'like', "%{$search}%")
                      ->orWhere('mobile', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            }

            // Filter by status
            if ($request->has('status') && $request->get('status')) {
                $query->where('status', $request->get('status'));
            }

            // Check if all data is requested (no pagination)
            if ($request->has('all') && $request->get('all') === 'true') {
                $vendors = $query->orderBy('created_at', 'desc')->get();
                return response()->json([
                    'success' => true,
                    'message' => 'Vendors retrieved successfully',
                    'data' => $vendors
                ]);
            }

            $vendors = $query->orderBy('created_at', 'desc')->paginate($request->get('per_page', 15));

            return response()->json([
                'success' => true,
                'message' => 'Vendors retrieved successfully',
                'data' => $vendors
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving vendors',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created vendor
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'vendor_name' => 'required|string|max:255|unique:vendors,vendor_name,NULL,id,deleted_at,NULL',
                'contact_person' => 'nullable|string|max:255',
                'mobile' => 'nullable|string|max:20|unique:vendors,mobile,NULL,id,deleted_at,NULL',
                'telephone' => 'nullable|string|max:20',
                'email' => 'nullable|email|max:255|unique:vendors,email,NULL,id,deleted_at,NULL',
                'address' => 'nullable|string',
                'gst_no' => 'nullable|string|max:50',
                'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
                'status' => 'nullable|in:active,inactive',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation errors',
                    'errors' => $validator->errors()
                ], 422);
            }

            $data = $request->all();

            // Handle logo upload
            if ($request->hasFile('logo')) {
                $logo = $request->file('logo');
                $logoName = time() . '_' . $logo->getClientOriginalName();
                
                // Create folder structure in public: files/vendors/logos/
                $folderPath = public_path('files/vendors/logos');
                if (!file_exists($folderPath)) {
                    mkdir($folderPath, 0755, true);
                }
                
                $logo->move($folderPath, $logoName);
                $data['logo'] = "files/vendors/logos/{$logoName}";
            }

            // Set default status if not provided
            if (!isset($data['status'])) {
                $data['status'] = 'active';
            }

            // Set created_by if user is authenticated
            if (auth()->check()) {
                $data['created_by'] = auth()->id();
            }

            $vendor = Vendor::create($data);

            return response()->json([
                'success' => true,
                'message' => 'Vendor created successfully',
                'data' => [
                    'vendor' => $vendor
                ]
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error creating vendor',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified vendor
     */
    public function show($id): JsonResponse
    {
        try {
            // SoftDeletes will automatically filter out deleted records
            $vendor = Vendor::find($id);
            
            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor not found'
                ], 404);
            }
        
            return response()->json([
                'success' => true,
                'message' => 'Vendor retrieved successfully',
                'data' => [
                    'vendor' => $vendor
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving vendor',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update vendor via POST method
     */
    public function updateViaPost(Request $request, $id): JsonResponse
    {
        return $this->update($request, $id);
    }

    /**
     * Update the specified vendor
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            // SoftDeletes will automatically filter out deleted records
            $vendor = Vendor::find($id);
            
            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor not found'
                ], 404);
            }

            $rules = [
                'vendor_name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('vendors', 'vendor_name')->ignore($id)->whereNull('deleted_at')],
                'contact_person' => 'nullable|string|max:255',
                'telephone' => 'nullable|string|max:20',
                'address' => 'nullable|string',
                'gst_no' => 'nullable|string|max:50',
                'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
                'status' => 'sometimes|in:active,inactive',
            ];

            // Only validate uniqueness for mobile if it's provided and not empty
            $mobileValue = $request->input('mobile');
            if (!empty($mobileValue) && trim($mobileValue) !== '') {
                $rules['mobile'] = ['nullable', 'string', 'max:20', Rule::unique('vendors', 'mobile')->ignore($id)->whereNull('deleted_at')];
            } else {
                $rules['mobile'] = 'nullable|string|max:20';
            }

            // Only validate uniqueness for email if it's provided and not empty
            $emailValue = $request->input('email');
            if (!empty($emailValue) && trim($emailValue) !== '') {
                $rules['email'] = ['nullable', 'email', 'max:255', Rule::unique('vendors', 'email')->ignore($id)->whereNull('deleted_at')];
            } else {
                $rules['email'] = 'nullable|email|max:255';
            }

            $validator = Validator::make($request->all(), $rules);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation errors',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Get all request data for fillable fields
            $data = $request->only(['vendor_name', 'contact_person', 'mobile', 'telephone', 'email', 'address', 'gst_no', 'status']);
            
            // Convert empty strings to null for nullable fields
            $nullableFields = ['contact_person', 'mobile', 'telephone', 'email', 'address', 'gst_no'];
            foreach ($nullableFields as $field) {
                if (isset($data[$field]) && $data[$field] === '') {
                    $data[$field] = null;
                }
            }

            // Handle logo upload
            if ($request->hasFile('logo')) {
                // Delete old logo if exists
                if ($vendor->logo && file_exists(public_path($vendor->logo))) {
                    @unlink(public_path($vendor->logo));
                }
                
                $logo = $request->file('logo');
                $logoName = time() . '_' . $logo->getClientOriginalName();
                
                // Create folder structure in public: files/vendors/logos/
                $folderPath = public_path('files/vendors/logos');
                if (!file_exists($folderPath)) {
                    mkdir($folderPath, 0755, true);
                }
                
                $logo->move($folderPath, $logoName);
                $data['logo'] = "files/vendors/logos/{$logoName}";
            }

            // Update the vendor
            $vendor->update($data);
            
            // Refresh the vendor to get the latest data
            $vendor->refresh();

            return response()->json([
                'success' => true,
                'message' => 'Vendor updated successfully',
                'data' => [
                    'vendor' => $vendor
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error updating vendor',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified vendor
     */
    public function destroy($id): JsonResponse
    {
        try {
            // SoftDeletes will automatically filter out deleted records
            $vendor = Vendor::find($id);
            
            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor not found'
                ], 404);
            }

            // Use soft delete
            $vendor->delete();

            return response()->json([
                'success' => true,
                'message' => 'Vendor deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error deleting vendor',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get vendor statistics
     */
    public function stats($id): JsonResponse
    {
        try {
            // SoftDeletes will automatically filter out deleted records
            $vendor = Vendor::find($id);
            
            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor not found'
                ], 404);
            }

            $stats = [
                'vendor_id' => $vendor->id,
                'vendor_name' => $vendor->vendor_name,
                'status' => $vendor->status,
                'created_at' => $vendor->created_at,
                'updated_at' => $vendor->updated_at,
            ];

            return response()->json([
                'success' => true,
                'message' => 'Vendor statistics retrieved successfully',
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving vendor statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get products assigned to a vendor
     */
    public function getAssignedProducts($id): JsonResponse
    {
        try {
            $vendor = Vendor::with(['vendorProducts.product'])->find($id);
            
            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor not found'
                ], 404);
            }

            $assignedProducts = $vendor->vendorProducts()
                ->with('product')
                ->where('status', 'active')
                ->get()
                ->map(function ($vp) {
                    return [
                        'id' => $vp->id,
                        'product_id' => $vp->product_id,
                        'product' => $vp->product,
                        'status' => $vp->status,
                        'created_at' => $vp->created_at,
                        'updated_at' => $vp->updated_at
                    ];
                });

            return response()->json([
                'success' => true,
                'message' => 'Assigned products retrieved successfully',
                'data' => $assignedProducts
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving assigned products',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Assign products to a vendor
     */
    public function assignProducts(Request $request, $id): JsonResponse
    {
        try {
            $vendor = Vendor::find($id);
            
            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor not found'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'product_ids' => 'required|array|min:1',
                'product_ids.*' => 'required|exists:products,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation errors',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = Auth::user();
            if (!$user || !$user->employee_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'User account is not linked to an employee. Please contact administrator.'
                ], 403);
            }

            DB::transaction(function () use ($vendor, $request, $user) {
                $productIds = $request->product_ids;
                $employeeId = $user->employee_id;

                // Remove existing assignments that are not in the new list
                VendorProduct::where('vendor_id', $vendor->id)
                    ->whereNotIn('product_id', $productIds)
                    ->update([
                        'status' => 'inactive',
                        'updated_by' => $employeeId
                    ]);

                // Add or reactivate products
                foreach ($productIds as $productId) {
                    VendorProduct::updateOrCreate(
                        [
                            'vendor_id' => $vendor->id,
                            'product_id' => $productId
                        ],
                        [
                            'status' => 'active',
                            'created_by' => $employeeId,
                            'updated_by' => $employeeId
                        ]
                    );
                }
            });

            return response()->json([
                'success' => true,
                'message' => 'Products assigned successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error assigning products',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove product assignment from a vendor
     */
    public function removeProduct($vendorId, $productId): JsonResponse
    {
        try {
            $user = Auth::user();
            if (!$user || !$user->employee_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'User account is not linked to an employee. Please contact administrator.'
                ], 403);
            }

            $vendorProduct = VendorProduct::where('vendor_id', $vendorId)
                ->where('product_id', $productId)
                ->first();
            
            if (!$vendorProduct) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product assignment not found'
                ], 404);
            }

            $vendorProduct->update([
                'status' => 'inactive',
                'updated_by' => $user->employee_id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Product assignment removed successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error removing product assignment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export vendors to Excel (CSV format)
     */
    public function exportExcel(Request $request): StreamedResponse
    {
        try {
            $query = Vendor::whereNull('deleted_at');

            // Apply filters if provided
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('vendor_name', 'like', "%{$search}%")
                      ->orWhere('contact_person', 'like', "%{$search}%")
                      ->orWhere('mobile', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            }

            if ($request->has('status') && $request->status) {
                $query->where('status', $request->status);
            }

            $vendors = $query->orderBy('vendor_name', 'asc')->get();

            $filename = 'vendors_report_' . date('Y-m-d_His') . '.csv';

            $headers = [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Cache-Control' => 'max-age=0',
            ];

            $callback = function () use ($vendors) {
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
                $headers = ['SI No', 'Vendor Name', 'Contact Person', 'Mobile', 'Telephone', 'Email', 'Address', 'GST No', 'Status'];
                fputcsv($output, array_map($escapeCsv, $headers));

                // Data rows
                $sno = 1;
                foreach ($vendors as $vendor) {
                    fputcsv($output, array_map($escapeCsv, [
                        $sno++,
                        $vendor->vendor_name ?? '',
                        $vendor->contact_person ?? '',
                        $vendor->mobile ?? '',
                        $vendor->telephone ?? '',
                        $vendor->email ?? '',
                        $vendor->address ?? '',
                        $vendor->gst_no ?? '',
                        ucfirst($vendor->status ?? 'active')
                    ]));
                }

                fclose($output);
            };

            return response()->stream($callback, 200, $headers);

        } catch (\Exception $e) {
            Log::error('Error exporting vendors to Excel: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to export vendors',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export vendors to PDF (HTML format for printing)
     */
    public function exportPdf(Request $request): Response
    {
        try {
            $query = Vendor::whereNull('deleted_at');

            // Apply filters if provided
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('vendor_name', 'like', "%{$search}%")
                      ->orWhere('contact_person', 'like', "%{$search}%")
                      ->orWhere('mobile', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            }

            if ($request->has('status') && $request->status) {
                $query->where('status', $request->status);
            }

            $vendors = $query->orderBy('vendor_name', 'asc')->get();

            // Generate HTML content for PDF
            $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>EBMS - Vendors Report</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 10px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { border: 1px solid #ddd; padding: 6px; text-align: left; }
        th { background-color: #dc3545; color: white; font-weight: bold; }
        .report-title { text-align: center; font-size: 18px; font-weight: bold; margin-bottom: 5px; }
        .report-subtitle { text-align: center; font-size: 14px; color: #6c757d; margin-bottom: 10px; }
        .report-meta { text-align: center; font-size: 9px; color: #6c757d; margin-bottom: 15px; }
    </style>
</head>
<body>
    <div class="report-title">EBMS</div>
    <div class="report-subtitle">Vendors Report</div>
    <div class="report-meta">Generated on: ' . date('d/m/Y H:i:s') . '</div>
    <table>
        <thead>
            <tr>
                <th>SI No</th>
                <th>Vendor Name</th>
                <th>Contact Person</th>
                <th>Mobile</th>
                <th>Telephone</th>
                <th>Email</th>
                <th>Address</th>
                <th>GST No</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>';

            $sno = 1;
            foreach ($vendors as $vendor) {
                $html .= '<tr>
                    <td>' . $sno++ . '</td>
                    <td>' . htmlspecialchars($vendor->vendor_name ?? '') . '</td>
                    <td>' . htmlspecialchars($vendor->contact_person ?? '') . '</td>
                    <td>' . htmlspecialchars($vendor->mobile ?? '') . '</td>
                    <td>' . htmlspecialchars($vendor->telephone ?? '') . '</td>
                    <td>' . htmlspecialchars($vendor->email ?? '') . '</td>
                    <td>' . htmlspecialchars($vendor->address ?? '') . '</td>
                    <td>' . htmlspecialchars($vendor->gst_no ?? '') . '</td>
                    <td>' . htmlspecialchars(ucfirst($vendor->status ?? 'active')) . '</td>
                </tr>';
            }

            $html .= '</tbody>
    </table>
</body>
</html>';

            return response($html, 200)
                ->header('Content-Type', 'text/html; charset=UTF-8');

        } catch (\Exception $e) {
            Log::error('Error exporting vendors to PDF: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to export vendors',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
