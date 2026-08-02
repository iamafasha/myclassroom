<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('workspaceables', function (Blueprint $table) {
            $table->string('workspaceable_id')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workspaceables', function (Blueprint $table) {
            $table->unsignedBigInteger('workspaceable_id')->change();
        });
    }
};
