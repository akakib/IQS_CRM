<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chat mode: the admin's own channels (each WhatsApp number, each page's
 * Messenger and comments, Instagram...), who answers which, the time spent
 * in chat, and what happened there: messages answered, a count undone (with
 * a reason), a chat that ended without an order (with a reason). Orders made
 * from a chat remember the channel.
 */
return new class extends Migration
{
    private const OLD_TYPES = ['status', 'amendment', 'return', 'cancel', 'hold', 'refund', 'reassign', 'break'];

    public function up(): void
    {
        Schema::create('chat_channels', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('type', 20); // whatsapp, messenger, comments, instagram, telegram, tiktok, call, other
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('chat_channel_user', function (Blueprint $table) {
            $table->foreignId('chat_channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['chat_channel_id', 'user_id']);
        });

        Schema::create('chat_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->index(['user_id', 'started_at']);
        });

        Schema::create('chat_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('chat_channel_id')->constrained();
            $table->string('kind', 12); // message, undo, no_order
            $table->foreignId('reason_id')->nullable()->constrained('status_reasons')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
            $table->index(['chat_channel_id', 'created_at']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('chat_channel_id')->nullable()->after('channel')->constrained()->nullOnDelete();
        });

        Schema::table('status_reasons', function (Blueprint $table) {
            $table->enum('reason_type', [...self::OLD_TYPES, 'chat_lost', 'chat_undo'])->change();
        });
        $reasons = [
            ['chat_lost', 'price_high', 'Price too high'], ['chat_lost', 'delivery_charge', 'Delivery charge too high'],
            ['chat_lost', 'out_of_stock', 'Out of stock'], ['chat_lost', 'just_asking', 'Only asking'],
            ['chat_lost', 'no_reply', 'Stopped replying'], ['chat_lost', 'other', 'Other'],
            ['chat_undo', 'wrong_tap', 'Tapped by mistake'], ['chat_undo', 'same_customer', 'Same customer counted twice'],
            ['chat_undo', 'spam', 'Spam or not a customer'],
        ];
        foreach ($reasons as $i => [$type, $key, $label]) {
            if (! DB::table('status_reasons')->where('reason_type', $type)->where('system_key', $key)->exists()) {
                DB::table('status_reasons')->insert(['reason_type' => $type, 'system_key' => $key, 'label_en' => $label, 'is_active' => true,
                    'sort_order' => $i, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        DB::table('status_reasons')->whereIn('reason_type', ['chat_lost', 'chat_undo'])->delete();
        Schema::table('status_reasons', fn (Blueprint $table) => $table->enum('reason_type', self::OLD_TYPES)->change());
        Schema::table('orders', fn (Blueprint $table) => $table->dropConstrainedForeignId('chat_channel_id'));
        Schema::dropIfExists('chat_events');
        Schema::dropIfExists('chat_sessions');
        Schema::dropIfExists('chat_channel_user');
        Schema::dropIfExists('chat_channels');
    }
};
