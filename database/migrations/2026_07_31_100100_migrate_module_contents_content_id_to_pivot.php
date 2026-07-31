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
        DB::table('module_contents')
            ->whereNotNull('content_id')
            ->orderBy('id')
            ->select('id', 'content_id')
            ->chunkById(500, function ($moduleContents) {
                $now = now();
                $rows = $moduleContents->map(fn ($moduleContent) => [
                    'content_id' => $moduleContent->content_id,
                    'module_content_id' => $moduleContent->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                DB::table('content_module_content')->insert($rows);
            });

        Schema::table('module_contents', function (Blueprint $table) {
            $table->dropColumn('content_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('module_contents', function (Blueprint $table) {
            $table->foreignIdFor(\App\Models\Content::class)->nullable()->after('module_id')->constrained()->nullOnDelete();
        });

        DB::table('content_module_content')
            ->orderBy('id')
            ->select('module_content_id', 'content_id')
            ->chunkById(500, function ($pivots) {
                foreach ($pivots as $pivot) {
                    DB::table('module_contents')
                        ->where('id', $pivot->module_content_id)
                        ->update(['content_id' => $pivot->content_id]);
                }
            });
    }
};
