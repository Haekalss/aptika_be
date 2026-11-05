<?php

namespace Tests\Feature;

use App\Models\Bidang;
use App\Models\SmkiCapacityAsset;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SmkiCapacityPlanningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Isolated in-memory database only; never migrate or clear the development database.
        config([
            'database.default' => 'fr026_testing',
            'database.connections.fr026_testing' => array_replace(config('database.connections.sqlite'), ['database' => ':memory:', 'url' => null]),
        ]);
        DB::purge('fr026_testing');
        Schema::create('bidangs', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role');
            $table->integer('bidang_id');
            $table->integer('is_active')->default(1);
            $table->string('position')->nullable();
            $table->timestamps();
        });
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->integer('parent_id')->nullable();
        });
        Schema::create('bidang_services', function (Blueprint $table) {
            $table->id();
            $table->integer('bidang_id');
            $table->integer('service_id');
            $table->boolean('is_enabled');
        });
        (require database_path('migrations/2026_11_03_000001_create_smki_capacity_planning_tables.php'))->up();
    }

    private function account(string $code = 'TEST', string $role = 'user'): User
    {
        $bidang = Bidang::firstOrCreate(['code' => $code], ['name' => 'Bidang '.$code]);

        return User::create([
            'name' => 'Pengguna '.$code, 'email' => uniqid().'@example.invalid', 'password' => 'test-only',
            'role' => $role, 'bidang_id' => $bidang->id, 'is_active' => 1,
        ]);
    }

    private function assetPayload(array $overrides = []): array
    {
        return array_replace([
            'category' => 'server',
            'asset_name' => 'SRV-APP-01',
            'ip_address' => '10.10.1.10',
        ], $overrides);
    }

    private function serverLog(array $overrides = []): array
    {
        return array_replace([
            'year' => 2026, 'month' => 10,
            'current_storage_gb' => 190, 'current_memory_pct' => 36, 'current_cpu_pct' => 24,
            'projection_storage_gb' => 270, 'projection_memory_pct' => 50, 'projection_cpu_pct' => 38,
        ], $overrides);
    }

    private function networkLog(array $overrides = []): array
    {
        return array_replace([
            'year' => 2026, 'month' => 10,
            'bandwidth_capacity_mbps' => 200, 'bandwidth_max_usage_mbps' => 140,
            'sla_agreement_pct' => 99.5, 'sla_achievement_pct' => 99.8,
            'notes' => 'Stabil',
        ], $overrides);
    }

    public function test_asset_creation_validation_and_unique_scope(): void
    {
        $this->actingAs($this->account(), 'sanctum');

        $serverId = $this->postJson('/api/smki/capacity-planning/assets', $this->assetPayload())
            ->assertCreated()
            ->assertJsonPath('data.category', 'server')
            ->assertJsonPath('data.asset_name', 'SRV-APP-01')
            ->assertJsonPath('data.logs', [])
            ->json('data.id');

        // The same name is allowed for another category, but not twice in the same category.
        $this->postJson('/api/smki/capacity-planning/assets', $this->assetPayload(['category' => 'network']))
            ->assertCreated();
        $this->postJson('/api/smki/capacity-planning/assets', $this->assetPayload())
            ->assertUnprocessable()->assertJsonValidationErrors(['asset_name']);

        $this->postJson('/api/smki/capacity-planning/assets', $this->assetPayload(['asset_name' => 'SRV-X', 'ip_address' => '999.1.1.1']))
            ->assertUnprocessable()->assertJsonValidationErrors(['ip_address']);
        $this->postJson('/api/smki/capacity-planning/assets', $this->assetPayload(['asset_name' => 'SRV-Y', 'ip_address' => '2001:db8::1']))
            ->assertCreated();

        $this->putJson("/api/smki/capacity-planning/assets/{$serverId}", $this->assetPayload(['asset_name' => 'SRV-APP-01-RENAMED']))
            ->assertOk()->assertJsonPath('data.asset_name', 'SRV-APP-01-RENAMED');
    }

    public function test_server_log_upsert_and_dashboard_aggregates(): void
    {
        $this->actingAs($this->account(), 'sanctum');
        $server = $this->postJson('/api/smki/capacity-planning/assets', $this->assetPayload())->json('data.id');
        $network = $this->postJson('/api/smki/capacity-planning/assets', $this->assetPayload(['category' => 'network', 'asset_name' => 'Internet International']))->json('data.id');

        $this->putJson("/api/smki/capacity-planning/assets/{$server}/logs", $this->serverLog())
            ->assertOk()
            ->assertJsonPath('data.current_storage_gb', 190)
            ->assertJsonPath('data.difference_storage_gb', 80);

        // One log per asset per month/year: a second save updates the same row.
        $this->putJson("/api/smki/capacity-planning/assets/{$server}/logs", $this->serverLog(['current_storage_gb' => 200]))
            ->assertOk()->assertJsonPath('data.current_storage_gb', 200);
        $this->assertDatabaseCount('smki_capacity_logs', 1);

        $dashboard = $this->getJson('/api/smki/capacity-planning?year=2026&month=10')->assertOk();
        $dashboard->assertJsonPath('data.meta.period_label', 'Oktober 2026')
            ->assertJsonPath('data.summary.total_assets', 2)
            ->assertJsonPath('data.summary.server_count', 1)
            ->assertJsonPath('data.summary.network_count', 1)
            ->assertJsonPath('data.summary.logs_this_month', 1)
            ->assertJsonPath('data.summary.month_completeness_pct', 50)
            ->assertJsonPath('data.summary.expected_to_date', 20)
            ->assertJsonPath('data.summary.logged_to_date', 1)
            ->assertJsonPath('data.summary.completeness_pct', 5)
            ->assertJsonPath('data.summary.monthly.9.logged', 1)
            ->assertJsonPath('data.summary.monthly.9.total', 2)
            ->assertJsonPath('data.summary.monthly.9.is_active', true)
            ->assertJsonCount(1, 'data.summary.missing_current_month')
            ->assertJsonPath('data.summary.missing_current_month.0.asset_id', $network)
            ->assertJsonPath('data.summary.server_comparison.0.asset_name', 'SRV-APP-01')
            ->assertJsonPath('data.summary.server_comparison.0.current_storage_gb', 200)
            ->assertJsonPath('data.summary.server_comparison.0.projection_storage_gb', 270)
            ->assertJsonPath('data.summary.server_comparison.0.difference_gb', 70)
            ->assertJsonPath('data.permissions.can_manage', true);

        // Year isolation: another year has no logs.
        $this->getJson('/api/smki/capacity-planning?year=2025&month=10')
            ->assertOk()->assertJsonPath('data.summary.annual_logs', 0)
            ->assertJsonPath('data.summary.completeness_pct', 0);
    }

    public function test_network_log_computes_usage_and_updates(): void
    {
        $this->actingAs($this->account(), 'sanctum');
        $network = $this->postJson('/api/smki/capacity-planning/assets', $this->assetPayload([
            'category' => 'network', 'asset_name' => 'Internet International', 'ip_address' => null,
        ]))->json('data.id');

        $this->putJson("/api/smki/capacity-planning/assets/{$network}/logs", $this->networkLog())
            ->assertOk()
            ->assertJsonPath('data.bandwidth_usage_pct', 70)
            ->assertJsonPath('data.notes', 'Stabil');

        $this->putJson("/api/smki/capacity-planning/assets/{$network}/logs", $this->networkLog([
            'bandwidth_capacity_mbps' => 500, 'bandwidth_max_usage_mbps' => 310, 'notes' => 'Normal',
        ]))->assertOk()->assertJsonPath('data.bandwidth_usage_pct', 62);
        $this->assertDatabaseCount('smki_capacity_logs', 1);

        $this->getJson('/api/smki/capacity-planning?year=2026&month=10')
            ->assertOk()
            ->assertJsonPath('data.summary.network_usage.0.asset_name', 'Internet International')
            ->assertJsonPath('data.summary.network_usage.0.capacity_mbps', 500)
            ->assertJsonPath('data.summary.network_usage.0.max_usage_mbps', 310)
            ->assertJsonPath('data.summary.network_usage.0.usage_pct', 62);
    }

    public function test_delete_asset_cascades_all_logs(): void
    {
        $this->actingAs($this->account(), 'sanctum');
        $asset = $this->postJson('/api/smki/capacity-planning/assets', $this->assetPayload())->json('data.id');
        $this->putJson("/api/smki/capacity-planning/assets/{$asset}/logs", $this->serverLog(['month' => 8]))->assertOk();
        $this->putJson("/api/smki/capacity-planning/assets/{$asset}/logs", $this->serverLog(['month' => 9]))->assertOk();
        $this->assertDatabaseCount('smki_capacity_logs', 2);

        $this->deleteJson("/api/smki/capacity-planning/assets/{$asset}")
            ->assertOk()->assertJsonPath('message', 'Aset dan seluruh log bulanannya berhasil dihapus.');

        $this->assertDatabaseCount('smki_capacity_assets', 0);
        $this->assertDatabaseCount('smki_capacity_logs', 0);
        $this->getJson('/api/smki/capacity-planning?year=2026&month=10')
            ->assertOk()->assertJsonPath('data.summary.total_assets', 0);
    }

    public function test_cross_bidang_isolation_and_admin_aptika_scope(): void
    {
        $owner = $this->account();
        $this->actingAs($owner, 'sanctum');
        $asset = $this->postJson('/api/smki/capacity-planning/assets', $this->assetPayload())->json('data.id');
        $this->putJson("/api/smki/capacity-planning/assets/{$asset}/logs", $this->serverLog())->assertOk();

        $other = $this->account('OTHER', 'admin');
        $this->actingAs($other, 'sanctum');
        $this->getJson('/api/smki/capacity-planning?year=2026&month=10')
            ->assertOk()->assertJsonPath('data.summary.total_assets', 0);
        $this->putJson("/api/smki/capacity-planning/assets/{$asset}/logs", $this->serverLog())->assertNotFound();
        $this->deleteJson("/api/smki/capacity-planning/assets/{$asset}")->assertNotFound();

        $admin = $this->account('APTIKA', 'admin');
        $this->actingAs($admin, 'sanctum');
        $this->getJson('/api/smki/capacity-planning?year=2026&month=10')
            ->assertOk()->assertJsonPath('data.summary.total_assets', 1)
            ->assertJsonPath('data.summary.server_comparison.0.asset_name', 'SRV-APP-01');
        $this->deleteJson("/api/smki/capacity-planning/assets/{$asset}")->assertOk();
    }

    public function test_log_validation_is_category_specific(): void
    {
        $this->actingAs($this->account(), 'sanctum');
        $server = $this->postJson('/api/smki/capacity-planning/assets', $this->assetPayload())->json('data.id');
        $network = $this->postJson('/api/smki/capacity-planning/assets', $this->assetPayload([
            'category' => 'network', 'asset_name' => 'Internet Domestic',
        ]))->json('data.id');

        $missingProjection = $this->serverLog();
        unset($missingProjection['projection_storage_gb']);
        $this->putJson("/api/smki/capacity-planning/assets/{$server}/logs", $missingProjection)
            ->assertUnprocessable()->assertJsonValidationErrors(['projection_storage_gb']);

        $this->putJson("/api/smki/capacity-planning/assets/{$server}/logs", $this->serverLog(['current_memory_pct' => 120]))
            ->assertUnprocessable()->assertJsonValidationErrors(['current_memory_pct']);
        $this->putJson("/api/smki/capacity-planning/assets/{$server}/logs", $this->serverLog(['year' => 1999]))
            ->assertUnprocessable()->assertJsonValidationErrors(['year']);

        $this->putJson("/api/smki/capacity-planning/assets/{$network}/logs", $this->networkLog(['bandwidth_capacity_mbps' => 0]))
            ->assertUnprocessable()->assertJsonValidationErrors(['bandwidth_capacity_mbps']);
        $this->putJson("/api/smki/capacity-planning/assets/{$network}/logs", $this->serverLog())
            ->assertUnprocessable()->assertJsonValidationErrors(['bandwidth_capacity_mbps']);

        $this->putJson("/api/smki/capacity-planning/assets/{$network}/logs", $this->networkLog())
            ->assertOk()->assertJsonPath('data.bandwidth_capacity_mbps', 200);

        // A category with existing logs cannot be switched.
        $this->putJson("/api/smki/capacity-planning/assets/{$network}", $this->assetPayload([
            'category' => 'server', 'asset_name' => 'Internet Domestic',
        ]))->assertUnprocessable();

        $this->assertDatabaseCount('smki_capacity_logs', 1);
    }

    public function test_export_data_returns_document_metadata_and_full_year_matrix(): void
    {
        $this->actingAs($this->account(), 'sanctum');
        $server = $this->postJson('/api/smki/capacity-planning/assets', $this->assetPayload())->json('data.id');
        $network = $this->postJson('/api/smki/capacity-planning/assets', $this->assetPayload([
            'category' => 'network', 'asset_name' => 'Internet International', 'ip_address' => null,
        ]))->json('data.id');
        $this->putJson("/api/smki/capacity-planning/assets/{$server}/logs", $this->serverLog())->assertOk();
        $this->putJson("/api/smki/capacity-planning/assets/{$network}/logs", $this->networkLog())->assertOk();

        $this->getJson('/api/smki/capacity-planning/export-data?year=2026&month=10')
            ->assertOk()
            ->assertJsonPath('data.meta.year', 2026)
            ->assertJsonPath('data.meta.documents.server.code', 'FR-026/KOM.03.05/SANDIKAMI')
            ->assertJsonPath('data.meta.documents.network.code', 'FR-029/KOM.03.05/SANDIKAMI')
            ->assertJsonPath('data.meta.documents.server.revision', '1.0')
            ->assertJsonPath('data.summary.annual_expected', 24)
            ->assertJsonPath('data.summary.annual_logs', 2)
            ->assertJsonCount(12, 'data.summary.monthly')
            ->assertJsonCount(2, 'data.assets')
            ->assertJsonCount(1, 'data.assets.0.logs');
    }

    public function test_service_and_account_access_guards(): void
    {
        $this->getJson('/api/smki/capacity-planning')->assertUnauthorized();

        $user = $this->account();
        $this->actingAs($user, 'sanctum');
        DB::table('services')->insert(['id' => 16, 'code' => 'SMKI', 'name' => 'SMKI']);
        DB::table('bidang_services')->insert(['bidang_id' => $user->bidang_id, 'service_id' => 16, 'is_enabled' => false]);
        $this->getJson('/api/smki/capacity-planning')->assertForbidden();

        DB::table('bidang_services')->update(['is_enabled' => true]);
        $this->getJson('/api/smki/capacity-planning?year=2026&month=10')->assertOk();

        $user->update(['is_active' => 0]);
        $this->getJson('/api/smki/capacity-planning')->assertForbidden();
    }

    public function test_visible_scope_model_helper(): void
    {
        $owner = $this->account();
        $other = $this->account('OTHER', 'admin');
        $admin = $this->account('APTIKA', 'admin');
        SmkiCapacityAsset::create(['bidang_id' => $owner->bidang_id, 'created_by' => $owner->id, 'category' => 'server', 'asset_name' => 'SRV-01']);
        SmkiCapacityAsset::create(['bidang_id' => $other->bidang_id, 'created_by' => $other->id, 'category' => 'network', 'asset_name' => 'NET-01']);

        $this->assertSame(1, SmkiCapacityAsset::query()->visibleTo($owner)->count());
        $this->assertSame(1, SmkiCapacityAsset::query()->visibleTo($other)->count());
        $this->assertSame(2, SmkiCapacityAsset::query()->visibleTo($admin)->count());
    }
}
