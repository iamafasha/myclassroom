<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One-to-one time between a student and the mentor who owns a course.
     * Named mentor_sessions because "sessions" belongs to the session driver.
     */
    public function up(): void
    {
        Schema::create('mentor_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            // Copied from the course owner at request time, so the request survives a course handover.
            $table->foreignId('mentor_id')->constrained('users')->cascadeOnDelete();
            $table->string('topic');
            $table->text('message')->nullable();
            $table->dateTime('preferred_at')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->default(30);
            $table->string('status')->default('pending');
            // The mentor's answer: a shortlist of times to choose from, and where to meet.
            $table->json('proposed_slots')->nullable();
            // The slot the student picked out of proposed_slots.
            $table->dateTime('scheduled_at')->nullable();
            $table->string('meeting_link')->nullable();
            $table->text('mentor_note')->nullable();
            $table->timestamps();

            $table->index(['mentor_id', 'status']);
            $table->index(['student_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mentor_sessions');
    }
};
