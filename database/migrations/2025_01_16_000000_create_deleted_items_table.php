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
        Schema::create('deleted_items', function (Blueprint $table) {
            $table->id();
            $table->string('item_type'); // 'building' or 'map'
            $table->unsignedBigInteger('original_id'); // Original ID of the deleted item
            $table->json('item_data'); // Complete data of the deleted item
            $table->string('deleted_by')->nullable(); // Admin who deleted it
            $table->timestamp('deleted_at');
            $table->timestamps();
            
            $table->index(['item_type', 'original_id']);
            $table->index('deleted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deleted_items');
    }
};
