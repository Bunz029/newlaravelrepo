<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('buildings', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->unsignedBigInteger('map_id')->nullable();
            $table->integer('x_coordinate')->nullable();
            $table->integer('y_coordinate')->nullable();
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->boolean('is_active')->default(true);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamps();
        });

        // Add foreign key constraint after both tables exist
        Schema::table('buildings', function (Blueprint $table) {
            $table->foreign('map_id')->references('id')->on('maps')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('buildings');
    }
}; 