<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smki_capacity_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bidang_id')->constrained('bidangs')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category', 10);
            $table->string('asset_name', 180);
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
            $table->unique(['bidang_id', 'category', 'asset_name'], 'fr026_asset_unique');
            $table->index(['bidang_id', 'category'], 'fr026_asset_scope_idx');
        });

        Schema::create('smki_capacity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('smki_capacity_assets')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            // Server metrics
            $table->decimal('current_storage_gb', 14, 2)->nullable();
            $table->decimal('current_memory_pct', 5, 2)->nullable();
            $table->decimal('current_cpu_pct', 5, 2)->nullable();
            $table->decimal('projection_storage_gb', 14, 2)->nullable();
            $table->decimal('projection_memory_pct', 5, 2)->nullable();
            $table->decimal('projection_cpu_pct', 5, 2)->nullable();
            // Network metrics
            $table->decimal('bandwidth_capacity_mbps', 12, 2)->nullable();
            $table->decimal('bandwidth_max_usage_mbps', 12, 2)->nullable();
            $table->decimal('sla_agreement_pct', 5, 2)->nullable();
            $table->decimal('sla_achievement_pct', 5, 2)->nullable();
            $table->string('notes', 1000)->nullable();
            $table->timestamps();
            $table->unique(['asset_id', 'year', 'month'], 'fr026_log_unique');
            $table->index(['year', 'month'], 'fr026_log_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smki_capacity_logs');
        Schema::dropIfExists('smki_capacity_assets');
    }
};
