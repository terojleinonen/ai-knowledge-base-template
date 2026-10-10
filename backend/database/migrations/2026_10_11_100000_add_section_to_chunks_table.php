<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chunks', function (Blueprint $table) {
            // Heading of the section a passage continues, when the passage doesn't start with it.
            $table->string('section')->nullable()->after('position');
        });
    }

    public function down(): void
    {
        Schema::table('chunks', function (Blueprint $table) {
            $table->dropColumn('section');
        });
    }
};
