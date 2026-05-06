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
        Schema::create('pdf_notes_contents', function (Blueprint $table) {
            $table->id();
            
            $table->string('name');
            $table->string('file_url');

            $table->string('start_position')->nullable();
            $table->string('end_position')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pdf_notes_contents');
    }
};
