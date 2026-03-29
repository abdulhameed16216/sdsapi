<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Employee::with('role', 'user');

        // Search functionality
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('employee_code', 'like', "%{$search}%")
                  ->orWhere('mobile_number', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Filter by role
        if ($request->has('role_id') && $request->role_id) {
            $query->where('role_id', $request->role_id);
        }

        // Filter by status
        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        // Check if all data is requested (no pagination)
        if ($request->has('all') && $request->get('all') === 'true') {
            $employees = $query->get();
            return response()->json([
                'success' => true,
                'data' => $employees
            ]);
        }

        $employees = $query->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $employees
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        // Validation rules
        $rules = [
            'employeeName' => 'required|string|min:2|max:255',
            'mobileNumber' => 'required|string|regex:/^[0-9]{10,15}$/|unique:employees,mobile_number,NULL,id,deleted_at,NULL',
            'email' => 'nullable|email|unique:employees,email,NULL,id,deleted_at,NULL',
            'dateOfBirth' => 'required|date|before:today',
            'dateOfJoining' => 'required|date|before_or_equal:today',
            'address' => 'required|string|min:10|max:500',
            'city' => 'nullable|string|max:100',
            'employeeImage' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:5120', // 5MB
            'idProof' => 'nullable|array',
            'idProof.*' => 'file|mimes:pdf,jpeg,png,jpg|max:10240', // 10MB per file
            'bloodGroup' => 'nullable|in:A+,A-,B+,B-,AB+,AB-,O+,O-',
            'userRole' => 'nullable|exists:roles,id',
            'username' => 'nullable|string|min:3|max:50|unique:users,username,NULL,id,deleted_at,NULL',
            'password' => 'nullable|string|min:6|max:255',
            'assignedVendors' => 'nullable|array',
            'assignedVendors.*' => 'integer|exists:vendors,id',
            'assignedMachines' => 'nullable|array',
            'assignedMachines.*' => 'integer|exists:machines,id',
        ];

        // Conditional validation for username and password
        // Check if userRole exists in request before accessing it
        $userRoleId = $request->input('userRole');
        $role = $userRoleId ? Role::find($userRoleId) : null;
        if ($role && in_array($role->slug, ['operator', 'supervisor'])) {
            $rules['username'] = 'required|string|min:3|max:50|unique:users,username,NULL,id,deleted_at,NULL';
            $rules['password'] = 'required|string|min:6|max:255';
        }

        $validated = $request->validate($rules);

        try {
            // Use database transaction to ensure data consistency
            return \DB::transaction(function () use ($validated, $request) {
                // Generate employee code
                $employeeCode = $this->generateEmployeeCode();

                // Create employee first to get ID
                $employee = Employee::create([
                    'employee_code' => $employeeCode,
                    'name' => $validated['employeeName'],
                    'mobile_number' => $validated['mobileNumber'],
                    'email' => $validated['email'] ?? null,
                    'date_of_birth' => $validated['dateOfBirth'],
                    'date_of_joining' => $validated['dateOfJoining'],
                    'address' => $validated['address'],
                    'city' => $validated['city'] ?? null,
                    'blood_group' => $validated['bloodGroup'] ?? null,
                    'role_id' => $validated['userRole'] ?? null, // Allow null if role is not provided
                    'status' => 'active',
                ]);

                // Handle file uploads after employee creation
                $employeeImagePath = null;
                $idProofPaths = [];

                if ($request->hasFile('employeeImage')) {
                    $employeeImagePath = $this->storeEmployeeFile($request->file('employeeImage'), $employee->id, 'images');
                }

                if ($request->hasFile('idProof')) {
                    foreach ($request->file('idProof') as $file) {
                        $idProofPaths[] = $this->storeEmployeeFile($file, $employee->id, 'id_proofs');
                    }
                }

                // Update employee with file paths
                if ($employeeImagePath || !empty($idProofPaths)) {
                    $updateData = [];
                    if ($employeeImagePath) $updateData['employee_image'] = $employeeImagePath;
                    if (!empty($idProofPaths)) $updateData['id_proof'] = json_encode($idProofPaths);
                    $employee->update($updateData);
                }

                // Create user account if username and password are provided
                if (!empty($validated['username']) && !empty($validated['password'])) {
                    User::create([
                        'username' => $validated['username'],
                        'password' => Hash::make($validated['password']),
                        'employee_id' => $employee->id,
                        'status' => 'active',
                    ]);
                }

                // Load relationships for response
                $employee->load('role', 'user');

                return response()->json([
                    'success' => true,
                    'message' => 'Employee created successfully',
                    'data' => $employee
                ], 201);
            });

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create employee',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $employee = Employee::with('role', 'user')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $employee
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $employee = Employee::findOrFail($id);

        // Validation rules
        $rules = [
            'employeeName' => 'sometimes|required|string|min:2|max:255',
            'mobileNumber' => 'sometimes|required|string|min:10|max:15|unique:employees,mobile_number,' . $id . ',id,deleted_at,NULL',
            'email' => 'nullable|email|unique:employees,email,' . $id . ',id,deleted_at,NULL',
            'dateOfBirth' => 'sometimes|required|date',
            'dateOfJoining' => 'sometimes|required|date',
            'address' => 'sometimes|required|string|min:5|max:500',
            'city' => 'nullable|string|max:100',
            'employeeImage' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:5120',
            'idProof' => 'nullable|array',
            'idProof.*' => 'nullable|file|mimes:pdf,jpeg,png,jpg|max:10240',
            'bloodGroup' => 'nullable|in:A+,A-,B+,B-,AB+,AB-,O+,O-',
            'userRole' => 'nullable|exists:roles,id', // Make role optional
            'status' => 'sometimes|required|in:active,inactive',
            'deleteProfileImage' => 'nullable|string|in:true,false',
            'deletedIdProofs' => 'nullable|array',
            'deletedIdProofs.*' => 'nullable|string',
            'username' => 'nullable|string|min:3|max:50|unique:users,username,' . ($employee->user ? $employee->user->id : 'NULL') . ',id,deleted_at,NULL',
            'password' => 'nullable|string|min:6|max:255',
        ];

        // Conditional validation for username and password when role is assigned
        $userRoleId = $request->input('userRole');
        $role = $userRoleId ? Role::find($userRoleId) : null;
        if ($role && in_array($role->slug, ['operator', 'supervisor'])) {
            // If employee doesn't have a user account, require username and password
            if (!$employee->user) {
                $rules['username'] = 'required|string|min:3|max:50|unique:users,username,NULL,id,deleted_at,NULL';
                $rules['password'] = 'required|string|min:6|max:255';
            }
        }

        $validated = $request->validate($rules);


        try {
            // Handle profile image deletion
            if ($request->has('deleteProfileImage') && $request->deleteProfileImage === 'true') {
                if ($employee->employee_image) {
                    Storage::disk('public')->delete($employee->employee_image);
                    $validated['employee_image'] = null;
                }
            }

            // Handle new profile image upload
            if ($request->hasFile('employeeImage')) {
                // Delete old image if exists
                if ($employee->employee_image) {
                    Storage::disk('public')->delete($employee->employee_image);
                }
                $validated['employee_image'] = $this->storeEmployeeFile($request->file('employeeImage'), $id, 'images');
            }

            // Handle ID proof deletions
            if ($request->has('deletedIdProofs') && is_array($request->deletedIdProofs)) {
                $currentProofs = $employee->id_proof ? json_decode($employee->id_proof, true) : [];
                
                // Delete specified files from storage
                foreach ($request->deletedIdProofs as $deletedProof) {
                    if ($deletedProof && Storage::disk('public')->exists($deletedProof)) {
                        Storage::disk('public')->delete($deletedProof);
                    }
                    
                    // Remove from current proofs array
                    $currentProofs = array_filter($currentProofs, function($proof) use ($deletedProof) {
                        return $proof !== $deletedProof;
                    });
                }
                
                // Re-index array and update
                $currentProofs = array_values($currentProofs);
                $validated['id_proof'] = json_encode($currentProofs);
            }

            // Handle new ID proof uploads
            if ($request->hasFile('idProof')) {
                $currentProofs = $employee->id_proof ? json_decode($employee->id_proof, true) : [];
                
                // Add new files to existing proofs
                foreach ($request->file('idProof') as $file) {
                    $currentProofs[] = $this->storeEmployeeFile($file, $id, 'id_proofs');
                }
                
                $validated['id_proof'] = json_encode($currentProofs);
            }

            // Map frontend field names to database field names
            $updateData = [];
            if (isset($validated['employeeName'])) $updateData['name'] = $validated['employeeName'];
            if (isset($validated['mobileNumber'])) $updateData['mobile_number'] = $validated['mobileNumber'];
            if (isset($validated['email'])) $updateData['email'] = $validated['email'];
            if (isset($validated['dateOfBirth'])) $updateData['date_of_birth'] = $validated['dateOfBirth'];
            if (isset($validated['dateOfJoining'])) $updateData['date_of_joining'] = $validated['dateOfJoining'];
            if (isset($validated['address'])) $updateData['address'] = $validated['address'];
            if (isset($validated['city'])) $updateData['city'] = $validated['city'];
            if (isset($validated['bloodGroup'])) $updateData['blood_group'] = $validated['bloodGroup'];
            // Handle role_id - allow null to remove role assignment
            if (array_key_exists('userRole', $validated)) {
                $updateData['role_id'] = $validated['userRole'] ?? null;
            }
            if (isset($validated['status'])) $updateData['status'] = $validated['status'];
            if (array_key_exists('employee_image', $validated)) $updateData['employee_image'] = $validated['employee_image'];
            if (array_key_exists('id_proof', $validated)) $updateData['id_proof'] = $validated['id_proof'];


            $employee->update($updateData);

            // Handle user account creation/update
            $userRoleId = $request->input('userRole');
            $hasUsername = $request->filled('username');
            $hasPassword = $request->filled('password');
            
            // Check if role requires user account (operator, supervisor) or if username/password provided
            $role = $userRoleId ? Role::find($userRoleId) : null;
            $requiresUserAccount = $role && in_array($role->slug, ['operator', 'supervisor']);
            
            if ($employee->user) {
                // User exists - update credentials if provided
                if ($hasUsername || $hasPassword) {
                    $userUpdateData = [];
                    if ($hasUsername) $userUpdateData['username'] = $request->username;
                    if ($hasPassword) $userUpdateData['password'] = Hash::make($request->password);
                    
                    $employee->user->update($userUpdateData);
                }
            } else {
                // User doesn't exist - create if username and password are provided
                if ($hasUsername && $hasPassword) {
                    User::create([
                        'username' => $validated['username'],
                        'password' => Hash::make($validated['password']),
                        'employee_id' => $employee->id,
                        'status' => 'active',
                    ]);
                    // Reload employee to get the new user relationship
                    $employee->refresh();
                }
            }

            // Reload relationships to ensure we have the latest data
            $employee->load('role', 'user');

            return response()->json([
                'success' => true,
                'message' => 'Employee updated successfully',
                'data' => $employee
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update employee',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update employee via POST method
     */
    public function updateViaPost(Request $request, string $id)
    {
        return $this->update($request, $id);
    }

    /**
     * Remove the specified resource from storage (Soft Delete).
     */
    public function destroy(string $id)
    {
        try {
            $employee = Employee::findOrFail($id);

            // Soft delete associated user account
            if ($employee->user) {
                $employee->user->delete(); // This will also soft delete if User model has SoftDeletes
            }

            // Soft delete the employee
            $employee->delete();

            return response()->json([
                'success' => true,
                'message' => 'Employee deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete employee',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Restore a soft-deleted employee
     */
    public function restore(string $id)
    {
        try {
            $employee = Employee::withTrashed()->findOrFail($id);
            
            if (!$employee->trashed()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Employee is not deleted'
                ], 400);
            }

            $employee->restore();

            // Restore associated user account if it exists
            if ($employee->user) {
                $employee->user->restore();
            }

            return response()->json([
                'success' => true,
                'message' => 'Employee restored successfully',
                'data' => $employee->load('role', 'user')
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to restore employee',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Permanently delete an employee (force delete)
     */
    public function forceDelete(string $id)
    {
        try {
            $employee = Employee::withTrashed()->findOrFail($id);

            // Delete associated files
            if ($employee->employee_image) {
                Storage::disk('public')->delete($employee->employee_image);
            }

            if ($employee->id_proof) {
                $proofs = json_decode($employee->id_proof, true);
                foreach ($proofs as $proof) {
                    Storage::disk('public')->delete($proof);
                }
            }

            // Force delete associated user account
            if ($employee->user) {
                $employee->user->forceDelete();
            }

            // Force delete the employee
            $employee->forceDelete();

            return response()->json([
                'success' => true,
                'message' => 'Employee permanently deleted'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to permanently delete employee',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate unique employee code
     */
    private function generateEmployeeCode(): string
    {
        // Get the highest employee code number from the database (excluding soft deleted records)
        $highestCode = Employee::whereNotNull('employee_code')
            ->where('employee_code', 'like', 'EB_%')
            ->whereNull('deleted_at') // Exclude soft deleted records
            ->selectRaw('MAX(CAST(SUBSTRING(employee_code, 4) AS UNSIGNED)) as max_number')
            ->value('max_number');
        
        // Start from the next number after the highest
        $nextNumber = ($highestCode ? $highestCode + 1 : 1);
        
        // Find the next available code (skip any existing codes, including soft deleted)
        $maxAttempts = 20; // Increased attempts to handle more gaps
        $attempt = 0;
        
        do {
            $employeeCode = 'EB_' . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
            
            // Check if this code exists in the database (including soft deleted)
            $exists = Employee::withTrashed()
                ->where('employee_code', $employeeCode)
                ->exists();
            
            if (!$exists) {
                // Code is available (not used by active or soft deleted records)
                return $employeeCode;
            }
            
            // Code exists (either active or soft deleted) - skip to next number
            $nextNumber++;
            $attempt++;
            
        } while ($attempt < $maxAttempts);
        
        // If we couldn't find an available code after max attempts, use timestamp-based fallback
        return 'EB_' . date('Ymd') . '_' . rand(100, 999);
    }

    /**
     * Get employee statistics
     */
    public function stats()
    {
        $stats = [
            'total' => Employee::count(),
            'active' => Employee::where('status', 'active')->count(),
            'inactive' => Employee::where('status', 'inactive')->count(),
            'by_role' => Employee::with('role')
                ->selectRaw('role_id, count(*) as count')
                ->groupBy('role_id')
                ->get()
                ->mapWithKeys(function ($item) {
                    return [$item->role->name ?? 'Unknown' => $item->count];
                })
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    /**
     * Get next available employee code
     */
    public function getNextEmployeeCode()
    {
        try {
            $nextCode = $this->generateEmployeeCode();
            
            // Also show current highest code for reference
            $highestCode = Employee::whereNotNull('employee_code')
                ->where('employee_code', 'like', 'EB_%')
                ->whereNull('deleted_at') // Exclude soft deleted records
                ->selectRaw('MAX(CAST(SUBSTRING(employee_code, 4) AS UNSIGNED)) as max_number')
                ->value('max_number');
            
            return response()->json([
                'success' => true,
                'data' => [
                    'next_employee_code' => $nextCode,
                    'current_highest_code' => $highestCode ? 'EB_' . str_pad($highestCode, 3, '0', STR_PAD_LEFT) : 'None',
                    'note' => 'Excludes soft deleted employees'
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate next employee code',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Fix duplicate employee codes
     */
    public function fixDuplicateEmployeeCodes()
    {
        try {
            // Find duplicate employee codes (excluding soft deleted records)
            $duplicates = \DB::table('employees')
                ->select('employee_code', \DB::raw('COUNT(*) as count'))
                ->whereNotNull('employee_code')
                ->whereNull('deleted_at') // Exclude soft deleted records
                ->groupBy('employee_code')
                ->having('count', '>', 1)
                ->get();

            if ($duplicates->count() === 0) {
                return response()->json([
                    'success' => true,
                    'message' => 'No duplicate employee codes found (excluding soft deleted employees)'
                ]);
            }

            $fixedCount = 0;
            
            // Get the highest existing employee code number (excluding soft deleted records)
            $highestCode = Employee::whereNotNull('employee_code')
                ->where('employee_code', 'like', 'EB_%')
                ->whereNull('deleted_at') // Exclude soft deleted records
                ->selectRaw('MAX(CAST(SUBSTRING(employee_code, 4) AS UNSIGNED)) as max_number')
                ->value('max_number');
            
            $counter = ($highestCode ? $highestCode + 1 : 1);

            foreach ($duplicates as $duplicate) {
                // Get all employees with this duplicate code (excluding soft deleted records)
                $employees = Employee::where('employee_code', $duplicate->employee_code)
                    ->whereNull('deleted_at') // Exclude soft deleted records
                    ->orderBy('created_at')
                    ->get();

                // Keep the first one (oldest), update the rest
                for ($i = 1; $i < $employees->count(); $i++) {
                    $newCode = 'EB_' . str_pad($counter, 3, '0', STR_PAD_LEFT);
                    
                    // Make sure the new code doesn't exist (excluding soft deleted records)
                    while (Employee::where('employee_code', $newCode)
                            ->whereNull('deleted_at') // Exclude soft deleted records
                            ->exists()) {
                        $counter++;
                        $newCode = 'EB_' . str_pad($counter, 3, '0', STR_PAD_LEFT);
                    }
                    
                    $employees[$i]->update(['employee_code' => $newCode]);
                    $counter++;
                    $fixedCount++;
                }
            }

            return response()->json([
                'success' => true,
                'message' => "Fixed {$fixedCount} duplicate employee codes (excluding soft deleted employees)",
                'data' => [
                    'duplicates_found' => $duplicates->count(),
                    'codes_fixed' => $fixedCount,
                    'note' => 'Only considers active (non-deleted) employees'
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fix duplicate employee codes',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store employee file with structured folder and timestamped filename in public/files
     */
    private function storeEmployeeFile($file, $employeeId, $folderType)
    {
        // Create folder structure in public: files/employees/emp_{id}/{folderType}/
        $folderPath = public_path("files/employees/emp_{$employeeId}/{$folderType}");
        
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
        return "files/employees/emp_{$employeeId}/{$folderType}/{$newFilename}";
    }

    /**
     * Export employees to Excel (CSV format)
     */
    public function exportExcel(Request $request): StreamedResponse
    {
        try {
            $query = Employee::with('role')->whereNull('deleted_at');

            // Apply filters if provided
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('employee_code', 'like', "%{$search}%")
                      ->orWhere('mobile_number', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            }

            if ($request->has('role_id') && $request->role_id) {
                $query->where('role_id', $request->role_id);
            }

            if ($request->has('status') && $request->status) {
                $query->where('status', $request->status);
            }

            $employees = $query->orderBy('employee_code', 'asc')->get();

            $filename = 'employees_report_' . date('Y-m-d_His') . '.csv';

            $headers = [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Cache-Control' => 'max-age=0',
            ];

            $callback = function () use ($employees) {
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
                $headers = ['SI No', 'EmpID', 'Name', 'Mobile Number', 'Email', 'City', 'Role', 'Status'];
                fputcsv($output, array_map($escapeCsv, $headers));

                // Data rows
                $sno = 1;
                foreach ($employees as $employee) {
                    fputcsv($output, array_map($escapeCsv, [
                        $sno++,
                        $employee->employee_code ?? '',
                        $employee->name ?? '',
                        $employee->mobile_number ?? '',
                        $employee->email ?? '',
                        $employee->city ?? 'N/A',
                        $employee->role ? $employee->role->name : 'No Role',
                        ucfirst($employee->status ?? 'active')
                    ]));
                }

                fclose($output);
            };

            return response()->stream($callback, 200, $headers);

        } catch (\Exception $e) {
            Log::error('Error exporting employees to Excel: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to export employees',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export employees to PDF (HTML format for printing)
     */
    public function exportPdf(Request $request): Response
    {
        try {
            $query = Employee::with('role')->whereNull('deleted_at');

            // Apply filters if provided
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('employee_code', 'like', "%{$search}%")
                      ->orWhere('mobile_number', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            }

            if ($request->has('role_id') && $request->role_id) {
                $query->where('role_id', $request->role_id);
            }

            if ($request->has('status') && $request->status) {
                $query->where('status', $request->status);
            }

            $employees = $query->orderBy('employee_code', 'asc')->get();

            // Generate HTML content for PDF
            $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>EBMS - Employees Report</title>
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
    <div class="report-subtitle">Employees Report</div>
    <div class="report-meta">Generated on: ' . date('d/m/Y H:i:s') . '</div>
    <table>
        <thead>
            <tr>
                <th>SI No</th>
                <th>EmpID</th>
                <th>Name</th>
                <th>Mobile Number</th>
                <th>Email</th>
                <th>City</th>
                <th>Role</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>';

            $sno = 1;
            foreach ($employees as $employee) {
                $html .= '<tr>
                    <td>' . $sno++ . '</td>
                    <td>' . htmlspecialchars($employee->employee_code ?? '') . '</td>
                    <td>' . htmlspecialchars($employee->name ?? '') . '</td>
                    <td>' . htmlspecialchars($employee->mobile_number ?? '') . '</td>
                    <td>' . htmlspecialchars($employee->email ?? '') . '</td>
                    <td>' . htmlspecialchars($employee->city ?? 'N/A') . '</td>
                    <td>' . htmlspecialchars($employee->role ? $employee->role->name : 'No Role') . '</td>
                    <td>' . htmlspecialchars(ucfirst($employee->status ?? 'active')) . '</td>
                </tr>';
            }

            $html .= '</tbody>
    </table>
</body>
</html>';

            return response($html, 200)
                ->header('Content-Type', 'text/html; charset=UTF-8');

        } catch (\Exception $e) {
            Log::error('Error exporting employees to PDF: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to export employees',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
