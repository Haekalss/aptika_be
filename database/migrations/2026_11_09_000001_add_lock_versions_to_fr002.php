<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['smki_fr002_headers', 'smki_fr002_risikos'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            // TiDB commits each ALTER independently, so a retry must tolerate the first ALTER succeeding.
            if (!Schema::hasColumn($table, 'lock_version')) {
                Schema::table($table, fn (Blueprint $schema) => $schema->unsignedInteger('lock_version')->default(1));
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasColumn($table, 'lock_version')) {
                Schema::table($table, fn (Blueprint $schema) => $schema->dropColumn('lock_version'));
            }
        }
    }
};
