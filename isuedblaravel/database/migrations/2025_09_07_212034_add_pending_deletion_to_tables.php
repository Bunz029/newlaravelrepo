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
        // Add pending_deletion column to buildings table
        Schema::table('buildings', function (Blueprint $table) {
            $table->boolean('pending_deletion')->default(false)->after('published_data');
        });
        
        // Add pending_deletion column to maps table
        Schema::table('maps', function (Blueprint $table) {
            $table->boolean('pending_deletion')->default(false)->after('published_data');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('buildings', function (Blueprint $table) {
            $table->dropColumn('pending_deletion');
        });
        
        Schema::table('maps', function (Blueprint $table) {
            $table->dropColumn('pending_deletion');
        });
    }
};
