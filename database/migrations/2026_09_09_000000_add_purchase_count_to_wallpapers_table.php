<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallpapers', function (Blueprint $table) {
            $table->unsignedInteger('purchase_count')->nullable()->after('prize_vnd');
        });
    }

    public function down(): void
    {
        Schema::table('wallpapers', function (Blueprint $table) {
            $table->dropColumn('purchase_count');
        });
    }
};
