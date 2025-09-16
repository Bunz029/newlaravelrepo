<?php

require_once 'vendor/autoload.php';

// Bootstrap Laravel
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Admin;
use Illuminate\Support\Facades\Hash;

try {
    // Check if admin exists
    $admin = Admin::where('email', 'admin@example.com')->first();
    
    if ($admin) {
        echo "Admin user found!\n";
        echo "ID: " . $admin->id . "\n";
        echo "Name: " . $admin->name . "\n";
        echo "Email: " . $admin->email . "\n";
        
        // Test password verification
        $passwordCheck = Hash::check('password123', $admin->password);
        echo "Password verification: " . ($passwordCheck ? "PASS" : "FAIL") . "\n";
        
        // Test token creation
        $token = $admin->createToken('test-token')->plainTextToken;
        echo "Token created successfully: " . substr($token, 0, 20) . "...\n";
        
        // Clean up test token
        $admin->tokens()->delete();
        echo "Test token cleaned up.\n";
        
    } else {
        echo "Admin user not found! Creating one...\n";
        
        $admin = Admin::create([
            'name' => 'Administrator',
            'email' => 'admin@example.com',
            'password' => Hash::make('password123'),
        ]);
        
        echo "Admin user created successfully!\n";
        echo "Email: admin@example.com\n";
        echo "Password: password123\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
}
