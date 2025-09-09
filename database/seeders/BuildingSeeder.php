<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BuildingSeeder extends Seeder
{
    public function run()
    {
        DB::table('buildings')->insert([
            ['building_name' => 'CCSICT',
            'description' => 'Computer Science Building',
            'image_url' => 'images/ccsict.jpg',
            'latitude' => 16.7185,
            'longitude' => 121.6884,],

            ['building_name' => 'CAS',
            'description' => 'College of Computing Studies',
            'image_url' => 'images/cas.jpg',
            'latitude' => 16.7190,
            'longitude' => 121.6890,],
        ]);
    }
}
