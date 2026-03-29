<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use Illuminate\Http\JsonResponse;

class PermissionController extends Controller
{
    /**
     * Get permissions configuration grouped by module
     */
    public function getConfig(): JsonResponse
    {
        try {
            $permissions = Permission::where('is_active', true)
                ->orderBy('module')
                ->orderBy('name')
                ->get()
                ->groupBy('module')
                ->map(function ($modulePermissions, $moduleName) {
                    return [
                        'module_name' => $moduleName,
                        'actions' => $modulePermissions->map(function ($permission) {
                            return [
                                'name' => $permission->name,
                                'slug' => $permission->slug,
                                'action' => $permission->action,
                                'description' => $permission->description
                            ];
                        })->values()->toArray()
                    ];
                })
                ->values();

            return response()->json($permissions);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch permissions configuration',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all permissions with full details
     */
    public function index(): JsonResponse
    {
        try {
            $permissions = Permission::where('is_active', true)
                ->orderBy('module')
                ->orderBy('name')
                ->orderBy('action')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $permissions
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch permissions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get permissions by module
     */
    public function getByModule(string $module): JsonResponse
    {
        try {
            $permissions = Permission::where('is_active', true)
                ->where('module', $module)
                ->orderBy('name')
                ->orderBy('action')
                ->get()
                ->groupBy('name')
                ->map(function ($permissionGroup) {
                    $firstPermission = $permissionGroup->first();
                    return [
                        'name' => $firstPermission->name,
                        'module' => $firstPermission->module,
                        'actions' => $permissionGroup->pluck('action')->toArray()
                    ];
                })
                ->values();

            return response()->json([
                'success' => true,
                'data' => $permissions
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch permissions for module',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
