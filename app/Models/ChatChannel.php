<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** A place customers write to: one WhatsApp number, one page's Messenger or comments, and so on. Set by the admin. */
class ChatChannel extends Model
{
    public const TYPES = [
        'whatsapp' => 'WhatsApp', 'messenger' => 'Messenger', 'comments' => 'Facebook comments', 'instagram' => 'Instagram',
        'telegram' => 'Telegram', 'tiktok' => 'TikTok', 'call' => 'Phone call', 'other' => 'Other',
    ];

    /** The order channel an order made from this chat gets (the exact chat stays on chat_channel_id). */
    public const ORDER_CHANNEL = ['whatsapp' => 'whatsapp', 'call' => 'phone'];

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
        return self::ORDER_CHANNEL[$this->type] ?? 'messenger';
    }
}
