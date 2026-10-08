<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A communication channel: a place customers write to (one WhatsApp number,
 * one page's Messenger or comments...) or the rider line, the number delivery
 * men call. Set by the admin; people get access per channel.
 */
class ChatChannel extends Model
{
    public const TYPES = [
        'whatsapp' => 'WhatsApp', 'messenger' => 'Messenger', 'comments' => 'Facebook comments', 'instagram' => 'Instagram',
        'telegram' => 'Telegram', 'tiktok' => 'TikTok', 'call' => 'Phone call', 'other' => 'Other',
        'rider' => 'Rider line (delivery men)',
    ];

    /** Not a chat: access to it means taking rider calls in Communication. */
    public const RIDER = 'rider';

    /** The order channel an order made from this chat gets: its platform (the exact chat stays on chat_channel_id). */
    public const ORDER_CHANNEL = ['call' => 'phone'];

    protected $fillable = ['name', 'type', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function orderChannel(): string
    {
        $channel = self::ORDER_CHANNEL[$this->type] ?? $this->type;

        return isset(Order::CHANNELS[$channel]) ? $channel : 'other';
    }
}
