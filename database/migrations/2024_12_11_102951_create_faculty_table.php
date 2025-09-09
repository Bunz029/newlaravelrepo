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
    Schema::create('faculty', function (Blueprint $table) {
        $table->id();  // The primary key for faculty table
        $table->string('faculty_name');
        $table->string('email')->unique();
        $table->string('faculty_image')->nullable();
        $table->unsignedBigInteger('building_id'); // Foreign key to buildings table
        $table->foreign('building_id')->references('id')->on('buildings')->onDelete('cascade');
        $table->timestamps();
    });
}

public function down()
{
    Schema::dropIfExists('faculty');
}

};
