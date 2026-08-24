<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table): void {
            // Extends the existing flat city/state/country location with the
            // finer-grained levels the marketplace needs (Area, Locality),
            // without replacing the existing columns or breaking relations.
            $table->string('area', 100)->nullable()->after('city');
            $table->string('locality', 100)->nullable()->after('area');

            $table->index(['country', 'state', 'city', 'area']);
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table): void {
            $table->dropIndex(['country', 'state', 'city', 'area']);
            $table->dropColumn(['area', 'locality']);
        });
    }
};
