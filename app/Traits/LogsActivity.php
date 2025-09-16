<?php

namespace App\Traits;

use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

trait LogsActivity
{
    /**
     * Log an activity
     */
    protected function logActivity(
        string $action,
        ?string $targetType = null,
        ?int $targetId = null,
        ?string $targetName = null,
        ?array $details = null,
        ?string $userName = null
    ): void {
        $data = [
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'target_name' => $targetName,
            'details' => $details,
            'user_name' => $userName
        ];

        // Add user information if authenticated
        $request = request();
        if (Auth::check()) {
            $data['user_id'] = Auth::id();
            $data['user_name'] = $data['user_name'] ?? (Auth::user()->name ?? 'Admin User');
        } elseif (config('auth.guards.admin') && Auth::guard('admin')->check()) {
            $data['user_id'] = Auth::guard('admin')->id();
            $adminUser = Auth::guard('admin')->user();
            $data['user_name'] = $data['user_name'] ?? ($adminUser->name ?? $adminUser->email ?? 'Admin');
        } elseif ($request) {
            // Fallbacks from headers/body for SPA without session auth
            $headerName = $request->header('X-Admin-Name') ?: $request->header('X-User-Name');
            $headerId = $request->header('X-Admin-Id') ?: $request->header('X-User-Id');
            $inputName = $request->input('admin_name') ?: $request->input('user_name');
            $inputId = $request->input('admin_id') ?: $request->input('user_id');
            if ($headerName || $inputName) {
                $data['user_name'] = $headerName ?: $inputName;
            }
            if ($headerId || $inputId) {
                $data['user_id'] = $headerId ?: $inputId;
            }
            if (empty($data['user_name'])) {
                $data['user_name'] = 'System';
            }
        }

        // Add request information if available
        if (request()) {
            $data['ip_address'] = request()->ip();
            $data['user_agent'] = request()->userAgent();
        }

        try {
            ActivityLog::create($data);
        } catch (\Illuminate\Database\QueryException $e) {
            // Handle MySQL strict mode issues
            if (strpos($e->getMessage(), "Field 'id' doesn't have a default value") !== false) {
                // Remove id from data if it exists and try again
                unset($data['id']);
                ActivityLog::create($data);
            } else {
                throw $e;
            }
        }
    }

    /**
     * Log building activity
     */
    protected function logBuildingActivity(
        string $action,
        $building,
        ?array $details = null
    ): void {
        $this->logActivity(
            $action,
            'building',
            $building->id,
            $building->building_name ?? $building->name ?? "Building #{$building->id}",
            $details
        );
    }

    /**
     * Log map activity
     */
    protected function logMapActivity(
        string $action,
        $map,
        ?array $details = null
    ): void {
        $this->logActivity(
            $action,
            'map',
            $map->id,
            $map->name ?? "Map #{$map->id}",
            $details
        );
    }

    /**
     * Log user activity
     */
    protected function logUserActivity(
        string $action,
        $user = null,
        ?array $details = null
    ): void {
        $userName = $user ? ($user->name ?? $user->email ?? "User #{$user->id}") : 'Unknown User';
        
        $this->logActivity(
            $action,
            'user',
            $user?->id,
            $userName,
            $details
        );
    }

    /**
     * Log system activity
     */
    protected function logSystemActivity(
        string $action,
        ?string $description = null,
        ?array $details = null
    ): void {
        $this->logActivity(
            $action,
            'system',
            null,
            $description,
            $details
        );
    }

    /**
     * Build an enterprise-grade change set for Building updates.
     * Returns a structured array suitable for frontends to render exact diffs.
     *
     * @param array $beforeData   Scalar fields before update
     * @param array $afterData    Scalar fields after update
     * @param array $beforeEmployees Names/emails of employees before
     * @param array $afterEmployees  Names/emails of employees after
     */
    protected function buildBuildingChangeDetails(
        array $beforeData,
        array $afterData,
        array $beforeEmployees,
        array $afterEmployees
    ): array {
        // Compare all scalar fields dynamically (avoid volatile fields)
        $ignore = ['id', 'created_at', 'updated_at', 'published_at'];
        $keys = array_unique(array_merge(array_keys($beforeData), array_keys($afterData)));
        $changes = [];
        foreach ($keys as $field) {
            if (in_array($field, $ignore, true)) continue;
            // Skip any image-related fields from explicit changes (we will surface boolean flags in UI instead)
            if (stripos($field, 'image') !== false) continue;
            $before = $beforeData[$field] ?? null;
            $after = $afterData[$field] ?? null;
            if (is_array($before) || is_array($after)) continue; // handle arrays via specific sections below
            if ($before !== $after) {
                $changes[] = [
                    'field' => $field,
                    'old' => $before,
                    'new' => $after,
                ];
            }
        }

        // Services diff (string or array → normalize to array of trimmed strings)
        $normalizeServices = function ($value): array {
            if (is_array($value)) {
                return array_values(array_filter(array_map(function ($v) {
                    return trim((string) $v);
                }, $value)));
            }
            if (is_string($value)) {
                $parts = array_map('trim', explode(',', $value));
                return array_values(array_filter($parts, function ($v) { return $v !== ''; }));
            }
            return [];
        };

        $beforeServices = $normalizeServices($beforeData['services'] ?? []);
        $afterServices = $normalizeServices($afterData['services'] ?? []);
        $servicesAdded = array_values(array_diff($afterServices, $beforeServices));
        $servicesRemoved = array_values(array_diff($beforeServices, $afterServices));

        // Employees diff
        $normalizeEmployees = function (array $list): array {
            return array_values(array_filter(array_map(function ($v) {
                return trim((string) $v);
            }, $list), function ($v) { return $v !== ''; }));
        };
        $beforeEmployees = $normalizeEmployees($beforeEmployees);
        $afterEmployees = $normalizeEmployees($afterEmployees);

        $employeesAdded = array_values(array_diff($afterEmployees, $beforeEmployees));
        $employeesRemoved = array_values(array_diff($beforeEmployees, $afterEmployees));

        return [
            'before' => $beforeData,
            'after' => $afterData,
            'changes' => $changes,
            'services' => [
                'added' => $servicesAdded,
                'removed' => $servicesRemoved,
            ],
            'employees' => [
                'added' => $employeesAdded,
                'removed' => $employeesRemoved,
            ],
        ];
    }
}


