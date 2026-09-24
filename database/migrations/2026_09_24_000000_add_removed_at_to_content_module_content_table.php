<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A removed block is hidden first and purged later, so the owner can undo a delete
 * without the block's exercise answers being lost to the cascade in between.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_module_content', function (Blueprint $table) {
            if (! Schema::hasColumn('content_module_content', 'removed_at')) {
                $table->timestamp('removed_at')->nullable()->after('is_exercise');
            }
        });
    }

    public function down(): void
    {
        Schema::table('content_module_content', function (Blueprint $table) {
            if (Schema::hasColumn('content_module_content', 'removed_at')) {
                $table->dropColumn('removed_at');
            }
        });
    }
};
