<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class DropPersonalAccessTokensTable extends Migration
{
    public function up()
    {
        Schema::dropIfExists('personal_access_tokens');
    }

    public function down()
    {
        // You can define the structure to recreate the table if needed
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tokenable_id'); // Use unsignedBigInteger to match users.id
            $table->string('tokenable_type'); // This will hold the type of the user (Admin or User)
            $table->string('name');
            $table->string('token');
            $table->text('abilities')->nullable();
            $table->timestamps();

            // Add the foreign key constraint for users
            $table->foreign('tokenable_id')->references('id')->on('users')->onDelete('cascade');
        });
    }
}
