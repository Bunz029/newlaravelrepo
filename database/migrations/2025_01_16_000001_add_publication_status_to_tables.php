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
        // Add publication status to maps table
        Schema::table('maps', function (Blueprint $table) {
            $table->boolean('is_published')->default(false)->after('is_active');
            $table->timestamp('published_at')->nullable()->after('is_published');
            $table->string('published_by')->nullable()->after('published_at');
        });

        // Add publication status to buildings table
        Schema::table('buildings', function (Blueprint $table) {
            $table->boolean('is_published')->default(false)->after('is_active');
            $table->timestamp('published_at')->nullable()->after('is_published');
            $table->string('published_by')->nullable()->after('published_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('maps', function (Blueprint $table) {
            $table->dropColumn(['is_published', 'published_at', 'published_by']);
        });

        Schema::table('buildings', function (Blueprint $table) {
            $table->dropColumn(['is_published', 'published_at', 'published_by']);
        });
    }
};
