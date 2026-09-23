<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-person state for one course inside one class. Being in a class used to mean
     * taking every course it teaches, all of them open-ended; a manager now excuses
     * someone from a single course, or signs off that they have finished it, without
     * touching anyone else or the other courses.
     *
     * A row exists only once a manager acts, so the default — enrolled and unfinished —
     * costs nothing.
     */
    public function up(): void
    {
        Schema::create('classroom_course_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('promoted_at')->nullable();
            $table->foreignId('promoted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['classroom_id', 'course_id', 'user_id'], 'classroom_course_user_unique');
            // Reading a course's visibility for one person is the hot path.
            $table->index(['user_id', 'course_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classroom_course_user');
    }
};
