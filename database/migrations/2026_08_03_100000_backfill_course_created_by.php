<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Courses that predate the `created_by` column have no owner, which would
     * leave them unmanageable. Hand them to the admin of the class they were
     * first added to.
     */
    public function up(): void
    {
        $owners = DB::table('classroom_course')
            ->join('classrooms', 'classrooms.id', '=', 'classroom_course.classroom_id')
            ->whereNotNull('classrooms.admin_id')
            ->orderBy('classroom_course.id')
            ->get(['classroom_course.course_id', 'classrooms.admin_id']);

        foreach ($owners as $owner) {
            DB::table('courses')
                ->where('id', $owner->course_id)
                ->whereNull('created_by')
                ->update(['created_by' => $owner->admin_id]);
        }
    }

    public function down(): void
    {
        // Ownership cannot be reliably un-inferred; nothing to reverse.
    }
};
