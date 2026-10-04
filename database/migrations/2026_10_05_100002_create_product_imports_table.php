<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('external_id', 50)->nullable()->after('id')->index(); // WooCommerce product id
        });

        // One CSV import from the website, processed in small steps so it works
        // on shared hosting without a background worker.
        Schema::create('product_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('file_name', 255);
            $table->string('path', 255);
            $table->enum('status', ['pending', 'running', 'done', 'failed'])->default('pending');
            $table->unsignedBigInteger('offset')->default(0);      // byte position in the file
            $table->json('header')->nullable();
            $table->unsignedInteger('rows_done')->default(0);
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->json('errors')->nullable();                     // first 200 problems
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_imports');
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('external_id'));
    }
};
