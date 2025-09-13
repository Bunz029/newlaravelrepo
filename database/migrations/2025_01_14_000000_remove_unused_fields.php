<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Remove unused fields from employees table
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['email', 'position', 'department']);
        });

        // Remove unused fields from rooms table
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Add back the fields if needed
        Schema::table('employees', function (Blueprint $table) {
            $table->string('email')->unique()->after('employee_name');
            $table->string('position')->nullable()->after('email');
            $table->string('department')->nullable()->after('position');
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
        });
    }
};
