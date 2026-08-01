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
        Schema::table('pdf_notes_contents', function (Blueprint $table) {
            $table->unsignedTinyInteger('start_percentage')->nullable()->after('start_position');
            $table->unsignedTinyInteger('end_percentage')->nullable()->after('end_position');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pdf_notes_contents', function (Blueprint $table) {
            $table->dropColumn(['start_percentage', 'end_percentage']);
        });
    }
};
