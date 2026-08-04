<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The date a learner is meant to start on a piece of content. Dates used to be shown
     * per module (from the module's created_at); they now live on the content itself so
     * they can be planned rather than just reflecting when the row was made.
     */
    public function up(): void
    {
        Schema::table('module_contents', function (Blueprint $table) {
            $table->date('study_at')->nullable()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('module_contents', function (Blueprint $table) {
            $table->dropColumn('study_at');
        });
    }
};
