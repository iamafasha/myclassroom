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
        Schema::create('content_exercise_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('content_module_content_id')->constrained('content_module_content')->cascadeOnDelete();
            $table->text('submission_link')->nullable();
            $table->string('submission_file_path')->nullable();
            $table->string('score')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'content_module_content_id'], 'content_exercise_answers_user_pivot_unique');
        });

        Schema::table('content_module_content', function (Blueprint $table) {
            $table->dropColumn(['submission_link', 'submission_file_path', 'score']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('content_module_content', function (Blueprint $table) {
            $table->text('submission_link')->nullable()->after('is_exercise');
            $table->string('submission_file_path')->nullable()->after('submission_link');
            $table->string('score')->nullable()->after('submission_file_path');
        });

        Schema::dropIfExists('content_exercise_answers');
    }
};
