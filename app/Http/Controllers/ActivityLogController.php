<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ActivityLogController extends Controller
{
    /**
     * Get activity logs with optional filtering
     */
    public function index(Request $request): JsonResponse
    {
        $query = ActivityLog::query()
            ->orderBy('created_at', 'desc');

        // Filter by action if provided
        if ($request->has('action') && $request->action) {
            $query->where('action', $request->action);
        }

        // Filter by target type if provided
        if ($request->has('target_type') && $request->target_type) {
            $query->where('target_type', $request->target_type);
        }

        // Filter by date range if provided
        if ($request->has('days') && $request->days) {
            $query->where('created_at', '>=', now()->subDays($request->days));
        }

        // Limit results if provided
        $limit = $request->get('limit', 50);
        $query->limit($limit);

        $activityLogs = $query->get();

        return response()->json($activityLogs);
    }

    /**
     * Create a new activity log entry
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => 'required|string|max:255',
            'target_type' => 'nullable|string|max:255',
            'target_id' => 'nullable|integer',
            'target_name' => 'nullable|string|max:255',
            'details' => 'nullable|array',
            'user_name' => 'nullable|string|max:255'
        ]);

        // Add user information if authenticated
        if (auth()->check()) {
            $validated['user_id'] = auth()->id();
            $validated['user_name'] = $validated['user_name'] ?? auth()->user()->name ?? 'Admin User';
        } else {
            $validated['user_name'] = $validated['user_name'] ?? 'System';
        }

        // Add request information
        $validated['ip_address'] = $request->ip();
        $validated['user_agent'] = $request->userAgent();

        $activityLog = ActivityLog::create($validated);

        return response()->json($activityLog, 201);
    }

    /**
     * Get activity log statistics
     */
    public function stats(): JsonResponse
    {
        $stats = [
            'total_activities' => ActivityLog::count(),
            'activities_today' => ActivityLog::whereDate('created_at', today())->count(),
            'activities_this_week' => ActivityLog::where('created_at', '>=', now()->startOfWeek())->count(),
            'activities_this_month' => ActivityLog::where('created_at', '>=', now()->startOfMonth())->count(),
            'top_actions' => ActivityLog::selectRaw('action, COUNT(*) as count')
                ->groupBy('action')
                ->orderBy('count', 'desc')
                ->limit(5)
                ->get(),
            'top_users' => ActivityLog::selectRaw('user_name, COUNT(*) as count')
                ->whereNotNull('user_name')
                ->groupBy('user_name')
                ->orderBy('count', 'desc')
                ->limit(5)
                ->get()
        ];

        return response()->json($stats);
    }

    /**
     * Clear old activity logs (older than specified days)
     */
    public function clear(Request $request): JsonResponse
    {
        $days = $request->get('days', 90); // Default to 90 days
        
        $deletedCount = ActivityLog::where('created_at', '<', now()->subDays($days))->delete();

        return response()->json([
            'message' => "Cleared {$deletedCount} activity logs older than {$days} days",
            'deleted_count' => $deletedCount
        ]);
    }
}


