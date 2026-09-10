<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Role-based authorization: the first authorization primitive in the app.
 *
 * A string rather than an enum so adding a 'support' role later is a code
 * change, not a schema change. Existing rows take the 'user' default, so the
 * owner promotes themselves with `php artisan user:promote` after this runs —
 * nobody is an admin by accident.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 32)->default('user')->after('password')->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropColumn('role');
        });
    }
};
