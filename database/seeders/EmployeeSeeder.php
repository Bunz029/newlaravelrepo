<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EmployeeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run()
    {
        DB::table('employees')->insert([
            [
                'employee_name' => 'Ms. Rena',
                'email' => 'renatecson@gmail.com',
                'contact_number' => '09123456789',
                'employee_image' => 'images/employees/maam_rena.png',
                'building_id' => 1, 
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'employee_name' => 'Mr. John Facun',
                'email' => 'johnfacun@gmail.com',
                'contact_number' => '09987654321',
                'employee_image' => 'images/employees/sir_facun.png',
                'building_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);        
    }
} 