<?php

namespace App\Services\Smki;

use App\Models\SmkiCapacityAsset;
use App\Models\SmkiCapacityLog;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CapacityPlanningService
{
    private const MONTHS = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
        7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    private const SHORT_MONTHS = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
        7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des',
    ];

    public function dashboard(User $user, int $year, int $month): array
    {
        $assets = SmkiCapacityAsset::query()
            ->visibleTo($user)
            ->with(['logs' => fn ($query) => $query->where('year', $year)->orderBy('month')])
            ->orderBy('category')
            ->orderBy('asset_name')
            ->get();

        $assets->each(fn (SmkiCapacityAsset $asset) => $asset->logs->each->setRelation('asset', $asset));
        $logs = $assets->flatMap(fn (SmkiCapacityAsset $asset) => $asset->logs);
        $logsByCategory = $logs->groupBy(fn (SmkiCapacityLog $log) => $log->asset?->category ?? '');
        $total = $assets->count();
        $activeMonth = max(1, min(12, $month));

        $monthly = collect(range(1, 12))->map(function (int $number) use ($logs, $total, $activeMonth) {
            $logged = $logs->where('month', $number)->count();

            return [
                'month' => $number,
                'label' => self::SHORT_MONTHS[$number],
                'logged' => $logged,
                'total' => $total,
                'is_active' => $number === $activeMonth,
            ];
        })->all();

        $logsThisMonth = $logs->where('month', $activeMonth)->count();
        $expectedToDate = $total * $activeMonth;
        $loggedToDate = $logs->where('month', '<=', $activeMonth)->count();
        $annualExpected = $total * 12;
        $annualLogs = $logs->count();

        $missing = $assets
            ->filter(fn (SmkiCapacityAsset $asset) => ! $asset->logs->contains(fn (SmkiCapacityLog $log) => (int) $log->month === $activeMonth))
            ->map(fn (SmkiCapacityAsset $asset) => [
                'asset_id' => $asset->id,
                'asset_name' => $asset->asset_name,
                'category' => $asset->category,
                'ip_address' => $asset->ip_address,
            ])->values()->all();

        $serverComparison = $assets->where('category', 'server')->map(function (SmkiCapacityAsset $asset) use ($activeMonth) {
            $log = $asset->logs->firstWhere('month', $activeMonth);
            $current = $log?->current_storage_gb;
            $projection = $log?->projection_storage_gb;

            return [
                'asset_id' => $asset->id,
                'asset_name' => $asset->asset_name,
                'ip_address' => $asset->ip_address,
                'has_log' => (bool) $log,
                'current_storage_gb' => $current === null ? null : (float) $current,
                'projection_storage_gb' => $projection === null ? null : (float) $projection,
                'difference_gb' => $current === null || $projection === null ? null : round((float) $projection - (float) $current, 2),
            ];
        })->values()->all();

        $networkUsage = $assets->where('category', 'network')->map(function (SmkiCapacityAsset $asset) use ($activeMonth) {
            $log = $asset->logs->firstWhere('month', $activeMonth);
            $capacity = $log?->bandwidth_capacity_mbps;
            $usage = $log?->bandwidth_max_usage_mbps;
            $percentage = $capacity && $capacity > 0 && $usage !== null
                ? round(((float) $usage / (float) $capacity) * 100, 2)
                : null;

            return [
                'asset_id' => $asset->id,
                'asset_name' => $asset->asset_name,
                'ip_address' => $asset->ip_address,
                'has_log' => (bool) $log,
                'capacity_mbps' => $capacity === null ? null : (float) $capacity,
                'max_usage_mbps' => $usage === null ? null : (float) $usage,
                'usage_pct' => $percentage,
            ];
        })->values()->all();

        return [
            'meta' => [
                'year' => $year,
                'month' => $activeMonth,
                'period_label' => self::MONTHS[$activeMonth].' '.$year,
                'month_label' => self::MONTHS[$activeMonth],
                'short_month_label' => self::SHORT_MONTHS[$activeMonth],
                'months' => collect(self::SHORT_MONTHS)->map(fn (string $label, int $number) => [
                    'month' => $number, 'label' => $label,
                ])->values()->all(),
                'documents' => [
                    'server' => $this->documentMeta('server', $year, $logsByCategory->get('server', collect())),
                    'network' => $this->documentMeta('network', $year, $logsByCategory->get('network', collect())),
                ],
            ],
            'assets' => $assets->map(fn (SmkiCapacityAsset $asset) => $this->presentAsset($asset))->values()->all(),
            'summary' => [
                'total_assets' => $total,
                'server_count' => $assets->where('category', 'server')->count(),
                'network_count' => $assets->where('category', 'network')->count(),
                'logs_this_month' => $logsThisMonth,
                'month_completeness_pct' => $total ? round(($logsThisMonth / $total) * 100, 2) : 0,
                'expected_to_date' => $expectedToDate,
                'logged_to_date' => $loggedToDate,
                'completeness_pct' => $expectedToDate ? round(($loggedToDate / $expectedToDate) * 100, 2) : 0,
                'annual_expected' => $annualExpected,
                'annual_logs' => $annualLogs,
                'monthly' => $monthly,
                'missing_current_month' => $missing,
                'server_comparison' => $serverComparison,
                'network_usage' => $networkUsage,
            ],
            'permissions' => [
                'can_manage' => Gate::allows('create', SmkiCapacityAsset::class),
            ],
        ];
    }

    public function createAsset(User $user, array $data): SmkiCapacityAsset
    {
        return DB::transaction(fn () => SmkiCapacityAsset::create([
            'bidang_id' => $user->bidang_id,
            'created_by' => $user->id,
            'category' => $data['category'],
            'asset_name' => trim($data['asset_name']),
            'ip_address' => $data['ip_address'] ?? null,
        ]));
    }

    public function updateAsset(SmkiCapacityAsset $asset, array $data): SmkiCapacityAsset
    {
        return DB::transaction(function () use ($asset, $data) {
            $category = $data['category'];
            abort_if(
                $category !== $asset->category && $asset->logs()->exists(),
                422,
                'Kategori aset tidak dapat diubah karena aset sudah memiliki log bulanan.'
            );
            $asset->fill([
                'category' => $category,
                'asset_name' => trim($data['asset_name']),
                'ip_address' => $data['ip_address'] ?? null,
            ])->save();

            return $asset->refresh();
        });
    }

    public function deleteAsset(SmkiCapacityAsset $asset): void
    {
        DB::transaction(fn () => $asset->delete());
    }

    public function saveLog(SmkiCapacityAsset $asset, array $data): SmkiCapacityLog
    {
        $metrics = $asset->category === 'network'
            ? Arr::only($data, ['bandwidth_capacity_mbps', 'bandwidth_max_usage_mbps', 'sla_agreement_pct', 'sla_achievement_pct', 'notes'])
            : Arr::only($data, ['current_storage_gb', 'current_memory_pct', 'current_cpu_pct', 'projection_storage_gb', 'projection_memory_pct', 'projection_cpu_pct']);

        return DB::transaction(function () use ($asset, $data, $metrics) {
            $log = SmkiCapacityLog::query()->updateOrCreate(
                ['asset_id' => $asset->id, 'year' => (int) $data['year'], 'month' => (int) $data['month']],
                $metrics
            );

            return $log->fresh();
        });
    }

    public function presentAsset(SmkiCapacityAsset $asset): array
    {
        return [
            'id' => $asset->id,
            'category' => $asset->category,
            'asset_name' => $asset->asset_name,
            'ip_address' => $asset->ip_address,
            'logs' => $asset->logs->map(fn (SmkiCapacityLog $log) => $this->presentLog($log))->values()->all(),
            'logs_count' => $asset->logs->count(),
            'created_at' => $asset->created_at?->toISOString(),
            'updated_at' => $asset->updated_at?->toISOString(),
        ];
    }

    public function presentLog(SmkiCapacityLog $log): array
    {
        $usagePct = null;
        $difference = null;
        if ($log->bandwidth_capacity_mbps && $log->bandwidth_capacity_mbps > 0 && $log->bandwidth_max_usage_mbps !== null) {
            $usagePct = round(((float) $log->bandwidth_max_usage_mbps / (float) $log->bandwidth_capacity_mbps) * 100, 2);
        }
        if ($log->current_storage_gb !== null && $log->projection_storage_gb !== null) {
            $difference = round((float) $log->projection_storage_gb - (float) $log->current_storage_gb, 2);
        }

        return [
            'id' => $log->id,
            'year' => (int) $log->year,
            'month' => (int) $log->month,
            'current_storage_gb' => $log->current_storage_gb === null ? null : (float) $log->current_storage_gb,
            'current_memory_pct' => $log->current_memory_pct === null ? null : (float) $log->current_memory_pct,
            'current_cpu_pct' => $log->current_cpu_pct === null ? null : (float) $log->current_cpu_pct,
            'projection_storage_gb' => $log->projection_storage_gb === null ? null : (float) $log->projection_storage_gb,
            'projection_memory_pct' => $log->projection_memory_pct === null ? null : (float) $log->projection_memory_pct,
            'projection_cpu_pct' => $log->projection_cpu_pct === null ? null : (float) $log->projection_cpu_pct,
            'bandwidth_capacity_mbps' => $log->bandwidth_capacity_mbps === null ? null : (float) $log->bandwidth_capacity_mbps,
            'bandwidth_max_usage_mbps' => $log->bandwidth_max_usage_mbps === null ? null : (float) $log->bandwidth_max_usage_mbps,
            'bandwidth_usage_pct' => $usagePct,
            'sla_agreement_pct' => $log->sla_agreement_pct === null ? null : (float) $log->sla_agreement_pct,
            'sla_achievement_pct' => $log->sla_achievement_pct === null ? null : (float) $log->sla_achievement_pct,
            'notes' => $log->notes,
            'difference_storage_gb' => $difference,
            'created_at' => $log->created_at?->toISOString(),
            'updated_at' => $log->updated_at?->toISOString(),
        ];
    }

    private function documentMeta(string $category, int $year, Collection $logs): array
    {
        $created = $logs->pluck('created_at')->filter();
        $updated = $logs->pluck('updated_at')->filter();

        return [
            'code' => SmkiCapacityAsset::DOCUMENTS[$category],
            'revision' => SmkiCapacityAsset::TEMPLATE['revision'],
            'effective_date' => SmkiCapacityAsset::TEMPLATE['effective_date'],
            'year' => $year,
            'tanggal' => $created->isNotEmpty() ? $created->min()?->format('Y-m-d') : null,
            'last_update' => $updated->isNotEmpty() ? $updated->max()?->format('Y-m-d') : null,
        ];
    }
}
