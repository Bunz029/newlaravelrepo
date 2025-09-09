<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FacultySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run()
    {
        DB::table('faculty')->insert([
            [
                'faculty_name' => 'Ms. Rena',
                'email' => 'renatecson@gmail.com',
                'faculty_image' => 'images/maam_rena.png',
                'building_id' => 1, // Replace 1 with the actual department ID
            ],
            [
                'faculty_name' => 'Mr. John Facun',
                'email' => 'johnfacun@gmail.com',
                'faculty_image' => 'images/sir_facun.png',
                'building_id' => 1, // Same department ID as Ms. Rena
            ],
        ]);        
        
    }
}
