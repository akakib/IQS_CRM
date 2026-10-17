<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Website orders: when IQS sets the website order to Record verified, and why it could not (then a
 * button sends it again). Verification rules: all conditions or any one of them, and how much
 * advance a Hold for advance rule asks for (delivery charge, a fixed amount or a percent of the total).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('website_pushed_at')->nullable();
            $table->string('website_push_error', 255)->nullable();
        });
        Schema::table('verification_rules', function (Blueprint $table) {
            $table->string('match_mode', 3)->default('all');
            $table->string('advance_type', 10)->default('delivery');
            $table->decimal('advance_value', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['website_pushed_at', 'website_push_error']));
        Schema::table('verification_rules', fn (Blueprint $table) => $table->dropColumn(['match_mode', 'advance_type', 'advance_value']));
    }
};
