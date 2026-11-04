<?php

use App\Models\Order;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Ad or organic, and which ad: picked on orders made by hand, read from the website's attribution on website orders. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('ad_source', 20)->nullable()->after('source_campaign')->index();
            $table->string('ad_code', 100)->nullable()->after('ad_source');
        });

        // Website orders already in: from the attribution they came with.
        DB::table('orders')->where('channel', 'web')->orderBy('id')->select(['id', 'customer_id', 'utm'])->chunkById(500, function ($rows) {
            foreach ($rows as $o) {
                $utm = $o->utm ? json_decode($o->utm, true) : null;
                $earlier = DB::table('orders')->where('customer_id', $o->customer_id)->where('id', '<', $o->id)->exists();
                DB::table('orders')->where('id', $o->id)->update(['ad_source' => Order::sourceFromUtm(is_array($utm) ? $utm : null, $earlier)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['ad_source']);
            $table->dropColumn(['ad_source', 'ad_code']);
        });
    }
};
