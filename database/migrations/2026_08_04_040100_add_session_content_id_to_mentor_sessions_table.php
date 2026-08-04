<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ties a mentor session to the Session content block it was booked from.
     * Null for sessions requested straight from the Sessions page.
     */
    public function up(): void
    {
        Schema::table('mentor_sessions', function (Blueprint $table) {
            $table->foreignId('session_content_id')
                ->nullable()
                ->after('course_id')
                ->constrained('session_contents')
                ->nullOnDelete();

            $table->index(['session_content_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::table('mentor_sessions', function (Blueprint $table) {
            $table->dropIndex(['session_content_id', 'student_id']);
            $table->dropConstrainedForeignId('session_content_id');
        });
    }
};
