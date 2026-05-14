<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subkit_plans', function (Blueprint $table) {
            $table->boolean('has_quantity')->default(false)->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('subkit_plans', function (Blueprint $table) {
            $table->dropColumn('has_quantity');
        });
    }
};
