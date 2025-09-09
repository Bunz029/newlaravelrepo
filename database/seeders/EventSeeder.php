<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EventSeeder extends Seeder
{
    public function run()
    {
        DB::table('events')->insert([
            ['event_name' => 'Orientation', 'description' => 'Orientation for new students', 'event_date' => '2024-12-15'],
            ['event_name' => 'Graduation', 'description' => 'Graduation ceremony for seniors', 'event_date' => '2024-06-20'],
        ]);
    }
}
