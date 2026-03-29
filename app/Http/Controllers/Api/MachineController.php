<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Machine;
use App\Models\CustomerMachine;
use App\Models\EmployeeCustomerMachineAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\QueryException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MachineController extends Controller
{
    /**
     * Display a listing of machines
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Machine::with(['creator', 'updater']);

            // Search functionality
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('machine_alias', 'like', "%{$search}%")
                      ->orWhere('machine_model', 'like', "%{$search}%")
                      ->orWhere('serial_number', 'like', "%{$search}%")
                      ->orWhere('capacity', 'like', "%{$search}%")
                      ->orWhere('ancillary_machine_no', 'like', "%{$search}%")
                      ->orWhere('machine_type', 'like', "%{$search}%");
                });
            }

            // Filter by status
            if ($request->has('status') && $request->status) {
                $query->where('status', $request->status);
            }

            // Filter by machine model
            if ($request->has('machine_model') && $request->machine_model) {
                $query->where('machine_model', $request->machine_model);
            }

            // Check if all data is requested (no pagination)
            if ($request->has('all') && $request->get('all') === 'true') {
                $machines = $query->orderBy('created_at', 'desc')->get();
                return response()->json([
                    'success' => true,
                    'message' => 'Machines retrieved successfully',
                    'data' => $machines
                ]);
            }

            // Pagination
            $perPage = $request->get('per_page', 15);
            $machines = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'Machines retrieved successfully',
                'data' => $machines
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching machines: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve machines',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Machines with no active customer_machines assignment (for assign-to-branch UI).
     * Matches MachineAssignmentController store conflict rules.
     */
    public function unassignedForCustomerAssignment(Request $request): JsonResponse
    {
        try {
            $query = Machine::with(['creator', 'updater'])
                ->whereNotIn('id', CustomerMachine::query()
                    ->where('status', 'active')
                    ->whereNull('deleted_at')
                    ->distinct()
                    ->select('machine_id'));

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('machine_alias', 'like', "%{$search}%")
                        ->orWhere('machine_model', 'like', "%{$search}%")
                        ->orWhere('serial_number', 'like', "%{$search}%")
                        ->orWhere('capacity', 'like', "%{$search}%")
                        ->orWhere('ancillary_machine_no', 'like', "%{$search}%")
                        ->orWhere('machine_type', 'like', "%{$search}%");
                });
            }

            if ($request->has('all') && $request->get('all') === 'true') {
                $machines = $query->orderBy('created_at', 'desc')->get();
                return response()->json([
                    'success' => true,
                    'message' => 'Unassigned machines retrieved successfully',
                    'data' => $machines,
                ]);
            }

            $perPage = (int) $request->get('per_page', 500);
            $perPage = $perPage > 0 ? min($perPage, 1000) : 500;
            $machines = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'Unassigned machines retrieved successfully',
                'data' => $machines,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching unassigned machines: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve unassigned machines',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Store a newly created machine
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'machine_alias' => 'required|string|max:255',
            'serial_number' => [
            'required',
            'string',
            'max:255',
            Rule::unique('machines', 'serial_number')->whereNull('deleted_at'),
        ],
            'capacity' => 'nullable|string|max:255',
            'machine_model' => 'nullable|string|max:255',
            'ancillary_machine_no' => 'nullable|string|max:255',
            'machine_type' => 'nullable|string|max:255|in:automatic_live,automatic_bean_to_cup,semi_automatic_or_manual',
            'machine_image' => 'nullable|image|mimes:jpeg,png,jpg|max:5120', // 5MB max
            'status' => 'nullable|in:active,inactive,maintenance',
            'notes' => 'nullable|string'
        ], [
            'serial_number.required' => 'The Machine number field is required.',
            'serial_number.unique' => 'The Machine number has already been taken.',
        ], [
            'attributes' => ['serial_number' => 'Machine number']
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        // Check for existing machine with same serial (including soft-deleted) to return clear messages
        $existing = Machine::withTrashed()->where('serial_number', $request->serial_number)->first();
        if ($existing) {
            if ($existing->trashed()) {
                return response()->json([
                    'success' => false,
                    'message' => 'A machine with this number was previously deleted. Restore it from Deleted Records (Force delete / Restore) or contact your administrator.',
                    'errors' => [
                        'serial_number' => [
                            'A machine with this number was previously deleted. Restore it from Deleted Records (Force delete / Restore) or contact your administrator.'
                        ]
                    ]
                ], 422);
            }
            return response()->json([
                'success' => false,
                'message' => 'The Machine number has already been taken.',
                'errors' => [
                    'serial_number' => ['The Machine number has already been taken.']
                ]
            ], 422);
        }

        try {
            $user = auth()->user();
            if (!$user || !$user->employee_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'User account is not linked to an employee. Please contact administrator.'
                ], 403);
            }

            $machineData = $request->except(['machine_image']);
            $machineData['created_by'] = $user->employee_id;
            $machineData['updated_by'] = $user->employee_id;
            // DB columns may be NOT NULL while API allows omission; avoid SQL 1048 on null inserts
            foreach (['machine_model', 'ancillary_machine_no', 'notes'] as $field) {
                if (!array_key_exists($field, $machineData) || $machineData[$field] === null) {
                    $machineData[$field] = '';
                }
            }

            $machine = Machine::create($machineData);
            
            // Handle machine image upload after machine creation
            if ($request->hasFile('machine_image')) {
                $image = $request->file('machine_image');
                $imagePath = $this->storeMachineImage($image, $machine->id);
                $machine->update(['machine_image' => $imagePath]);
            }
            
            $machine->load(['creator', 'updater']);

            return response()->json([
                'success' => true,
                'message' => 'Machine created successfully',
                'data' => $machine
            ], 201);

        } catch (QueryException $e) {
            if (($e->getCode() === '23000' || (int) $e->getCode() === 23000) && strpos($e->getMessage(), 'machines_serial_number_unique') !== false) {
                $existing = Machine::withTrashed()->where('serial_number', $request->serial_number)->first();
                $message = ($existing && $existing->trashed())
                    ? 'A machine with this number was previously deleted. Restore it from Deleted Records (Backup & Restore) or contact your administrator.'
                    : 'The Machine number has already been taken.';
                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'errors' => ['serial_number' => [$message]]
                ], 422);
            }
            Log::error('Error creating machine: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create machine',
                'error' => $e->getMessage()
            ], 500);
        } catch (\Exception $e) {
            Log::error('Error creating machine: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create machine',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified machine
     */
    public function show(Machine $machine): JsonResponse
    {
        try {
            $machine->load(['creator', 'updater']);

            return response()->json([
                'success' => true,
                'message' => 'Machine retrieved successfully',
                'data' => $machine
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching machine: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve machine',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified machine
     */
    public function update(Request $request, Machine $machine): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'machine_alias' => 'sometimes|required|string|max:255',
            'serial_number' => 'sometimes|required|string|max:255|unique:machines,serial_number,' . $machine->id,
            'capacity' => 'nullable|string|max:255',
            'machine_model' => 'nullable|string|max:255',
            'ancillary_machine_no' => 'nullable|string|max:255',
            'machine_type' => 'nullable|string|max:255|in:automatic_live,automatic_bean_to_cup,semi_automatic_or_manual',
            'machine_image' => 'nullable|image|mimes:jpeg,png,jpg|max:5120',
            'status' => 'nullable|in:active,inactive,maintenance',
            'notes' => 'nullable|string',
            'remove_image' => 'nullable|in:true,false,1,0,"true","false"'
        ], [
            'serial_number.required' => 'The Machine number field is required.',
            'serial_number.unique' => 'The Machine number has already been taken.',
        ], [
            'attributes' => ['serial_number' => 'Machine number']
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = auth()->user();
            if (!$user || !$user->employee_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'User account is not linked to an employee. Please contact administrator.'
                ], 403);
            }

            $machineData = $request->except(['machine_image', 'remove_image']);
            $machineData['updated_by'] = $user->employee_id;
            foreach (['machine_model', 'ancillary_machine_no', 'notes'] as $field) {
                if (array_key_exists($field, $machineData) && $machineData[$field] === null) {
                    $machineData[$field] = '';
                }
            }

            // Handle image removal
            $removeImage = $request->get('remove_image');
            $shouldRemoveImage = $removeImage === true || $removeImage === 'true' || $removeImage === '1' || $removeImage === 1;
            
            if ($request->has('remove_image') && $shouldRemoveImage && $machine->machine_image) {
                // Delete the existing image file
                if (Storage::disk('public')->exists($machine->machine_image)) {
                    Storage::disk('public')->delete($machine->machine_image);
                }
                $machineData['machine_image'] = null;
                Log::info('Image removed for machine: ' . $machine->id);
            }

            // Handle new image upload
            if ($request->hasFile('machine_image')) {
                // Delete old image if exists
                if ($machine->machine_image && Storage::disk('public')->exists($machine->machine_image)) {
                    Storage::disk('public')->delete($machine->machine_image);
                }

                $image = $request->file('machine_image');
                $imagePath = $this->storeMachineImage($image, $machine->id);
                $machineData['machine_image'] = $imagePath;
                Log::info('Image uploaded for machine: ' . $machine->id . ', path: ' . $imagePath);
            }

            $machine->update($machineData);
            $machine->load(['creator', 'updater']);

            return response()->json([
                'success' => true,
                'message' => 'Machine updated successfully',
                'data' => $machine
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating machine: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update machine',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified machine from storage
     */
    public function destroy(Machine $machine): JsonResponse
    {
        try {
            // Soft-delete assignment links so they stay in sync; restore machine later will not restore these
            CustomerMachine::where('machine_id', $machine->id)->delete();
            EmployeeCustomerMachineAssignment::where('assigned_machine_id', $machine->id)->delete();

            // Delete machine image if exists
            if ($machine->machine_image && Storage::disk('public')->exists($machine->machine_image)) {
                Storage::disk('public')->delete($machine->machine_image);
            }

            $machine->delete();

            return response()->json([
                'success' => true,
                'message' => 'Machine deleted successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Error deleting machine: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete machine',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get machine image URL
     */
    public function getMachineImageUrl(Machine $machine): JsonResponse
    {
        try {
            if (!$machine->machine_image) {
                return response()->json([
                    'success' => false,
                    'message' => 'No image found for this machine'
                ], 404);
            }

            $baseUrl = url('/');
            $imageUrl = $baseUrl . '/storage/' . $machine->machine_image;

            return response()->json([
                'success' => true,
                'data' => [
                    'image_url' => $imageUrl
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting machine image URL: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to get machine image URL',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store machine image
     */
    private function storeMachineImage($file, $machineId): string
    {
        // Create folder structure in public: files/machines/machine_{id}/
        $folderPath = public_path("files/machines/machine_{$machineId}");
        
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
        return "files/machines/machine_{$machineId}/{$filename}";
    }

    /**
     * Export machines to Excel (CSV format)
     */
    public function exportExcel(Request $request): StreamedResponse
    {
        try {
            $query = Machine::whereNull('deleted_at');

            // Apply filters if provided
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('machine_alias', 'like', "%{$search}%")
                      ->orWhere('machine_model', 'like', "%{$search}%")
                      ->orWhere('serial_number', 'like', "%{$search}%")
                      ->orWhere('capacity', 'like', "%{$search}%");
                });
            }

            if ($request->has('status') && $request->status) {
                $query->where('status', $request->status);
            }

            if ($request->has('machine_model') && $request->machine_model) {
                $query->where('machine_model', $request->machine_model);
            }

            $machines = $query->orderBy('machine_alias', 'asc')->get();

            $filename = 'machines_report_' . date('Y-m-d_H-i-s') . '.csv';

            $headers = [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
                'Pragma' => 'no-cache',
                'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
                'Expires' => '0',
            ];

            $callback = function () use ($machines) {
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
                $headers = ['SI No', 'Machine Alias', 'Machine Model', 'Machine Number', 'Capacity', 'Machine Type', 'Status'];
                fputcsv($output, array_map($escapeCsv, $headers));

                // Data rows
                $sno = 1;
                foreach ($machines as $machine) {
                    fputcsv($output, array_map($escapeCsv, [
                        $sno++,
                        $machine->machine_alias ?? '',
                        $machine->machine_model ?? 'N/A',
                        $machine->serial_number ?? '',
                        $machine->capacity ?? 'N/A',
                        $machine->machine_type ?? 'N/A',
                        ucfirst($machine->status ?? 'active')
                    ]));
                }

                fclose($output);
            };

            return response()->stream($callback, 200, $headers);

        } catch (\Exception $e) {
            Log::error('Error exporting machines to Excel: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to export machines',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export machines to PDF (HTML format for printing)
     */
    public function exportPdf(Request $request): Response
    {
        try {
            $query = Machine::whereNull('deleted_at');

            // Apply filters if provided
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('machine_alias', 'like', "%{$search}%")
                      ->orWhere('machine_model', 'like', "%{$search}%")
                      ->orWhere('serial_number', 'like', "%{$search}%")
                      ->orWhere('capacity', 'like', "%{$search}%");
                });
            }

            if ($request->has('status') && $request->status) {
                $query->where('status', $request->status);
            }

            if ($request->has('machine_model') && $request->machine_model) {
                $query->where('machine_model', $request->machine_model);
            }

            $machines = $query->orderBy('machine_alias', 'asc')->get();

            // Generate HTML content for PDF
            $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>EBMS - Machines Report</title>
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
    <div class="report-subtitle">Machines Report</div>
    <div class="report-meta">Generated on: ' . date('d/m/Y H:i:s') . '</div>
    <table>
        <thead>
            <tr>
                <th>SI No</th>
                <th>Machine Alias</th>
                <th>Machine Model</th>
                <th>Machine Number</th>
                <th>Capacity</th>
                <th>Machine Type</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>';

            $sno = 1;
            foreach ($machines as $machine) {
                $html .= '<tr>
                    <td>' . $sno++ . '</td>
                    <td>' . htmlspecialchars($machine->machine_alias ?? '') . '</td>
                    <td>' . htmlspecialchars($machine->machine_model ?? 'N/A') . '</td>
                    <td>' . htmlspecialchars($machine->serial_number ?? '') . '</td>
                    <td>' . htmlspecialchars($machine->capacity ?? 'N/A') . '</td>
                    <td>' . htmlspecialchars($machine->machine_type ?? 'N/A') . '</td>
                    <td>' . ucfirst($machine->status ?? 'active') . '</td>
                </tr>';
            }

            $html .= '</tbody>
    </table>
</body>
</html>';

            return response($html, 200)
                ->header('Content-Type', 'text/html; charset=UTF-8');

        } catch (\Exception $e) {
            Log::error('Error exporting machines to PDF: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to export machines to PDF',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
