<?php

namespace App\Services\Marketing\Drivers;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Meta Marketing API insights, one row per day (time_increment=1). */
class MetaAdsDriver implements AdsDriver
{
    public function __construct(private string $token, private string $version = 'v21.0') {}

    public function daily(object $account, string $from, string $to): array
    {
        $id = str_starts_with((string) $account->external_id, 'act_') ? $account->external_id : 'act_'.$account->external_id;
        $res = Http::timeout(30)->get("https://graph.facebook.com/{$this->version}/{$id}/insights", [
            'access_token' => $this->token,
            'time_increment' => 1,
            'time_range' => json_encode(['since' => $from, 'until' => $to]),
            'fields' => 'spend,impressions,clicks,actions,action_values',
            'limit' => 100,
        ]);
        if (! $res->successful()) {
            throw new RuntimeException('Meta insights failed: '.$res->json('error.message', $res->status()));
        }

        $out = [];
        foreach ($res->json('data', []) as $row) {
            $action = fn (string $list, string $type) => (float) (collect($row[$list] ?? [])->firstWhere('action_type', $type)['value'] ?? 0);
            $out[$row['date_start']] = [
                'spend_usd' => (float) ($row['spend'] ?? 0),
                'impressions' => (int) ($row['impressions'] ?? 0),
                'clicks' => (int) ($row['clicks'] ?? 0),
                'messages' => (int) $action('actions', 'onsite_conversion.messaging_conversation_started_7d'),
                'purchases' => (int) $action('actions', 'purchase'),
                'reported_value' => $action('action_values', 'purchase'),
            ];
        }

        return $out;
    }

    public function source(): string
    {
        return 'api';
    }
}
