<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'position')) {
                $table->string('position')->nullable()->after('email');
            }
            if (!Schema::hasColumn('users', 'phone')) {
                $table->string('phone')->nullable()->after('position');
            }
            if (!Schema::hasColumn('users', 'avatar')) {
                $table->longText('avatar')->nullable()->after('phone');
            }
        });

        // Sintaks MODIFY hanya valid di MySQL; lewati di SQLite (phpunit) agar
        // test suite tetap bisa berjalan. Kolom sudah longText nullable dari atas.
        if (Schema::hasColumn('users', 'avatar') && DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY avatar LONGTEXT NULL");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $colsToDrop = array_filter(['position', 'phone', 'avatar'], function ($col) {
                return Schema::hasColumn('users', $col);
            });
            if (!empty($colsToDrop)) {
                $table->dropColumn($colsToDrop);
            }
        });
    }
};
