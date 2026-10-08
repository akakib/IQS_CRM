<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The order's channel is the platform it came from (Order::CHANNELS): a plain string, so a new
 * platform needs one line in code and no migration. Orders made from a chat get their chat's
 * platform (they had Messenger for TikTok, Instagram, Telegram and Facebook comments).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('channel', 20)->change();
        });

        $map = ['comments' => 'comments', 'instagram' => 'instagram', 'tiktok' => 'tiktok', 'telegram' => 'telegram', 'other' => 'other'];
        foreach ($map as $type => $channel) {
            DB::table('orders')->whereIn('chat_channel_id', DB::table('chat_channels')->where('type', $type)->select('id'))->update(['channel' => $channel]);
        }
    }

    public function down(): void
    {
        DB::table('orders')->whereIn('channel', ['comments', 'instagram', 'tiktok', 'telegram'])->update(['channel' => 'messenger']);
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('channel', ['web', 'messenger', 'whatsapp', 'phone', 'b2b', 'other'])->change();
        });
    }
};
