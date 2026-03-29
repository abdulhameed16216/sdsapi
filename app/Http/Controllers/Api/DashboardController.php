<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Get dashboard statistics
     */
    public function getStats(): JsonResponse
    {
        $stats = [
            'users' => [
                'total' => User::count(),
                'active' => User::active()->count(),
                'inactive' => User::where('status', 'inactive')->count(),
                'new_this_month' => User::whereMonth('created_at', now()->month)->count(),
            ],
            'system' => [
                'uptime' => $this->getSystemUptime(),
                'version' => '1.0.0',
                'environment' => app()->environment(),
            ]
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    /**
     * Get analytics data
     */
    public function getAnalytics(Request $request): JsonResponse
    {
        $period = $request->get('period', '30'); // days
        $startDate = now()->subDays($period);

        // User registration analytics
        $userRegistrations = User::select(
            DB::raw('DATE(created_at) as date'),
            DB::raw('COUNT(*) as count')
        )
        ->where('created_at', '>=', $startDate)
        ->groupBy('date')
        ->orderBy('date')
        ->get();

        // User activity analytics
        $userActivity = User::select(
            DB::raw('DATE(last_login_at) as date'),
            DB::raw('COUNT(*) as count')
        )
        ->where('last_login_at', '>=', $startDate)
        ->groupBy('date')
        ->orderBy('date')
        ->get();

        // Role distribution - get from employees table
        $roleDistribution = DB::table('employees as e')
            ->join('roles as r', 'e.role_id', '=', 'r.id')
            ->select('r.name as role', DB::raw('COUNT(*) as count'))
            ->whereNull('e.deleted_at')
            ->groupBy('r.name')
            ->get();

        $analytics = [
            'user_registrations' => $userRegistrations,
            'user_activity' => $userActivity,
            'role_distribution' => $roleDistribution,
            'period' => $period,
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => now()->format('Y-m-d'),
        ];

        return response()->json([
            'success' => true,
            'data' => $analytics
        ]);
    }

    /**
     * Get recent activities
     */
    public function getRecentActivities(Request $request): JsonResponse
    {
        $limit = $request->get('limit', 10);

        // Recent user registrations
        $recentRegistrations = User::latest()
            ->limit($limit)
            ->get(['id', 'name', 'email', 'created_at']);

        // Recent logins
        $recentLogins = User::whereNotNull('last_login_at')
            ->orderBy('last_login_at', 'desc')
            ->limit($limit)
            ->get(['id', 'name', 'email', 'last_login_at']);

        $activities = [
            'recent_registrations' => $recentRegistrations,
            'recent_logins' => $recentLogins,
        ];

        return response()->json([
            'success' => true,
            'data' => $activities
        ]);
    }

    /**
     * Get system uptime
     */
    private function getSystemUptime(): string
    {
        try {
            if (PHP_OS_FAMILY === 'Windows') {
                $uptime = shell_exec('wmic os get lastbootuptime /value');
                // Parse Windows uptime (simplified)
                return 'System running';
            } else {
                $uptime = shell_exec('uptime -p');
                return trim($uptime) ?: 'System running';
            }
        } catch (\Exception $e) {
            return 'System running';
        }
    }
}
