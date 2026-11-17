<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A note written "For packer" on an order: kept in the history (note_type packing) and gathered on the
 * order (packer_note), so the packing list, the scan screen and the A4 sheet show it without extra queries.
 */
return new class extends Migration
{
    private const TYPES = ['manual', 'system', 'call', 'chat', 'rider', 'amendment', 'payment', 'status', 'courier', 'verification', 'assignment', 'scan'];

    public function up(): void
    {
        Schema::table('order_notes', function (Blueprint $table) {
            $table->enum('note_type', [...self::TYPES, 'packing'])->change();
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->text('packer_note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('packer_note');
        });
        DB::table('order_notes')->where('note_type', 'packing')->update(['note_type' => 'manual']);
        Schema::table('order_notes', function (Blueprint $table) {
            $table->enum('note_type', self::TYPES)->change();
        });
    }
};
