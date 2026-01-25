<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ServiceController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 15);
        $search = $request->get('search');
        $isPublished = $request->get('is_published');
        $parentId = $request->get('parent_id'); // Filter by parent service or main services

        $query = Service::with(['user', 'parent', 'subServices']);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('content', 'like', "%{$search}%")
                  ->orWhere('excerpt', 'like', "%{$search}%");
            });
        }

        if ($isPublished !== null) {
            $query->where('is_published', (int)$isPublished);
        }

        // Filter by parent_id: null = main services, specific id = sub-services of that parent
        if ($parentId !== null) {
            if ($parentId === 'main' || $parentId === '') {
                $query->whereNull('parent_id'); // Only main services
            } else {
                $query->where('parent_id', $parentId); // Sub-services of specific parent
            }
        }

        $services = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $services
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'image' => 'nullable|string',
            'excerpt' => 'nullable|string',
            'icon' => 'nullable|string|max:255',
            'parent_id' => 'nullable|exists:services,id', // Can be null for main service or ID of parent service
            'is_published' => 'integer|in:0,1,2', // 0 = Draft, 1 = Published, 2 = Archived
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $slug = Str::slug($request->title);
        $originalSlug = $slug;
        $counter = 1;

        // Ensure unique slug
        while (Service::where('slug', $slug)->exists()) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }

        $service = Service::create([
            'title' => $request->title,
            'content' => $request->content,
            'image' => $request->image,
            'slug' => $slug,
            'excerpt' => $request->excerpt,
            'icon' => $request->icon,
            'parent_id' => $request->parent_id, // null for main service, ID for sub-service
            'is_published' => $request->is_published ?? 0, // Default to draft (0)
            'user_id' => auth('api')->id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Service created successfully',
            'data' => $service->load(['user', 'parent', 'subServices'])
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $service = Service::with(['user', 'parent', 'subServices'])->find($id);

        if (!$service) {
            return response()->json([
                'success' => false,
                'message' => 'Service not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $service
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $service = Service::find($id);

        if (!$service) {
            return response()->json([
                'success' => false,
                'message' => 'Service not found'
            ], 404);
        }

        // Check if user owns the service
        if ($service->user_id !== auth('api')->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. You can only update your own services.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:255',
            'content' => 'sometimes|required|string',
            'image' => 'nullable|string',
            'excerpt' => 'nullable|string',
            'icon' => 'nullable|string|max:255',
            'parent_id' => 'nullable|exists:services,id',
            'is_published' => 'integer|in:0,1,2', // 0 = Draft, 1 = Published, 2 = Archived
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $request->only(['title', 'content', 'image', 'excerpt', 'icon', 'parent_id', 'is_published']);

        // Update slug if title changed
        if ($request->has('title') && $request->title !== $service->title) {
            $slug = Str::slug($request->title);
            $originalSlug = $slug;
            $counter = 1;

            while (Service::where('slug', $slug)->where('id', '!=', $id)->exists()) {
                $slug = $originalSlug . '-' . $counter;
                $counter++;
            }

            $data['slug'] = $slug;
        }

        $service->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Service updated successfully',
            'data' => $service->fresh()->load(['user', 'parent', 'subServices'])
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $service = Service::find($id);

        if (!$service) {
            return response()->json([
                'success' => false,
                'message' => 'Service not found'
            ], 404);
        }

        // Check if user owns the service
        if ($service->user_id !== auth('api')->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. You can only delete your own services.'
            ], 403);
        }

        $service->delete();

        return response()->json([
            'success' => true,
            'message' => 'Service deleted successfully'
        ]);
    }

    /**
     * Clone a service (duplicate with new title and slug)
     */
    public function clone($id)
    {
        $originalService = Service::find($id);

        if (!$originalService) {
            return response()->json([
                'success' => false,
                'message' => 'Service not found'
            ], 404);
        }

        // Check if user owns the service
        if ($originalService->user_id !== auth('api')->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. You can only clone your own services.'
            ], 403);
        }

        try {
            // Generate new slug from title
            $newTitle = $originalService->title . ' (Copy)';
            $slug = Str::slug($newTitle);
            $originalSlug = $slug;
            $counter = 1;

            // Ensure unique slug
            while (Service::where('slug', $slug)->exists()) {
                $slug = $originalSlug . '-' . $counter;
                $counter++;
            }

            // Create cloned service
            $clonedService = Service::create([
                'title' => $newTitle,
                'content' => $originalService->content,
                'image' => $originalService->image,
                'slug' => $slug,
                'excerpt' => $originalService->excerpt,
                'icon' => $originalService->icon,
                'parent_id' => $originalService->parent_id, // Keep same parent if sub-service
                'is_published' => 0, // Default to draft for cloned service
                'user_id' => auth('api')->id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Service cloned successfully',
                'data' => $clonedService->load(['user', 'parent', 'subServices'])
            ], 201);

        } catch (\Exception $e) {
            \Log::error('Clone service error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to clone service. Please try again later.'
            ], 500);
        }
    }

    /**
     * Update service status (published/draft/archived)
     */
    public function updateStatus(Request $request, $id)
    {
        $service = Service::find($id);

        if (!$service) {
            return response()->json([
                'success' => false,
                'message' => 'Service not found'
            ], 404);
        }

        // Check if user owns the service
        if ($service->user_id !== auth('api')->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. You can only update your own services.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|integer|in:0,1,2', // 0 = Draft, 1 = Published, 2 = Archived
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $status = (int)$request->status;
        
        // Update is_published: 0 = Draft, 1 = Published, 2 = Archived
        $service->update([
            'is_published' => $status
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Service status updated successfully',
            'data' => $service->fresh()->load(['user', 'parent', 'subServices'])
        ]);
    }

    /**
     * Get main services only (services without parent)
     */
    public function getMainServices()
    {
        $services = Service::with(['user', 'subServices'])
            ->whereNull('parent_id')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $services
        ]);
    }

    /**
     * Get sub-services of a specific parent service
     */
    public function getSubServices($parentId)
    {
        $services = Service::with(['user', 'parent'])
            ->where('parent_id', $parentId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $services
        ]);
    }

    /**
     * Public API: Get menu structure (main services with sub-services)
     * Returns only main services ordered by ID, each with nested sub-services
     */
    public function publicMenu()
    {
        // Get all published main services ordered by ID
        $mainServices = Service::with(['subServices' => function($q) {
            $q->where('is_published', 1)
              ->orderBy('id', 'asc');
        }])
            ->whereNull('parent_id')
            ->where('is_published', 1)
            ->orderBy('id', 'asc')
            ->get();

        // Format response for menu structure
        $menuItems = $mainServices->map(function ($service) {
            return [
                'id' => $service->id,
                'name' => $service->title,
                'slug' => $service->slug,
                'icon' => $service->icon,
                'subservices' => $service->subServices->map(function ($subService) {
                    return [
                        'id' => $subService->id,
                        'name' => $subService->title,
                        'slug' => $subService->slug,
                        'icon' => $subService->icon,
                    ];
                })->toArray()
            ];
        });

        return response()->json($menuItems);
    }

    /**
     * Public API: Display a listing of published services (for website viewing)
     */
    public function publicIndex(Request $request)
    {
        $limit = $request->get('limit');
        $search = $request->get('search');
        $parentId = $request->get('parent_id'); // Filter by parent

        $query = Service::with(['user', 'parent', 'subServices'])
            ->where('is_published', 1); // Only published services

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('content', 'like', "%{$search}%")
                  ->orWhere('excerpt', 'like', "%{$search}%");
            });
        }

        // Filter by parent_id
        if ($parentId !== null) {
            if ($parentId === 'main' || $parentId === '') {
                $query->whereNull('parent_id'); // Only main services
            } else {
                $query->where('parent_id', $parentId); // Sub-services of specific parent
            }
        }

        $query->orderBy('updated_at', 'desc');

        // If limit is provided, return limited results
        // Otherwise, return all services
        if ($limit !== null && $limit !== '') {
            $limit = (int)$limit;
            $services = $query->limit($limit)->get();
        } else {
            $services = $query->get();
        }

        return response()->json([
            'success' => true,
            'data' => $services,
            'count' => $services->count()
        ]);
    }

    /**
     * Public API: Get sub-services of a main service by parent ID
     */
    public function publicGetSubServices($parentId)
    {
        // First verify the parent service exists and is published
        $parentService = Service::where('id', $parentId)
            ->where('is_published', 1)
            ->whereNull('parent_id') // Ensure it's a main service
            ->first();

        if (!$parentService) {
            return response()->json([
                'success' => false,
                'message' => 'Main service not found or not published'
            ], 404);
        }

        // Get all published sub-services of this parent
        $subServices = Service::with('user')
            ->where('parent_id', $parentId)
            ->where('is_published', 1)
            ->orderBy('updated_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'parent_service' => $parentService,
                'sub_services' => $subServices,
                'count' => $subServices->count()
            ]
        ]);
    }

    /**
     * Public API: Get all main services with their sub-services nested (hierarchical structure)
     */
    public function publicGetAllWithChildren(Request $request)
    {
        $search = $request->get('search');

        // Get all published main services
        $query = Service::with(['user', 'subServices' => function($q) {
            $q->where('is_published', 1)
              ->orderBy('id', 'asc');
        }])
            ->whereNull('parent_id') // Only main services
            ->where('is_published', 1);

        // Apply search if provided
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('content', 'like', "%{$search}%")
                  ->orWhere('excerpt', 'like', "%{$search}%");
            });
        }

        $mainServices = $query->orderBy('id', 'asc')->get();

        // Format response with nested structure
        $formattedServices = $mainServices->map(function ($service) {
            return [
                'id' => $service->id,
                'title' => $service->title,
                'content' => $service->content,
                'image' => $service->image,
                'slug' => $service->slug,
                'excerpt' => $service->excerpt,
                'icon' => $service->icon,
                'parent_id' => $service->parent_id,
                'is_published' => $service->is_published,
                'user' => $service->user,
                'created_at' => $service->created_at,
                'updated_at' => $service->updated_at,
                'sub_services' => $service->subServices->map(function ($subService) {
                    return [
                        'id' => $subService->id,
                        'title' => $subService->title,
                        'content' => $subService->content,
                        'image' => $subService->image,
                        'slug' => $subService->slug,
                        'excerpt' => $subService->excerpt,
                        'icon' => $subService->icon,
                        'parent_id' => $subService->parent_id,
                        'is_published' => $subService->is_published,
                        'user' => $subService->user,
                        'created_at' => $subService->created_at,
                        'updated_at' => $subService->updated_at,
                    ];
                })
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $formattedServices,
            'count' => $formattedServices->count()
        ]);
    }

    /**
     * Public API: Display a single published service by slug (for website viewing)
     * Includes parent service, sub-services, and related services
     */
    public function publicShow($slug)
    {
        $service = Service::with(['user'])
            ->where('slug', $slug)
            ->where('is_published', 1) // Only published services
            ->first();

        if (!$service) {
            return response()->json([
                'success' => false,
                'message' => 'Service not found'
            ], 404);
        }

        // Format parent service with all sub-services (title and slug only)
        $parentData = null;
        if ($service->parent_id) {
            // Get parent service
            $parentService = Service::where('id', $service->parent_id)
                ->where('is_published', 1)
                ->first();
            
            if ($parentService) {
                // Get all sub-services of the parent
                $parentSubServices = Service::where('parent_id', $service->parent_id)
                    ->where('is_published', 1)
                    ->orderBy('id', 'asc')
                    ->select('id', 'title', 'slug', 'icon')
                    ->get();
                
                $parentData = [
                    'id' => $parentService->id,
                    'title' => $parentService->title,
                    'slug' => $parentService->slug,
                    'icon' => $parentService->icon,
                    'sub_services' => $parentSubServices->map(function ($sub) {
                        return [
                            'id' => $sub->id,
                            'title' => $sub->title,
                            'slug' => $sub->slug,
                            'icon' => $sub->icon,
                        ];
                    })->toArray()
                ];
            }
        }

        // Format sub-services for main service (title and slug only)
        $formattedSubServices = [];
        if (!$service->parent_id) {
            // If main service, get all sub-services
            $subServices = Service::where('parent_id', $service->id)
                ->where('is_published', 1)
                ->orderBy('id', 'asc')
                ->select('id', 'title', 'slug', 'icon')
                ->get();
            
            $formattedSubServices = $subServices->map(function ($sub) {
                return [
                    'id' => $sub->id,
                    'title' => $sub->title,
                    'slug' => $sub->slug,
                    'icon' => $sub->icon,
                ];
            })->toArray();
        }

        // Remove parent and sub_services from service object
        $serviceData = $service->toArray();
        unset($serviceData['parent']);
        unset($serviceData['sub_services']);

        return response()->json([
            'success' => true,
            'data' => [
                'service' => $serviceData,
                'parent' => $parentData,
                'sub_services' => $formattedSubServices
            ]
        ]);
    }
}

