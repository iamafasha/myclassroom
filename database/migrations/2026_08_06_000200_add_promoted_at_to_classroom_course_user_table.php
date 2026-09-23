<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classroom_course_user', function (Blueprint $table) {
            if (! Schema::hasColumn('classroom_course_user', 'promoted_at')) {
                $table->timestamp('promoted_at')->nullable()->after('completed_by');
            }
            if (! Schema::hasColumn('classroom_course_user', 'promoted_by')) {
                $table->foreignId('promoted_by')->nullable()->after('promoted_at')->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('classroom_course_user', function (Blueprint $table) {
            if (Schema::hasColumn('classroom_course_user', 'promoted_by')) {
                $table->dropConstrainedForeignId('promoted_by');
            }
            if (Schema::hasColumn('classroom_course_user', 'promoted_at')) {
                $table->dropColumn('promoted_at');
            }
        });
    }
};
