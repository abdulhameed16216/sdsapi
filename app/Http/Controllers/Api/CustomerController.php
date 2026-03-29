<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerProduct;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    /**
     * Display a listing of customers
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Customer::with(['creator', 'updater', 'customerGroup:id,name'])
                ->withCount('floors');

            // Search functionality
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%")
                      ->orWhere('company_name', 'like', "%{$search}%")
                      ->orWhere('contact_person', 'like', "%{$search}%")
                      ->orWhereHas('customerGroup', function ($gq) use ($search) {
                          $gq->where('name', 'like', "%{$search}%");
                      });
                });
            }

            // Filter by status
            if ($request->has('status') && $request->status) {
                $query->where('status', $request->status);
            }

            // Filter by customer type
            if ($request->has('customer_type') && $request->customer_type) {
                $query->where('customer_type', $request->customer_type);
            }

            // Check if all data is requested (no pagination)
            if ($request->has('all') && $request->get('all') === 'true') {
                $customers = $query->orderBy('created_at', 'desc')->get();
                return response()->json([
                    'success' => true,
                    'message' => 'Customers retrieved successfully',
                    'data' => $customers
                ]);
            }

            // Pagination
            $perPage = $request->get('per_page', 15);
            $customers = $query->orderBy('created_at', 'desc')->paginate($perPage);

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
     * Store a newly created customer
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'customer_group_id' => 'nullable|integer|exists:customer_groups,id',
            'email' => 'nullable|email|max:255|unique:customers,email',
            'phone' => 'nullable|string|max:20|unique:customers,phone',
            'mobile_number' => 'nullable|string|regex:/^[0-9]{10,15}$/',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'company_name' => 'nullable|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'customer_type' => 'nullable|in:individual,business,organization',
            'nature_of_account' => 'nullable|in:litre,cuppage,consumables',
            'status' => 'nullable|in:active,inactive,suspended',
            'notes' => 'nullable|string',
            'agreement_start_date' => 'nullable|date',
            'agreement_end_date' => 'nullable|date|after_or_equal:agreement_start_date',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'documents.*' => 'nullable|file|mimes:pdf,jpeg,png,jpg,doc,docx|max:10240'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $customerData = $request->except(['logo', 'documents']);
            // Use auth()->id() to get the user's ID from users table (foreign key references users.id)
            $userId = auth()->id();
            $customerData['created_by'] = $userId;
            $customerData['updated_by'] = $userId;

            $customer = Customer::create($customerData);
            
            // Handle logo upload after customer creation
            if ($request->hasFile('logo')) {
                $logo = $request->file('logo');
                $logoPath = $this->storeCustomerLogo($logo, $customer->id);
                $customer->update(['logo' => $logoPath]);
            }
            
            // Handle document uploads
            $documentPaths = [];
            if ($request->hasFile('documents')) {
                $documents = $request->file('documents');
                foreach ($documents as $document) {
                    $docPath = $this->storeCustomerDocument($document, $customer->id);
                    $documentPaths[] = $docPath;
                }
                if (!empty($documentPaths)) {
                    $customer->update(['documents' => $documentPaths]);
                }
            }
            
            $customer->load(['creator', 'updater']);

            return response()->json([
                'success' => true,
                'message' => 'Customer created successfully',
                'data' => $customer
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error creating customer: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create customer',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified customer
     */
    public function show(Customer $customer): JsonResponse
    {
        try {
            $customer->load(['creator', 'updater']);

            return response()->json([
                'success' => true,
                'message' => 'Customer retrieved successfully',
                'data' => $customer
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching customer: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve customer',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified customer
     */
    public function update(Request $request, Customer $customer): JsonResponse
    {
        if ($request->has('customer_group_id') && $request->input('customer_group_id') === '') {
            $request->merge(['customer_group_id' => null]);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'email' => 'nullable|email|max:255|unique:customers,email,' . $customer->id,
            'phone' => 'nullable|string|max:20|unique:customers,phone,' . $customer->id,
            'mobile_number' => 'nullable|string|regex:/^[0-9]{10,15}$/',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'company_name' => 'nullable|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'customer_type' => 'nullable|in:individual,business,organization',
            'nature_of_account' => 'nullable|in:litre,cuppage,consumables',
            'status' => 'nullable|in:active,inactive,suspended',
            'notes' => 'nullable|string',
            'agreement_start_date' => 'nullable|date',
            'agreement_end_date' => 'nullable|date|after_or_equal:agreement_start_date',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'remove_logo' => 'nullable|in:true,false,1,0,"true","false"',
            'documents.*' => 'nullable|file|mimes:pdf,jpeg,png,jpg,doc,docx|max:10240',
            'customer_group_id' => 'nullable|integer|exists:customer_groups,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $customerData = $request->except(['logo', 'remove_logo', 'documents']);
            // Use auth()->id() to get the user's ID from users table (foreign key references users.id)
            $customerData['updated_by'] = auth()->id();

            // Log the received data for debugging
            Log::info('Customer update data:', [
                'customer_id' => $customer->id,
                'request_data' => $request->all(),
                'customer_data' => $customerData,
                'has_logo_file' => $request->hasFile('logo'),
                'remove_logo' => $request->get('remove_logo')
            ]);

            // Handle logo removal
            $removeLogo = $request->get('remove_logo');
            $shouldRemoveLogo = $removeLogo === true || $removeLogo === 'true' || $removeLogo === '1' || $removeLogo === 1;
            
            if ($request->has('remove_logo') && $shouldRemoveLogo && $customer->logo) {
                // Delete the existing logo file
                $logoPath = public_path($customer->logo);
                if (file_exists($logoPath)) {
                    unlink($logoPath);
                }
                $customerData['logo'] = null;
                Log::info('Logo removed for customer: ' . $customer->id);
            }

            // Handle new logo upload
            if ($request->hasFile('logo')) {
                // Delete old logo if exists
                if ($customer->logo) {
                    $oldLogoPath = public_path($customer->logo);
                    if (file_exists($oldLogoPath)) {
                        unlink($oldLogoPath);
                    }
                }

                $logo = $request->file('logo');
                $logoPath = $this->storeCustomerLogo($logo, $customer->id);
                $customerData['logo'] = $logoPath;
                Log::info('Logo uploaded for customer: ' . $customer->id . ', path: ' . $logoPath);
            }

            // Handle document uploads
            if ($request->hasFile('documents')) {
                $existingDocuments = $customer->documents ?? [];
                $newDocumentPaths = [];
                
                $documents = $request->file('documents');
                foreach ($documents as $document) {
                    $docPath = $this->storeCustomerDocument($document, $customer->id);
                    $newDocumentPaths[] = $docPath;
                }
                
                // Merge existing documents with new ones
                $allDocuments = array_merge($existingDocuments, $newDocumentPaths);
                $customerData['documents'] = $allDocuments;
            }

            $customer->update($customerData);
            $customer->load(['creator', 'updater']);

            return response()->json([
                'success' => true,
                'message' => 'Customer updated successfully',
                'data' => $customer
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating customer: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update customer',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified customer (soft delete)
     */
    public function destroy(Customer $customer): JsonResponse
    {
        try {
            $customer->delete();

            return response()->json([
                'success' => true,
                'message' => 'Customer deleted successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Error deleting customer: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete customer',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Restore a soft-deleted customer
     */
    public function restore($id): JsonResponse
    {
        try {
            $customer = Customer::withTrashed()->findOrFail($id);
            
            if (!$customer->trashed()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Customer is not deleted'
                ], 400);
            }

            $customer->restore();
            $customer->load(['creator', 'updater']);

            return response()->json([
                'success' => true,
                'message' => 'Customer restored successfully',
                'data' => $customer
            ]);

        } catch (\Exception $e) {
            Log::error('Error restoring customer: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to restore customer',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Permanently delete a customer
     */
    public function forceDelete($id): JsonResponse
    {
        try {
            $customer = Customer::withTrashed()->findOrFail($id);
            
            if (!$customer->trashed()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Customer is not deleted'
                ], 400);
            }

            $customer->forceDelete();

            return response()->json([
                'success' => true,
                'message' => 'Customer permanently deleted'
            ]);

        } catch (\Exception $e) {
            Log::error('Error force deleting customer: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to permanently delete customer',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store customer logo file in public/files/ folder
     */
    private function storeCustomerLogo($file, $customerId)
    {
        // Create folder structure in public: files/customers/cus_{id}/
        $folderPath = public_path("files/customers/cus_{$customerId}");
        
        // Create directory if it doesn't exist
        if (!file_exists($folderPath)) {
            mkdir($folderPath, 0755, true);
        }
        
        // Get original filename and extension
        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $extension = $file->getClientOriginalExtension();
        
        // Create timestamped filename using Indian timezone: filename_dd_mm_yyyy_hh_mm_ss.ext
        $timestamp = now()->setTimezone('Asia/Kolkata')->format('d_m_Y_H_i_s');
        $newFilename = "{$originalName}_{$timestamp}.{$extension}";
        
        // Move file to public folder
        $file->move($folderPath, $newFilename);
        
        // Return relative path from public folder
        return "files/customers/cus_{$customerId}/{$newFilename}";
    }

    /**
     * Store customer document file in public/files folder
     */
    private function storeCustomerDocument($file, $customerId)
    {
        // Create folder structure in public: files/customers/cus_{id}/documents/
        $folderPath = public_path("files/customers/cus_{$customerId}/documents");
        
        // Create directory if it doesn't exist
        if (!file_exists($folderPath)) {
            mkdir($folderPath, 0755, true);
        }
        
        // Get original filename and extension
        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $extension = $file->getClientOriginalExtension();
        
        // Create timestamped filename using Indian timezone: filename_dd_mm_yyyy_hh_mm_ss.ext
        $timestamp = now()->setTimezone('Asia/Kolkata')->format('d_m_Y_H_i_s');
        $newFilename = "{$originalName}_{$timestamp}.{$extension}";
        
        // Move file to public folder
        $file->move($folderPath, $newFilename);
        
        // Return relative path from public folder
        return "files/customers/cus_{$customerId}/documents/{$newFilename}";
    }

    /**
     * Get customer logo URL
     */
    public function getLogoUrl(Customer $customer): JsonResponse
    {
        try {
            if (!$customer->logo) {
                return response()->json([
                    'success' => false,
                    'message' => 'No logo found for this customer'
                ], 404);
            }

            // Handle old paths (with iupload) and new paths (without iupload)
            $logoPath = $customer->logo;
            
            // If path contains 'iupload', remove it (migration from old to new structure)
            if (strpos($logoPath, 'files/iupload/') !== false) {
                $logoPath = str_replace('files/iupload/', 'files/', $logoPath);
            }
            
            // Also handle old paths that might be in storage or customers/ directly
            if (strpos($logoPath, 'storage/') === 0) {
                $logoPath = str_replace('storage/', 'files/', $logoPath);
            }
            if (strpos($logoPath, 'customers/') === 0 && strpos($logoPath, 'files/') === false) {
                $logoPath = 'files/' . $logoPath;
            }
            
            // Check if file exists
            $filePath = public_path($logoPath);
            if (!file_exists($filePath)) {
                // Try with iupload path (for backward compatibility)
                $oldPath = str_replace('files/', 'files/iupload/', $logoPath);
                $oldFilePath = public_path($oldPath);
                if (file_exists($oldFilePath)) {
                    $logoPath = $oldPath;
                } else {
                    return response()->json([
                        'success' => false,
                        'message' => 'Logo file not found',
                        'debug' => [
                            'logo_path' => $customer->logo,
                            'checked_path' => $logoPath,
                            'file_exists' => false
                        ]
                    ], 404);
                }
            }

            // Generate URL with public path
            $baseUrl = url('/');
            $logoUrl = $baseUrl . '/' . $logoPath;

            return response()->json([
                'success' => true,
                'message' => 'Logo URL retrieved successfully',
                'data' => [
                    'logo_url' => $logoUrl,
                    'logo_path' => $logoPath
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting logo URL: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve logo URL',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get customer statistics
     */
    public function stats(): JsonResponse
    {
        try {
            $stats = [
                'total' => Customer::count(),
                'active' => Customer::where('status', 'active')->count(),
                'inactive' => Customer::where('status', 'inactive')->count(),
                'suspended' => Customer::where('status', 'suspended')->count(),
                'by_type' => Customer::selectRaw('customer_type, COUNT(*) as count')
                    ->groupBy('customer_type')
                    ->pluck('count', 'customer_type')
                    ->toArray()
            ];

            return response()->json([
                'success' => true,
                'message' => 'Customer statistics retrieved successfully',
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching customer stats: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve customer statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get products assigned to a customer
     */
    public function getAssignedProducts($id): JsonResponse
    {
        try {
            $customer = Customer::with(['customerProducts.product'])->find($id);
            
            if (!$customer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Customer not found'
                ], 404);
            }

            $assignedProducts = $customer->customerProducts()
                ->with('product')
                ->where('status', 'active')
                ->get()
                ->map(function ($cp) {
                    return [
                        'id' => $cp->id,
                        'product_id' => $cp->product_id,
                        'product' => $cp->product,
                        'status' => $cp->status,
                        'created_at' => $cp->created_at,
                        'updated_at' => $cp->updated_at
                    ];
                });

            return response()->json([
                'success' => true,
                'message' => 'Assigned products retrieved successfully',
                'data' => $assignedProducts
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching assigned products: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving assigned products',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Assign products to a customer
     */
    public function assignProducts(Request $request, $id): JsonResponse
    {
        try {
            $customer = Customer::find($id);
            
            if (!$customer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Customer not found'
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

            DB::transaction(function () use ($customer, $request, $user) {
                $productIds = $request->product_ids;
                $employeeId = $user->employee_id;

                // Remove existing assignments that are not in the new list
                CustomerProduct::where('customer_id', $customer->id)
                    ->whereNotIn('product_id', $productIds)
                    ->update([
                        'status' => 'inactive',
                        'updated_by' => $employeeId
                    ]);

                // Add or reactivate products
                foreach ($productIds as $productId) {
                    CustomerProduct::updateOrCreate(
                        [
                            'customer_id' => $customer->id,
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
            Log::error('Error assigning products: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error assigning products',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove product assignment from a customer
     */
    public function removeProduct($customerId, $productId): JsonResponse
    {
        try {
            $user = Auth::user();
            if (!$user || !$user->employee_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'User account is not linked to an employee. Please contact administrator.'
                ], 403);
            }

            $customerProduct = CustomerProduct::where('customer_id', $customerId)
                ->where('product_id', $productId)
                ->first();
            
            if (!$customerProduct) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product assignment not found'
                ], 404);
            }

            $customerProduct->update([
                'status' => 'inactive',
                'updated_by' => $user->employee_id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Product assignment removed successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error removing product assignment: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error removing product assignment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export customers to Excel (CSV format)
     */
    public function exportExcel(Request $request): StreamedResponse
    {
        try {
            $query = Customer::whereNull('deleted_at');

            // Apply filters if provided
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('mobile_number', 'like', "%{$search}%")
                      ->orWhere('company_name', 'like', "%{$search}%");
                });
            }

            if ($request->has('status') && $request->status) {
                $query->where('status', $request->status);
            }

            if ($request->has('customer_type') && $request->customer_type) {
                $query->where('customer_type', $request->customer_type);
            }

            $customers = $query->orderBy('name', 'asc')->get();

            $filename = 'customers_report_' . date('Y-m-d_His') . '.csv';

            $headers = [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Cache-Control' => 'max-age=0',
            ];

            $callback = function () use ($customers) {
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
                $headers = ['SI No', 'Name', 'Company Name', 'Contact Person', 'Mobile Number', 'Email', 'Phone', 'City', 'State', 'Customer Type', 'Status'];
                fputcsv($output, array_map($escapeCsv, $headers));

                // Data rows
                $sno = 1;
                foreach ($customers as $customer) {
                    fputcsv($output, array_map($escapeCsv, [
                        $sno++,
                        $customer->name ?? '',
                        $customer->company_name ?? '',
                        $customer->contact_person ?? '',
                        $customer->mobile_number ?? '',
                        $customer->email ?? '',
                        $customer->phone ?? '',
                        $customer->city ?? '',
                        $customer->state ?? '',
                        ucfirst($customer->customer_type ?? 'individual'),
                        ucfirst($customer->status ?? 'active')
                    ]));
                }

                fclose($output);
            };

            return response()->stream($callback, 200, $headers);

        } catch (\Exception $e) {
            Log::error('Error exporting customers to Excel: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to export customers',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export customers to PDF (HTML format for printing)
     */
    public function exportPdf(Request $request): Response
    {
        try {
            $query = Customer::whereNull('deleted_at');

            // Apply filters if provided
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('mobile_number', 'like', "%{$search}%")
                      ->orWhere('company_name', 'like', "%{$search}%");
                });
            }

            if ($request->has('status') && $request->status) {
                $query->where('status', $request->status);
            }

            if ($request->has('customer_type') && $request->customer_type) {
                $query->where('customer_type', $request->customer_type);
            }

            $customers = $query->orderBy('name', 'asc')->get();

            // Generate HTML content for PDF
            $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>EBMS - Customers Report</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 9px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { border: 1px solid #ddd; padding: 5px; text-align: left; }
        th { background-color: #dc3545; color: white; font-weight: bold; }
        .report-title { text-align: center; font-size: 18px; font-weight: bold; margin-bottom: 5px; }
        .report-subtitle { text-align: center; font-size: 14px; color: #6c757d; margin-bottom: 10px; }
        .report-meta { text-align: center; font-size: 9px; color: #6c757d; margin-bottom: 15px; }
    </style>
</head>
<body>
    <div class="report-title">EBMS</div>
    <div class="report-subtitle">Customers Report</div>
    <div class="report-meta">Generated on: ' . date('d/m/Y H:i:s') . '</div>
    <table>
        <thead>
            <tr>
                <th>SI No</th>
                <th>Name</th>
                <th>Company Name</th>
                <th>Contact Person</th>
                <th>Mobile</th>
                <th>Email</th>
                <th>City</th>
                <th>Type</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>';

            $sno = 1;
            foreach ($customers as $customer) {
                $html .= '<tr>
                    <td>' . $sno++ . '</td>
                    <td>' . htmlspecialchars($customer->name ?? '') . '</td>
                    <td>' . htmlspecialchars($customer->company_name ?? '') . '</td>
                    <td>' . htmlspecialchars($customer->contact_person ?? '') . '</td>
                    <td>' . htmlspecialchars($customer->mobile_number ?? '') . '</td>
                    <td>' . htmlspecialchars($customer->email ?? '') . '</td>
                    <td>' . htmlspecialchars($customer->city ?? '') . '</td>
                    <td>' . htmlspecialchars(ucfirst($customer->customer_type ?? 'individual')) . '</td>
                    <td>' . htmlspecialchars(ucfirst($customer->status ?? 'active')) . '</td>
                </tr>';
            }

            $html .= '</tbody>
    </table>
</body>
</html>';

            return response($html, 200)
                ->header('Content-Type', 'text/html; charset=UTF-8');

        } catch (\Exception $e) {
            Log::error('Error exporting customers to PDF: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to export customers',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
