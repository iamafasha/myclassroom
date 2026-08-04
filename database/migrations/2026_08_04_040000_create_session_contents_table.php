<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A "Session" content block placed inside a module. It does not hold one shared
     * session: every student who opens it books their own mentor session against it.
     */
    public function up(): void
    {
        Schema::create('session_contents', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->default(30);
            // Off closes new bookings; sessions already booked stay untouched.
            $table->boolean('is_booking_enabled')->default(true);
            // Off keeps a student to one live session at a time on this block.
            $table->boolean('allow_multiple')->default(false);
            // Where the session happens, when the mentor uses the same room every time.
            $table->string('meeting_link')->nullable();
            // Times the mentor publishes up front. Picking one books instantly;
            // with none, the student sends a request and the mentor offers times back.
            $table->json('available_slots')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_contents');
    }
};
