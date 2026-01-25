<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Blog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class BlogController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 15);
        $search = $request->get('search');
        $isPublished = $request->get('is_published');

        $query = Blog::with('user');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('content', 'like', "%{$search}%");
            });
        }

        if ($isPublished !== null) {
            $query->where('is_published', (int)$isPublished);
        }

        $blogs = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $blogs
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
            'category' => 'nullable|string|max:255',
            'excerpt' => 'nullable|string',
            'tags' => 'nullable|string',
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
        while (Blog::where('slug', $slug)->exists()) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }

        $blog = Blog::create([
            'title' => $request->title,
            'content' => $request->content,
            'image' => $request->image,
            'slug' => $slug,
            'category' => $request->category,
            'excerpt' => $request->excerpt,
            'tags' => $request->tags,
            'is_published' => $request->is_published ?? 0, // Default to draft (0)
            'user_id' => auth('api')->id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Blog created successfully',
            'data' => $blog->load('user')
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $blog = Blog::with('user')->find($id);

        if (!$blog) {
            return response()->json([
                'success' => false,
                'message' => 'Blog not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $blog
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $blog = Blog::find($id);

        if (!$blog) {
            return response()->json([
                'success' => false,
                'message' => 'Blog not found'
            ], 404);
        }

        // Check if user owns the blog or is admin
        if ($blog->user_id !== auth('api')->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. You can only update your own blogs.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:255',
            'content' => 'sometimes|required|string',
            'image' => 'nullable|string',
            'category' => 'nullable|string|max:255',
            'excerpt' => 'nullable|string',
            'tags' => 'nullable|string',
            'is_published' => 'integer|in:0,1,2', // 0 = Draft, 1 = Published, 2 = Archived
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $request->only(['title', 'content', 'image', 'category', 'excerpt', 'tags', 'is_published']);

        // Update slug if title changed
        if ($request->has('title') && $request->title !== $blog->title) {
            $slug = Str::slug($request->title);
            $originalSlug = $slug;
            $counter = 1;

            while (Blog::where('slug', $slug)->where('id', '!=', $id)->exists()) {
                $slug = $originalSlug . '-' . $counter;
                $counter++;
            }

            $data['slug'] = $slug;
        }

        $blog->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Blog updated successfully',
            'data' => $blog->fresh()->load('user')
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $blog = Blog::find($id);

        if (!$blog) {
            return response()->json([
                'success' => false,
                'message' => 'Blog not found'
            ], 404);
        }

        // Check if user owns the blog or is admin
        if ($blog->user_id !== auth('api')->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. You can only delete your own blogs.'
            ], 403);
        }

        $blog->delete();

        return response()->json([
            'success' => true,
            'message' => 'Blog deleted successfully'
        ]);
    }

    /**
     * Update blog status (published/draft/archived)
     */
    public function updateStatus(Request $request, $id)
    {
        $blog = Blog::find($id);

        if (!$blog) {
            return response()->json([
                'success' => false,
                'message' => 'Blog not found'
            ], 404);
        }

        // Check if user owns the blog
        if ($blog->user_id !== auth('api')->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. You can only update your own blogs.'
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
        $blog->update([
            'is_published' => $status
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Blog status updated successfully',
            'data' => $blog->fresh()->load('user')
        ]);
    }

    /**
     * Public API: Display a listing of published blogs (for website viewing)
     * Supports search and limit parameter
     * - If limit is provided, returns only that many blogs
     * - If limit is not provided, returns all blogs
     */
    public function publicIndex(Request $request)
    {
        $limit = $request->get('limit');
        $search = $request->get('search');
        $category = $request->get('category');

        $query = Blog::with('user')
            ->where('is_published', 1); // Only published blogs

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('content', 'like', "%{$search}%")
                  ->orWhere('excerpt', 'like', "%{$search}%")
                  ->orWhere('tags', 'like', "%{$search}%");
            });
        }

        if ($category) {
            $query->where('category', $category);
        }

        $query->orderBy('updated_at', 'desc');

        // If limit is provided, return limited results
        // Otherwise, return all blogs
        if ($limit !== null && $limit !== '') {
            $limit = (int)$limit;
            $blogs = $query->limit($limit)->get();
        } else {
            $blogs = $query->get();
        }

        return response()->json([
            'success' => true,
            'data' => $blogs,
            'count' => $blogs->count()
        ]);
    }

    /**
     * Public API: Display a single published blog by slug (for website viewing)
     * Includes next and previous blog information
     */
    public function publicShow($slug)
    {
        $blog = Blog::with('user')
            ->where('slug', $slug)
            ->where('is_published', 1) // Only published blogs
            ->first();

        if (!$blog) {
            return response()->json([
                'success' => false,
                'message' => 'Blog not found'
            ], 404);
        }

        // Get next blog (published, ordered by created_at desc)
        $nextBlog = Blog::where('is_published', 1)
            ->where('created_at', '<', $blog->created_at)
            ->orderBy('created_at', 'desc')
            ->select('id', 'title', 'slug', 'image', 'excerpt', 'category', 'created_at')
            ->first();

        // Get previous blog (published, ordered by created_at asc)
        $prevBlog = Blog::where('is_published', 1)
            ->where('created_at', '>', $blog->created_at)
            ->orderBy('created_at', 'asc')
            ->select('id', 'title', 'slug', 'image', 'excerpt', 'category', 'created_at')
            ->first();

        // Get related blogs (6 blogs based on same category, excluding current blog)
        $relatedBlogs = Blog::where('is_published', 1)
            ->where('id', '!=', $blog->id)
            ->where('category', $blog->category)
            ->orderBy('updated_at', 'desc')
            ->select('id', 'title', 'slug', 'image', 'excerpt', 'category', 'created_at', 'updated_at')
            ->limit(4)
            ->get();

        // If we don't have 6 related blogs in the same category, fill with other published blogs
        if ($relatedBlogs->count() < 6) {
            $remainingCount = 6 - $relatedBlogs->count();
            $additionalBlogs = Blog::where('is_published', 1)
                ->where('id', '!=', $blog->id)
                ->whereNotIn('id', $relatedBlogs->pluck('id'))
                ->orderBy('updated_at', 'desc')
                ->select('id', 'title', 'slug', 'image', 'excerpt', 'category', 'created_at', 'updated_at')
                ->limit($remainingCount)
                ->get();
            
            $relatedBlogs = $relatedBlogs->merge($additionalBlogs);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'blog' => $blog,
                'next_blog' => $nextBlog,
                'prev_blog' => $prevBlog,
                'related_blogs' => $relatedBlogs
            ]
        ]);
    }
}
