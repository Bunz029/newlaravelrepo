<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
{
    // Only run if the events table exists
    if (Schema::hasTable('events')) {
        Schema::table('events', function (Blueprint $table) {
            $table->string('image_url', 2048)->change();
        });
    }
}

public function down()
{
    // Only run if the events table exists
    if (Schema::hasTable('events')) {
        Schema::table('events', function (Blueprint $table) {
            $table->string('image_url', 255)->change(); // Revert to original length if needed
        });
    }
}
};
