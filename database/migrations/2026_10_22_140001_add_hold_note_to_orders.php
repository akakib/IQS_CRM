<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Why an order is on hold, in the words of whoever held it (and when the customer will pay), shown to whoever opens it next. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('hold_note', 500)->nullable()->after('hold_expected_date');
            $table->foreignId('hold_note_by')->nullable()->after('hold_note')->constrained('users')->nullOnDelete();
            $table->timestamp('hold_note_at')->nullable()->after('hold_note_by');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropConstrainedForeignId('hold_note_by'));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['hold_note', 'hold_note_at']));
    }
};
