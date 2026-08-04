<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->unsignedBigInteger('size')->nullable()->after('file_type')->comment('Size in bytes');
        });

        // Backfill from disk. Anything already missing stays null and sorts as unknown.
        DB::table('files')->orderBy('id')->chunk(200, function ($files) {
            foreach ($files as $file) {
                if ($file->file_path && Storage::disk('public')->exists($file->file_path)) {
                    DB::table('files')->where('id', $file->id)->update([
                        'size' => Storage::disk('public')->size($file->file_path),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropColumn('size');
        });
    }
};
