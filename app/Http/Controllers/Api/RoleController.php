<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\RolePrivilege;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class RoleController extends Controller
{
    /**
     * Display a listing of roles
     */
    public function index(Request $request): JsonResponse
    {
        $query = Role::with('privileges');

        // Search functionality
        if ($request->has('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('is_active', $request->get('status') === 'active');
        }

        // Check if all data is requested (no pagination)
        if ($request->has('all') && $request->get('all') === 'true') {
            $roles = $query->get();
            return response()->json([
                'success' => true,
                'data' => $roles
            ]);
        }

        $roles = $query->paginate($request->get('per_page', 15));

        // Transform the data to include formatted privileges
        $roles->getCollection()->transform(function ($role) {
            $role->role_privileges = $this->formatRolePrivileges($role->privileges);
            return $role;
        });

        return response()->json([
            'success' => true,
            'data' => $roles
        ]);
    }

    /**
     * Store a newly created role
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:roles,name',
            'description' => 'nullable|string',
            'privileges' => 'required|array|min:1',
        ]);

        // Custom validation to ensure at least one privilege is enabled
        $validator->after(function ($validator) use ($request) {
            if ($request->has('privileges') && is_array($request->privileges)) {
                $hasEnabledPrivilege = false;
                foreach ($request->privileges as $category => $actions) {
                    if (is_array($actions)) {
                        foreach ($actions as $action => $enabled) {
                            if ($enabled === true) {
                                $hasEnabledPrivilege = true;
                                break 2;
                            }
                        }
                    }
                }
                
                if (!$hasEnabledPrivilege) {
                    $validator->errors()->add('privileges', 'At least one privilege must be selected.');
                }
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        // Generate slug from name (replace spaces with underscores)
        $slug = str_replace(' ', '_', strtolower($request->name));

        $role = Role::create([
            'name' => $request->name,
            'slug' => $slug,
            'display_name' => $request->name,
            'description' => $request->description,
            'is_active' => true,
        ]);

        // Store privileges (only if provided and not empty)
        if ($request->has('privileges') && !empty($request->privileges)) {
            $this->storeRolePrivileges($role->id, $request->privileges);
        }

        $role->load('privileges');

        return response()->json([
            'success' => true,
            'message' => 'Role created successfully',
            'data' => [
                'role' => $role
            ]
        ], 201);
    }

    /**
     * Display the specified role
     */
    public function show(Role $role): JsonResponse
    {
        $role->load('privileges');
        $role->role_privileges = $this->formatRolePrivileges($role->privileges);
        
        return response()->json([
            'success' => true,
            'data' => [
                'role' => $role
            ]
        ]);
    }

    /**
     * Update the specified role
     */
    public function update(Request $request, Role $role): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255|unique:roles,name,' . $role->id,
            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
            'privileges' => 'sometimes|required|array|min:1',
        ]);

        // Custom validation to ensure at least one privilege is enabled when privileges are provided
        $validator->after(function ($validator) use ($request) {
            if ($request->has('privileges') && is_array($request->privileges)) {
                $hasEnabledPrivilege = false;
                foreach ($request->privileges as $category => $actions) {
                    if (is_array($actions)) {
                        foreach ($actions as $action => $enabled) {
                            if ($enabled === true) {
                                $hasEnabledPrivilege = true;
                                break 2;
                            }
                        }
                    }
                }
                
                if (!$hasEnabledPrivilege) {
                    $validator->errors()->add('privileges', 'At least one privilege must be selected.');
                }
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        $updateData = $request->only(['name', 'description', 'is_active']);
        
        // Generate slug from name if name is being updated
        if ($request->has('name')) {
            $updateData['slug'] = str_replace(' ', '_', strtolower($request->name));
            $updateData['display_name'] = $request->name;
        }

        $role->update($updateData);

        // Update privileges if provided
        if ($request->has('privileges')) {
            // Delete existing privileges
            $role->privileges()->delete();
            // Store new privileges (only if not empty)
            if (!empty($request->privileges)) {
                $this->storeRolePrivileges($role->id, $request->privileges);
            }
        }

        $role->load('privileges');

        return response()->json([
            'success' => true,
            'message' => 'Role updated successfully',
            'data' => [
                'role' => $role
            ]
        ]);
    }

    /**
     * Remove the specified role (soft delete)
     */
    public function destroy(Role $role): JsonResponse
    {
        try {
            \Log::info('Attempting to delete role', [
                'role_id' => $role->id,
                'role_name' => $role->name
            ]);

            // Check if role has employees (users are connected through employees)
            $employeeCount = $role->employee_count;
            $userCount = $role->user_count;
            
            \Log::info('Role counts', [
                'employee_count' => $employeeCount,
                'user_count' => $userCount
            ]);
            
            if ($employeeCount > 0) {
                \Log::warning('Cannot delete role with assigned employees', [
                    'role_id' => $role->id,
                    'employee_count' => $employeeCount,
                    'user_count' => $userCount
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete role that has assigned employees. Please reassign or remove employees first.'
                ], 400);
            }

            // Soft delete the role
            $role->delete();
            
            \Log::info('Role deleted successfully', ['role_id' => $role->id]);

            return response()->json([
                'success' => true,
                'message' => 'Role deleted successfully'
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to delete role', [
                'role_id' => $role->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete role',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Restore a soft-deleted role
     */
    public function restore(int $id): JsonResponse
    {
        try {
            $role = Role::withTrashed()->findOrFail($id);
            
            if (!$role->trashed()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Role is not deleted'
                ], 400);
            }

            $role->restore();

            return response()->json([
                'success' => true,
                'message' => 'Role restored successfully',
                'data' => [
                    'role' => $role
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to restore role',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Permanently delete a role
     */
    public function forceDelete(int $id): JsonResponse
    {
        try {
            $role = Role::withTrashed()->findOrFail($id);
            
            if (!$role->trashed()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Role must be soft-deleted before permanent deletion'
                ], 400);
            }

            // Check if role has users
            if ($role->users()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot permanently delete role that has assigned users'
                ], 400);
            }

            // Delete associated privileges first
            $role->privileges()->delete();
            
            // Permanently delete the role
            $role->forceDelete();

            return response()->json([
                'success' => true,
                'message' => 'Role permanently deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to permanently delete role',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Get all active roles for dropdown/select purposes
     */
    public function list(): JsonResponse
    {
        $roles = Role::active()
            ->select('id', 'name', 'slug', 'display_name')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $roles
        ]);
    }

    /**
     * Get role privileges
     */
    public function getRolePrivileges(Role $role): JsonResponse
    {
        $privileges = $role->privileges()->get();
        
        // Convert to the format expected by frontend
        $privilegeData = [
            'employeeManagement' => ['add' => false, 'edit' => false, 'view' => false, 'delete' => false],
            'role' => ['add' => false, 'edit' => false, 'view' => false, 'delete' => false],
            'vendors' => ['add' => false, 'edit' => false, 'view' => false, 'delete' => false],
            'machines' => ['add' => false, 'edit' => false, 'view' => false, 'delete' => false],
            'products' => ['add' => false, 'edit' => false, 'view' => false, 'delete' => false],
            'attendance' => ['attendanceReport' => false, 'regularizationScreenAccess' => false],
            'stocks' => ['stockIn' => false, 'stockOut' => false, 'transfer' => false],
            'machineReading' => ['add' => false, 'edit' => false, 'view' => false, 'delete' => false],
            'fileUpload' => ['access' => false]
        ];

        foreach ($privileges as $privilege) {
            if (isset($privilegeData[$privilege->category])) {
                if (is_array($privilegeData[$privilege->category])) {
                    $privilegeData[$privilege->category][$privilege->action] = true;
                }
            }
        }

        return response()->json([
            'success' => true,
            'data' => $privilegeData
        ]);
    }

    /**
     * Store role privileges
     */
    private function storeRolePrivileges(int $roleId, array $privileges): void
    {
        $privilegeData = [];

        foreach ($privileges as $category => $actions) {
            if (is_array($actions)) {
                foreach ($actions as $action => $enabled) {
                    if ($enabled) {
                        $privilegeData[] = [
                            'role_id' => $roleId,
                            'category' => $category,
                            'action' => $action,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                }
            }
        }

        if (!empty($privilegeData)) {
            RolePrivilege::insert($privilegeData);
        }
    }

    /**
     * Format role privileges for frontend - Dynamic structure
     */
    private function formatRolePrivileges($privileges)
    {
        $privilegeData = [];

        foreach ($privileges as $privilege) {
            if (!isset($privilegeData[$privilege->category])) {
                $privilegeData[$privilege->category] = [];
            }
            $privilegeData[$privilege->category][$privilege->action] = true;
        }

        return $privilegeData;
    }
}
