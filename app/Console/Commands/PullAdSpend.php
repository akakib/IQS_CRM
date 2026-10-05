<?php

namespace App\Console\Commands;

use App\Services\Marketing\AdCostService;
use App\Services\Marketing\Drivers\AdsDriver;
use App\Services\Marketing\Drivers\FakeAdsDriver;
use App\Services\Marketing\Drivers\MetaAdsDriver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Pull daily ad spend, re-pulling the last few days (platforms correct them late), then re-cost FIFO. */
class PullAdSpend extends Command
{
    protected $signature = 'ads:pull-spend {--days=3}';

    protected $description = 'Pull daily ad spend for every active ad account';

    public function handle(AdCostService $costs): int
    {
        $failed = 0;
        foreach (DB::table('ad_accounts')->where('is_active', true)->get() as $account) {
            $driver = $this->driver($account);
            $today = now($account->timezone)->toDateString();
            $from = now($account->timezone)->subDays(max(1, (int) $this->option('days')) - 1)->toDateString();
            try {
                foreach ($driver->daily($account, $from, $today) as $date => $m) {
                    $costs->saveSpend($account->id, $date, $m, $driver->source());
                }
                $this->line("{$account->name}: {$from} to {$today} ({$driver->source()})");
            } catch (Throwable $e) {
                $failed++;
                report($e);
                $this->error("{$account->name}: {$e->getMessage()}");
            }
        }
        $costs->rebuild();

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** Google spend comes by CSV import until API access is approved; without a token Meta is fake too. */
    private function driver(object $account): AdsDriver
    {
        $token = config('services.meta.ads_token');

        return $account->platform === 'meta' && $token && $account->external_id
            ? new MetaAdsDriver($token)
            : new FakeAdsDriver;
    }
}
