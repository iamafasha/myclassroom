<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A class teaches its courses in an order the manager chooses, rather than
     * alphabetically. The order belongs to the pairing, not the course: the same
     * course can sit in several classes at different points in each syllabus.
     */
    public function up(): void
    {
        Schema::table('classroom_course', function (Blueprint $table) {
            $table->integer('sort_order')->default(0);
        });

        // Seed each class with its current alphabetical order, so nothing appears
        // to shuffle the first time a manager opens the page.
        $rows = DB::table('classroom_course')
            ->join('courses', 'courses.id', '=', 'classroom_course.course_id')
            ->orderBy('classroom_course.classroom_id')
            ->orderBy('courses.title')
            ->orderBy('classroom_course.id')
            ->select('classroom_course.id', 'classroom_course.classroom_id')
            ->get();

        $position = [];

        foreach ($rows as $row) {
            $next = $position[$row->classroom_id] ?? 0;

            DB::table('classroom_course')->where('id', $row->id)->update(['sort_order' => $next]);

            $position[$row->classroom_id] = $next + 1;
        }
    }

    public function down(): void
    {
        Schema::table('classroom_course', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};
