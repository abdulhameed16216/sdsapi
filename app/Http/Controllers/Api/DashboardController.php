<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Blog;
use App\Models\GalleryImage;
use App\Models\Service;
use App\Models\Subscription;
use App\Models\Customer;
use App\Models\Project;
use App\Models\PdfDownload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    /**
     * Get dashboard statistics
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function statistics()
    {
        try {
            // User Statistics
            $totalUsers = User::count();
            $activeUsers = User::where('status', 1)->count();
            $newUsersThisMonth = User::whereMonth('created_at', Carbon::now()->month)
                ->whereYear('created_at', Carbon::now()->year)
                ->count();

            // Blog Statistics
            $totalBlogs = Blog::count();
            $publishedBlogs = Blog::where('is_published', 1)->count();
            $draftBlogs = Blog::where('is_published', 0)->count();
            $archivedBlogs = Blog::where('is_published', 2)->count();

            // Gallery Statistics
            $totalImages = GalleryImage::count();
            $imagesThisMonth = GalleryImage::whereMonth('created_at', Carbon::now()->month)
                ->whereYear('created_at', Carbon::now()->year)
                ->count();
            
            // Calculate total size in bytes
            $totalSizeBytes = GalleryImage::sum('file_size');
            $totalSizeGB = round($totalSizeBytes / (1024 * 1024 * 1024), 2);
            $totalSizeMB = round($totalSizeBytes / (1024 * 1024), 2);
            
            // Format total size
            $totalSize = $totalSizeGB >= 1 
                ? number_format($totalSizeGB, 2) . ' GB'
                : number_format($totalSizeMB, 2) . ' MB';

            // Services Statistics
            $totalServices = Service::count();
            $activeServices = Service::where('is_published', 1)->count();
            $inactiveServices = Service::where('is_published', 0)->count();

            // Subscriptions Statistics
            $totalSubscriptions = Subscription::count();
            $publishedSubscriptions = Subscription::where('status', 1)->count(); // status 1 = published
            $draftSubscriptions = Subscription::where('status', 0)->count(); // status 0 = draft

            // Customers Statistics
            $totalCustomers = Customer::count();
            // Count active customers (assuming all customers are active by default, or check status field if exists)
            $activeCustomers = Customer::count(); // All customers are considered active
            $newCustomersThisMonth = Customer::whereMonth('created_at', Carbon::now()->month)
                ->whereYear('created_at', Carbon::now()->year)
                ->count();

            // Projects Statistics
            $totalProjects = Project::count();
            // Count projects with at least one incomplete step as active
            $activeProjects = Project::whereHas('steps', function($query) {
                $query->where('status', '!=', 'completed');
            })->orWhereDoesntHave('steps')->count();
            // Count projects where all steps are completed
            $completedProjects = 0;
            $projects = Project::with('steps')->get();
            foreach ($projects as $project) {
                $steps = $project->steps;
                if ($steps->isNotEmpty() && $steps->every(function($step) {
                    return $step->status === 'completed';
                })) {
                    $completedProjects++;
                }
            }
            // Recalculate active as total - completed
            $activeProjects = $totalProjects - $completedProjects;

            // PDF Downloads Statistics
            $totalPDFs = PdfDownload::count();

            return response()->json([
                'success' => true,
                'data' => [
                    'users' => [
                        'total' => $totalUsers,
                        'active' => $activeUsers,
                        'new_this_month' => $newUsersThisMonth,
                    ],
                    'blogs' => [
                        'total' => $totalBlogs,
                        'published' => $publishedBlogs,
                        'draft' => $draftBlogs,
                        'archived' => $archivedBlogs,
                    ],
                    'gallery' => [
                        'total' => $totalImages,
                        'new_this_month' => $imagesThisMonth,
                        'total_size' => $totalSize,
                        'total_size_bytes' => $totalSizeBytes,
                    ],
                    'services' => [
                        'total' => $totalServices,
                        'active' => $activeServices,
                        'inactive' => $inactiveServices,
                    ],
                    'subscriptions' => [
                        'total' => $totalSubscriptions,
                        'active' => $publishedSubscriptions,
                        'expired' => $draftSubscriptions,
                    ],
                    'customers' => [
                        'total' => $totalCustomers,
                        'active' => $activeCustomers,
                        'new_this_month' => $newCustomersThisMonth,
                    ],
                    'projects' => [
                        'total' => $totalProjects,
                        'active' => $activeProjects,
                        'completed' => $completedProjects,
                    ],
                    'pdfs' => [
                        'total' => $totalPDFs,
                    ],
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch dashboard statistics: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get time series statistics for charts
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function timeSeries(Request $request)
    {
        try {
            $period = $request->input('period', 'month'); // week, month, year

            $now = Carbon::now();
            $data = [];

            if ($period === 'week') {
                // Last 7 days
                $categories = [];
                $usersData = [];
                $blogsData = [];
                $galleryData = [];

                for ($i = 6; $i >= 0; $i--) {
                    $date = $now->copy()->subDays($i);
                    $dateStart = $date->copy()->startOfDay();
                    $dateEnd = $date->copy()->endOfDay();

                    $categories[] = $date->format('D'); // Mon, Tue, etc.

                    // Count users created up to this date
                    $usersData[] = User::where('created_at', '<=', $dateEnd)->count();
                    
                    // Count blogs created up to this date
                    $blogsData[] = Blog::where('created_at', '<=', $dateEnd)->count();
                    
                    // Count gallery images created up to this date
                    $galleryData[] = GalleryImage::where('created_at', '<=', $dateEnd)->count();
                }

                $data = [
                    'categories' => $categories,
                    'series' => [
                        ['name' => 'Users', 'data' => $usersData],
                        ['name' => 'Blogs', 'data' => $blogsData],
                        ['name' => 'Gallery', 'data' => $galleryData],
                    ]
                ];
            } elseif ($period === 'month') {
                // Last 12 months
                $categories = [];
                $usersData = [];
                $blogsData = [];
                $galleryData = [];

                for ($i = 11; $i >= 0; $i--) {
                    $date = $now->copy()->subMonths($i);
                    $monthStart = $date->copy()->startOfMonth();
                    $monthEnd = $date->copy()->endOfMonth();

                    $categories[] = $date->format('M'); // Jan, Feb, etc.

                    // Count users created up to end of this month
                    $usersData[] = User::where('created_at', '<=', $monthEnd)->count();
                    
                    // Count blogs created up to end of this month
                    $blogsData[] = Blog::where('created_at', '<=', $monthEnd)->count();
                    
                    // Count gallery images created up to end of this month
                    $galleryData[] = GalleryImage::where('created_at', '<=', $monthEnd)->count();
                }

                $data = [
                    'categories' => $categories,
                    'series' => [
                        ['name' => 'Users', 'data' => $usersData],
                        ['name' => 'Blogs', 'data' => $blogsData],
                        ['name' => 'Gallery', 'data' => $galleryData],
                    ]
                ];
            } else { // year
                // Last 12 months (same as month for now, but can be extended to years)
                $categories = [];
                $usersData = [];
                $blogsData = [];
                $galleryData = [];

                for ($i = 11; $i >= 0; $i--) {
                    $date = $now->copy()->subMonths($i);
                    $monthStart = $date->copy()->startOfMonth();
                    $monthEnd = $date->copy()->endOfMonth();

                    $categories[] = $date->format('M');

                    // Count users created up to end of this month
                    $usersData[] = User::where('created_at', '<=', $monthEnd)->count();
                    
                    // Count blogs created up to end of this month
                    $blogsData[] = Blog::where('created_at', '<=', $monthEnd)->count();
                    
                    // Count gallery images created up to end of this month
                    $galleryData[] = GalleryImage::where('created_at', '<=', $monthEnd)->count();
                }

                $data = [
                    'categories' => $categories,
                    'series' => [
                        ['name' => 'Users', 'data' => $usersData],
                        ['name' => 'Blogs', 'data' => $blogsData],
                        ['name' => 'Gallery', 'data' => $galleryData],
                    ]
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch time series data: ' . $e->getMessage()
            ], 500);
        }
    }
}

