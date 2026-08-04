<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Completion and quiz scores used to live on module_contents itself, so one person
     * ticking a lesson off marked it done for everyone in the class. They move here, one
     * row per person per lesson.
     */
    public function up(): void
    {
        Schema::create('module_content_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_content_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->string('quiz_score')->nullable();
            $table->timestamps();

            $table->unique(['module_content_id', 'user_id']);
        });

        // Existing progress can only be attributed to the person who built the course.
        $rows = DB::table('module_contents')
            ->join('modules', 'modules.id', '=', 'module_contents.module_id')
            ->join('courses', 'courses.id', '=', 'modules.course_id')
            ->whereNotNull('courses.created_by')
            ->where(function ($query) {
                $query->where('module_contents.is_completed', true)
                    ->orWhereNotNull('module_contents.score');
            })
            ->select([
                'module_contents.id as module_content_id',
                'module_contents.is_completed',
                'module_contents.score',
                'module_contents.updated_at',
                'courses.created_by as user_id',
            ])
            ->get();

        foreach ($rows as $row) {
            DB::table('module_content_user')->insert([
                'module_content_id' => $row->module_content_id,
                'user_id' => $row->user_id,
                'completed_at' => $row->is_completed ? ($row->updated_at ?? now()) : null,
                'quiz_score' => $row->score,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('module_contents', function (Blueprint $table) {
            $table->dropColumn(['is_completed', 'score']);
        });
    }

    public function down(): void
    {
        Schema::table('module_contents', function (Blueprint $table) {
            $table->boolean('is_completed')->default(false)->after('slug');
            $table->string('score')->nullable()->after('is_completed');
        });

        // Collapsing back to one flag per lesson: anyone finishing it marks it finished.
        $rows = DB::table('module_content_user')->get();

        foreach ($rows as $row) {
            DB::table('module_contents')->where('id', $row->module_content_id)->update([
                'is_completed' => $row->completed_at !== null,
                'score' => $row->quiz_score,
            ]);
        }

        Schema::dropIfExists('module_content_user');
    }
};
