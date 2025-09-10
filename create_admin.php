<?php

require_once 'vendor/autoload.php';

// Bootstrap Laravel
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Admin;
use Illuminate\Support\Facades\Hash;

try {
    // Create admin table if it doesn't exist
    if (!Schema::hasTable('admins')) {
        Schema::create('admins', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }

    // Create admin user
    $admin = Admin::firstOrCreate(
        ['email' => 'admin@example.com'],
        [
            'name' => 'Administrator',
            'password' => Hash::make('password123'),
        ]
    );

    echo "Admin created successfully!\n";
    echo "Email: admin@example.com\n";
    echo "Password: password123\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
