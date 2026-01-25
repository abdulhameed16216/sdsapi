<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GalleryImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

class GalleryController extends Controller
{
    /**
     * Get full image URL using APP_URL from .env
     * 
     * @param string $relativePath Path relative to public (e.g., uploads/gallery/folder/image.jpg)
     * @return string Full absolute URL
     */
    private function getImageUrl($relativePath)
    {
        $appUrl = rtrim(config('app.url'), '/');
        // Construct: APP_URL + / + relative path (files are directly in public/uploads)
        // Example: https://sdsapi.veeyaainnovatives.com/uploads/gallery/folder/image.jpg
        return $appUrl . '/' . ltrim($relativePath, '/');
    }

    /**
     * Get all gallery images
     */
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 15);
        $search = $request->get('search');
        $category = $request->get('category');

        $query = GalleryImage::query();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('category', 'like', "%{$search}%");
            });
        }

        if ($category) {
            $query->where('category', $category);
        }

        $images = $query->orderBy('created_at', 'desc')->paginate($perPage);

        // Update URLs to use APP_URL from .env - always reconstruct from file_path
        $images->getCollection()->transform(function ($image) {
            // Always reconstruct URL from file_path using APP_URL from .env
            // Files are now directly in public/uploads, so URL is just /uploads/...
            $image->url = $this->getImageUrl($image->file_path);
            return $image;
        });

        return response()->json([
            'success' => true,
            'data' => $images
        ]);
    }

    /**
     * Upload gallery image
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|image|mimes:png,jpg,jpeg,gif,webp|max:10240', // 10MB max - Only images (PNG, JPG, JPEG, GIF, WEBP)
            'title' => 'nullable|string|max:255',
            'category' => 'required|string|in:blogs,services,portfolio',
            'quality' => 'sometimes|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $file = $request->file('file');
            $title = $request->get('title', 'Untitled');
            $category = $request->get('category'); // Required field - blogs, services, or portfolio
            $quality = (int) $request->get('quality', 85); // Cast to integer to ensure type safety

            // Generate folder name: random alphabets + date_time
            $randomString = Str::random(8);
            $dateTime = now()->format('Ymd_His');
            $folderName = $randomString . '_' . $dateTime;

            // Get original filename without extension
            $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $extension = $file->getClientOriginalExtension();

            // Create full path - save directly to public/uploads/gallery
            $folderPath = public_path('uploads/gallery/' . $folderName);
            $fileName = $originalName . '.' . $extension;
            $fullPath = $folderPath . '/' . $fileName;
            $relativePath = 'uploads/gallery/' . $folderName . '/' . $fileName;

            // Create directory if it doesn't exist
            if (!is_dir($folderPath)) {
                mkdir($folderPath, 0755, true);
            }

            $mimeType = $file->getMimeType();

            // Check if GD extension is available before trying to use Intervention Image
            if (extension_loaded('gd') && function_exists('imagecreate')) {
                try {
                    // Dynamically load Intervention Image classes only if GD is available
                    $driverClass = 'Intervention\Image\Drivers\Gd\Driver';
                    $managerClass = 'Intervention\Image\ImageManager';
                    
                    if (class_exists($driverClass) && class_exists($managerClass)) {
                        try {
                            $driver = new $driverClass();
                        } catch (\Exception $driverError) {
                            // Driver initialization failed, save directly
                            $file->move($folderPath, $fileName);
                            $driver = null;
                        }
                        
                        if ($driver !== null) {
                            $manager = new $managerClass($driver);
                            $image = $manager->read($file->getRealPath());

                            // Resize if image is too large (optional - maintain aspect ratio)
                            $maxWidth = 1920;
                            $maxHeight = 1080;

                            if ($image->width() > $maxWidth || $image->height() > $maxHeight) {
                                $image->scaleDown($maxWidth, $maxHeight);
                            }

                            // Encode and save with quality compression based on file type
                            if (in_array($mimeType, ['image/jpeg', 'image/jpg'])) {
                                $encoded = $image->toJpeg(quality: (int) $quality);
                                $encoded->save($fullPath);
                            } elseif ($mimeType === 'image/png') {
                                $encoded = $image->toPng();
                                $encoded->save($fullPath);
                            } elseif ($mimeType === 'image/webp') {
                                $encoded = $image->toWebp(quality: (int) $quality);
                                $encoded->save($fullPath);
                            } elseif ($mimeType === 'image/gif') {
                                $encoded = $image->toGif();
                                $encoded->save($fullPath);
                            } else {
                                $encoded = $image->toJpeg(quality: (int) $quality);
                                $encoded->save($fullPath);
                            }
                        } else {
                            // Driver was null, save directly
                            $file->move($folderPath, $fileName);
                        }
                    } else {
                        // Classes not found, save directly
                        $file->move($folderPath, $fileName);
                    }
                } catch (\Exception $e) {
                    // If image processing fails for any reason (including GD errors), fall back to direct save
                    // This ensures uploads work even if GD is not properly configured
                    $file->move($folderPath, $fileName);
                }
            } else {
                // GD not available or not functional, save file directly without processing
                // This will work on any hosting without GD extension
                $file->move($folderPath, $fileName);
            }

            // Get public URL - direct path from public folder
            $url = '/' . $relativePath;

            // Save to database
            $galleryImage = GalleryImage::create([
                'title' => $title,
                'category' => $category,
                'filename' => $fileName,
                'file_path' => $relativePath,
                'url' => $url,
                'mime_type' => $mimeType,
                'file_size' => filesize($fullPath),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Image uploaded successfully',
                'data' => $galleryImage
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Image upload failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete gallery image
     */
    public function destroy($id)
    {
        $image = GalleryImage::find($id);

        if (!$image) {
            return response()->json([
                'success' => false,
                'message' => 'Image not found'
            ], 404);
        }

        // Check if image is referenced in any blogs
        $imageUrl = $image->url;
        $imagePath = $image->file_path;
        
        // Get base URL to match different URL formats
        $baseUrl = rtrim(config('app.url'), '/');
        $storageUrl = $baseUrl . '/storage/';
        
        // Check if image is used as featured image in any blog
        $usedAsFeatured = \App\Models\Blog::where('image', 'like', '%' . $imageUrl . '%')
            ->orWhere('image', 'like', '%' . $imagePath . '%')
            ->orWhere('image', 'like', '%' . basename($imagePath) . '%')
            ->exists();
        
        // Check if image URL appears in blog content (HTML)
        $usedInContent = \App\Models\Blog::where('content', 'like', '%' . $imageUrl . '%')
            ->orWhere('content', 'like', '%' . $imagePath . '%')
            ->orWhere('content', 'like', '%' . basename($imagePath) . '%')
            ->exists();
        
        // Also check with storage URL format
        $storageImageUrl = $storageUrl . $imagePath;
        if (!$usedAsFeatured) {
            $usedAsFeatured = \App\Models\Blog::where('image', 'like', '%' . $storageImageUrl . '%')->exists();
        }
        if (!$usedInContent) {
            $usedInContent = \App\Models\Blog::where('content', 'like', '%' . $storageImageUrl . '%')->exists();
        }
        
        // Check with just the filename (in case URL format differs)
        $filename = basename($imagePath);
        if (!$usedAsFeatured) {
            $usedAsFeatured = \App\Models\Blog::where('image', 'like', '%' . $filename . '%')->exists();
        }
        if (!$usedInContent) {
            $usedInContent = \App\Models\Blog::where('content', 'like', '%' . $filename . '%')->exists();
        }
        
        if ($usedAsFeatured || $usedInContent) {
            // Get blog titles that use this image
            $blogsUsingImage = \App\Models\Blog::where(function($query) use ($imageUrl, $imagePath, $storageImageUrl, $filename) {
                $query->where('image', 'like', '%' . $imageUrl . '%')
                    ->orWhere('image', 'like', '%' . $imagePath . '%')
                    ->orWhere('image', 'like', '%' . $storageImageUrl . '%')
                    ->orWhere('image', 'like', '%' . $filename . '%')
                    ->orWhere('content', 'like', '%' . $imageUrl . '%')
                    ->orWhere('content', 'like', '%' . $imagePath . '%')
                    ->orWhere('content', 'like', '%' . $storageImageUrl . '%')
                    ->orWhere('content', 'like', '%' . $filename . '%');
            })->pluck('title')->toArray();
            
            $blogTitles = !empty($blogsUsingImage) ? implode(', ', array_slice($blogsUsingImage, 0, 3)) : '';
            $moreBlogs = count($blogsUsingImage) > 3 ? ' and ' . (count($blogsUsingImage) - 3) . ' more' : '';
            
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete image: This image is being used in one or more blog posts.',
                'details' => !empty($blogTitles) ? 'Used in: ' . $blogTitles . $moreBlogs : 'Please remove this image from all blog posts before deleting.',
                'blogs_count' => count($blogsUsingImage),
                'blogs' => $blogsUsingImage
            ], 422);
        }

        // Extract folder path from file_path
        // file_path format: uploads/gallery/randomString_datetime/filename.jpg
        // We need to delete the entire folder: uploads/gallery/randomString_datetime/
        $filePath = $image->file_path;
        $folderPath = dirname($filePath); // Gets: uploads/gallery/randomString_datetime
        $fullFolderPath = public_path($folderPath);
        $fullFilePath = public_path($filePath);

        // Delete the entire folder (which contains the random generated string)
        if (is_dir($fullFolderPath)) {
            // Delete all files in the folder first
            $files = glob($fullFolderPath . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            // Then delete the folder
            rmdir($fullFolderPath);
        }

        // Also delete the file itself (in case folder deletion fails)
        if (file_exists($fullFilePath)) {
            unlink($fullFilePath);
        }

        // Delete from database
        $image->delete();

        return response()->json([
            'success' => true,
            'message' => 'Image and folder deleted successfully'
        ]);
    }

    /**
     * Public API: Display a listing of portfolio images (for website viewing)
     * Returns all portfolio images (no pagination by default, but supports limit parameter)
     * Supports search parameter
     */
    public function publicPortfolio(Request $request)
    {
        $limit = $request->get('limit');
        $search = $request->get('search');

        $query = GalleryImage::where('category', 'portfolio'); // Only portfolio category

        // Search functionality
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%");
            });
        }

        $query->orderBy('created_at', 'desc');

        // Apply limit if provided, otherwise return all
        if ($limit) {
            $images = $query->limit((int)$limit)->get();
        } else {
            $images = $query->get();
        }

        // Update URLs to use APP_URL from .env
        $images->transform(function ($image) {
            $image->url = $this->getImageUrl($image->file_path);
            return $image;
        });

        return response()->json([
            'success' => true,
            'data' => $images,
            'count' => $images->count()
        ]);
    }

    /**
     * Public API: Display a single portfolio image by ID (for website viewing)
     */
    public function publicPortfolioShow($id)
    {
        $image = GalleryImage::where('id', $id)
            ->where('category', 'portfolio') // Only portfolio category
            ->first();

        if (!$image) {
            return response()->json([
                'success' => false,
                'message' => 'Portfolio image not found'
            ], 404);
        }

        // Update URL to use APP_URL from .env
        $image->url = $this->getImageUrl($image->file_path);

        return response()->json([
            'success' => true,
            'data' => $image
        ]);
    }
}
