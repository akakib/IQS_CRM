<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Sales report > My list: the products and categories each person keeps an eye on. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_watch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10); // product | category
            $table->unsignedBigInteger('ref_id');
            $table->timestamp('created_at')->nullable();
            $table->unique(['user_id', 'kind', 'ref_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_watch_items');
    }
};
