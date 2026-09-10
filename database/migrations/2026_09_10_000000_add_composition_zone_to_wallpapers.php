<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallpapers', function (Blueprint $table) {
            $table->string('composition_zone', 16)->nullable()->after('composition');
        });
        Schema::table('composition_proposals', function (Blueprint $table) {
            $table->string('composition_zone', 16)->nullable()->after('composition');
        });
    }

    public function down(): void
    {
        Schema::table('wallpapers', function (Blueprint $table) {
            $table->dropColumn('composition_zone');
        });
        Schema::table('composition_proposals', function (Blueprint $table) {
            $table->dropColumn('composition_zone');
        });
    }
};
