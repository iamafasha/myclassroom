<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('content_module_content', function (Blueprint $table) {
            $table->boolean('is_exercise')->default(false)->after('sort_order');
            $table->text('submission_link')->nullable()->after('is_exercise');
            $table->string('submission_file_path')->nullable()->after('submission_link');
            $table->string('score')->nullable()->after('submission_file_path');
        });

        DB::table('module_contents')
            ->where('is_exercise', true)
            ->orderBy('id')
            ->select('id', 'is_exercise', 'submission_link', 'submission_file_path', 'score')
            ->chunkById(500, function ($moduleContents) {
                foreach ($moduleContents as $moduleContent) {
                    DB::table('content_module_content')
                        ->where('module_content_id', $moduleContent->id)
                        ->update([
                            'is_exercise' => $moduleContent->is_exercise,
                            'submission_link' => $moduleContent->submission_link,
                            'submission_file_path' => $moduleContent->submission_file_path,
                            'score' => $moduleContent->score,
                        ]);
                }
            });

        Schema::table('module_contents', function (Blueprint $table) {
            $table->dropColumn(['is_exercise', 'submission_link', 'submission_file_path']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('module_contents', function (Blueprint $table) {
            $table->boolean('is_exercise')->default(false)->after('is_completed');
            $table->text('submission_link')->nullable()->after('is_exercise');
            $table->string('submission_file_path')->nullable()->after('submission_link');
        });

        DB::table('content_module_content')
            ->where('is_exercise', true)
            ->orderBy('id')
            ->select('id', 'module_content_id', 'is_exercise', 'submission_link', 'submission_file_path', 'score')
            ->chunkById(500, function ($pivots) {
                foreach ($pivots as $pivot) {
                    DB::table('module_contents')
                        ->where('id', $pivot->module_content_id)
                        ->update([
                            'is_exercise' => $pivot->is_exercise,
                            'submission_link' => $pivot->submission_link,
                            'submission_file_path' => $pivot->submission_file_path,
                            'score' => $pivot->score,
                        ]);
                }
            });

        Schema::table('content_module_content', function (Blueprint $table) {
            $table->dropColumn(['is_exercise', 'submission_link', 'submission_file_path', 'score']);
        });
    }
};
