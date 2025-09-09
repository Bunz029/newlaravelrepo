<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ActivityLog;
use Carbon\Carbon;

class ActivityLogSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $activities = [
            [
                'user_name' => 'Admin Vince',
                'action' => 'published',
                'target_type' => 'system',
                'target_id' => null,
                'target_name' => 'All Pending Changes',
                'details' => [
                    'maps_published' => 2,
                    'buildings_published' => 5,
                    'buildings_deleted' => 1,
                    'total_published' => 7,
                    'published_by' => 'Admin Vince'
                ],
                'ip_address' => '192.168.1.100',
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'created_at' => Carbon::now()->subHours(2),
                'updated_at' => Carbon::now()->subHours(2)
            ],
            [
                'user_name' => 'Admin Vince',
                'action' => 'published_building',
                'target_type' => 'building',
                'target_id' => 1,
                'target_name' => 'Library Building',
                'details' => [
                    'map_id' => 1,
                    'employee_count' => 3,
                    'published_by' => 'Admin Vince'
                ],
                'ip_address' => '192.168.1.100',
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'created_at' => Carbon::now()->subHours(3),
                'updated_at' => Carbon::now()->subHours(3)
            ],
            [
                'user_name' => 'Admin Vince',
                'action' => 'created',
                'target_type' => 'building',
                'target_id' => 2,
                'target_name' => 'New Admin Office',
                'details' => [
                    'map_id' => 1,
                    'employee_count' => 2,
                    'coordinates' => '(150, 200)',
                    'dimensions' => '80x60',
                    'description' => 'Modern administrative office with conference facilities',
                    'services' => 'WiFi, Air Conditioning, Conference Room',
                    'latitude' => 14.5995,
                    'longitude' => 120.9842,
                    'is_active' => true
                ],
                'ip_address' => '192.168.1.100',
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'created_at' => Carbon::now()->subHours(4),
                'updated_at' => Carbon::now()->subHours(4)
            ],
            [
                'user_name' => 'Admin Vince',
                'action' => 'updated',
                'target_type' => 'building',
                'target_id' => 3,
                'target_name' => 'Computer Lab',
                'details' => [
                    'map_id' => 1,
                    'employee_count' => 4,
                    'coordinates' => '(300, 150)',
                    'dimensions' => '120x80',
                    'description' => 'Advanced computer laboratory with 50 workstations',
                    'services' => 'WiFi, Air Conditioning, Projector, Network Access',
                    'latitude' => 14.6000,
                    'longitude' => 120.9850,
                    'is_active' => true
                ],
                'ip_address' => '192.168.1.100',
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'created_at' => Carbon::now()->subHours(5),
                'updated_at' => Carbon::now()->subHours(5)
            ],
            [
                'user_name' => 'Admin Vince',
                'action' => 'deleted',
                'target_type' => 'building',
                'target_id' => 4,
                'target_name' => 'Old Storage Room',
                'details' => [
                    'map_id' => 1,
                    'employee_count' => 0
                ],
                'ip_address' => '192.168.1.100',
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'created_at' => Carbon::now()->subHours(6),
                'updated_at' => Carbon::now()->subHours(6)
            ],
            [
                'user_name' => 'Admin Vince',
                'action' => 'published_deletion',
                'target_type' => 'building',
                'target_id' => 5,
                'target_name' => 'Conference Hall',
                'details' => [
                    'map_id' => 1,
                    'employee_count' => 1,
                    'published_by' => 'Admin Vince'
                ],
                'ip_address' => '192.168.1.100',
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'created_at' => Carbon::now()->subHours(7),
                'updated_at' => Carbon::now()->subHours(7)
            ],
            [
                'user_name' => 'Admin Vince',
                'action' => 'restored',
                'target_type' => 'building',
                'target_id' => 6,
                'target_name' => 'Meeting Room',
                'details' => [
                    'map_id' => 1,
                    'employee_count' => 2
                ],
                'ip_address' => '192.168.1.100',
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'created_at' => Carbon::now()->subHours(8),
                'updated_at' => Carbon::now()->subHours(8)
            ],
            [
                'user_name' => 'Admin Vince',
                'action' => 'published_restoration',
                'target_type' => 'building',
                'target_id' => 6,
                'target_name' => 'Meeting Room',
                'details' => [
                    'map_id' => 1,
                    'employee_count' => 2,
                    'published_by' => 'Admin Vince'
                ],
                'ip_address' => '192.168.1.100',
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'created_at' => Carbon::now()->subHours(9),
                'updated_at' => Carbon::now()->subHours(9)
            ]
        ];

        foreach ($activities as $activity) {
            ActivityLog::create($activity);
        }
    }
}
