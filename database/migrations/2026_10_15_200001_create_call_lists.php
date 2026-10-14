<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Call lists: a manager makes a list from an imported file (which customers,
 * what the call is for, who calls, how many a day each). The callers share
 * one pool: whoever presses Next call gets the next number, so nothing waits
 * on someone who is away. Every attempt is also a customer_calls row, so it
 * shows on the customer's journey and counts as work time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_lists', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('purpose', 20)->default('feedback'); // feedback, upsell, confirm, other
            $table->text('instructions')->nullable();           // what to say, shown to the caller
            $table->unsignedSmallInteger('daily_target')->default(50); // calls per person per day
            $table->foreignId('customer_import_id')->nullable()->constrained()->nullOnDelete();
            $table->json('filter')->nullable();                 // which parcels of the file: status, from, to
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('call_list_user', function (Blueprint $table) {
            $table->foreignId('call_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['call_list_id', 'user_id']);
        });

        Schema::create('call_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone', 15);
            $table->string('name', 150);
            $table->string('state', 12)->default('waiting');    // waiting, in_hand, later, done
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // has it now / finished it
            $table->timestamp('taken_at')->nullable();
            $table->timestamp('next_at')->nullable();           // later: call again from
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('outcome', 20)->nullable();          // last outcome
            $table->unsignedTinyInteger('satisfaction')->nullable(); // 0, 25, 50, 75, 100
            $table->json('topics')->nullable();                 // product/delivery/... => good|bad
            $table->string('note', 500)->nullable();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete(); // an order taken on the call
            $table->timestamp('done_at')->nullable();
            $table->timestamps();
            $table->unique(['call_list_id', 'phone']);
            $table->index(['call_list_id', 'state', 'next_at']);
            $table->index(['user_id', 'done_at']);
        });

        Schema::table('customer_calls', function (Blueprint $table) {
            $table->foreignId('call_list_item_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedTinyInteger('satisfaction')->nullable();
            $table->string('proof_path', 255)->nullable(); // the recording, or a call-log screenshot when nobody answered
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->timestamp('do_not_call_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('do_not_call_at'));
        Schema::table('customer_calls', function (Blueprint $table) {
            $table->dropConstrainedForeignId('call_list_item_id');
            $table->dropColumn(['ended_at', 'satisfaction', 'proof_path']);
        });
        Schema::dropIfExists('call_list_items');
        Schema::dropIfExists('call_list_user');
        Schema::dropIfExists('call_lists');
    }
};
