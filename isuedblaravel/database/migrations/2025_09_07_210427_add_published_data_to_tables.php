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
        // Add published_data column to buildings table
        Schema::table('buildings', function (Blueprint $table) {
            $table->json('published_data')->nullable()->after('is_published');
        });
        
        // Add published_data column to maps table
        Schema::table('maps', function (Blueprint $table) {
            $table->json('published_data')->nullable()->after('is_published');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('buildings', function (Blueprint $table) {
            $table->dropColumn('published_data');
        });
        
        Schema::table('maps', function (Blueprint $table) {
            $table->dropColumn('published_data');
        });
    }
};
