<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Invited people get a partial account — an email and nothing else — so they can be
     * attached to a class before they have signed up. They fill in the name and password
     * themselves when they register, keeping the classes they were invited to.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->nullable()->change();
            $table->string('password')->nullable()->change();
            $table->timestamp('invited_at')->nullable()->after('remember_token');
            $table->foreignId('invited_by')->nullable()->after('invited_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invited_by');
            $table->dropColumn('invited_at');
        });

        // Rows with no password can't be represented once the columns are required again.
        \Illuminate\Support\Facades\DB::table('users')->whereNull('password')->delete();

        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->nullable(false)->change();
            $table->string('password')->nullable(false)->change();
        });
    }
};
