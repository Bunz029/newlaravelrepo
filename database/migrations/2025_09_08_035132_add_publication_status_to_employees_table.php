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
        Schema::table('employees', function (Blueprint $table) {
            $table->boolean('is_published')->default(false)->after('building_id');
            $table->timestamp('published_at')->nullable()->after('is_published');
            $table->string('published_by')->nullable()->after('published_at');
            $table->json('published_data')->nullable()->after('published_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['is_published', 'published_at', 'published_by', 'published_data']);
        });
    }
};
