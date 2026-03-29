<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Show the dashboard
     */
    public function index()
    {
        $stats = [
            'total_users' => User::count(),
            'active_users' => User::active()->count(),
            'admin_users' => User::admin()->count(),
            'recent_registrations' => User::where('created_at', '>=', now()->subDays(30))->count(),
        ];

        $recentUsers = User::latest()->limit(5)->get();

        return view('dashboard.index', compact('stats', 'recentUsers'));
    }

    /**
     * Show analytics page
     */
    public function analytics()
    {
        $period = request()->get('period', '30');
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

        // Role distribution - get from employees table
        $roleDistribution = DB::table('employees as e')
            ->join('roles as r', 'e.role_id', '=', 'r.id')
            ->select('r.name as role', DB::raw('COUNT(*) as count'))
            ->whereNull('e.deleted_at')
            ->groupBy('r.name')
            ->get();

        return view('dashboard.analytics', compact('userRegistrations', 'roleDistribution', 'period'));
    }

    /**
     * Show users management page
     */
    public function users()
    {
        $query = User::query();

        // Search functionality
        if (request()->has('search')) {
            $search = request()->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        // Filter by role
        if (request()->has('role')) {
            $query->where('role', request()->get('role'));
        }

        // Filter by status
        if (request()->has('status')) {
            $query->where('status', request()->get('status'));
        }

        $users = $query->paginate(15);

        return view('dashboard.users', compact('users'));
    }

    /**
     * Show settings page
     */
    public function settings()
    {
        return view('dashboard.settings');
    }
}
