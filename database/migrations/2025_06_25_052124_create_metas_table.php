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

            
        Schema::dropIfExists('metas');


        Schema::create('metas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->morphs('metable');
            $table->string('key');
            $table->longText('value')->nullable();
            $table->string('type')->nullable();
            $table->timestamps();
        });
        
        Schema::table('metas', function (Blueprint $table) {
            $table->uuid('metable_id')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('metas');
    }
};
