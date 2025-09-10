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
        Schema::table('buildings', function (Blueprint $table) {
            // Check if image_url column exists and rename it to image_path
            if (Schema::hasColumn('buildings', 'image_url')) {
                $table->renameColumn('image_url', 'image_path');
            }
            
            // Add modal_image_path if it doesn't exist
            if (!Schema::hasColumn('buildings', 'modal_image_path')) {
                $table->string('modal_image_path')->nullable()->after('image_path');
            }
            
            // Add services column as TEXT (not JSON) to match your code
            if (!Schema::hasColumn('buildings', 'services')) {
                $table->text('services')->nullable()->after('description');
            }
            
            if (!Schema::hasColumn('buildings', 'is_published')) {
                $table->boolean('is_published')->default(false)->after('is_active');
            }
            
            if (!Schema::hasColumn('buildings', 'published_data')) {
                $table->json('published_data')->nullable()->after('is_published');
            }
            
            if (!Schema::hasColumn('buildings', 'pending_deletion')) {
                $table->boolean('pending_deletion')->default(false)->after('published_data');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('buildings', function (Blueprint $table) {
            // Rename back if needed
            if (Schema::hasColumn('buildings', 'image_path')) {
                $table->renameColumn('image_path', 'image_url');
            }
            
            // Drop added columns
            $table->dropColumn([
                'modal_image_path',
                'services',
                'is_published',
                'published_data',
                'pending_deletion'
            ]);
        });
    }
};
