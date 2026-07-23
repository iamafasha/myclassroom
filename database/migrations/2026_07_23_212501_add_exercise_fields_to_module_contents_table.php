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
        Schema::table('module_contents', function (Blueprint $table) {
            $table->boolean('is_exercise')->default(false)->after('is_completed');
            $table->text('submission_link')->nullable()->after('is_exercise');
            $table->string('submission_file_path')->nullable()->after('submission_link');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('module_contents', function (Blueprint $table) {
            $table->dropColumn(['is_exercise', 'submission_link', 'submission_file_path']);
        });
    }
};
