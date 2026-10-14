<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Order history brought in from a CSV or Excel file (WooCommerce, Pathao...).
 * It only tells a customer's story: no stock, money, courier or points.
 * Templates remember how a file's columns map, keyed by its header row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('signature', 64)->unique(); // hash of the header row
            $table->json('mapping');                   // header => field
            $table->json('values')->nullable();        // status / medium words => meaning
            $table->string('default_medium', 20)->nullable();
            $table->timestamps();
        });

        Schema::create('customer_imports', function (Blueprint $table) {
            $table->id();
            $table->string('file_name', 200);
            $table->string('path', 255);               // parsed rows as JSON, deleted once imported
            $table->json('columns');                   // header, sample, distinct values
            $table->unsignedInteger('row_count');
            $table->string('source', 80)->nullable();  // template name, shown on the journey
            $table->json('mapping')->nullable();
            $table->json('values')->nullable();
            $table->json('result')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('customer_past_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_import_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 80);
            $table->string('external_no', 60)->nullable();
            $table->dateTime('ordered_at');
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('status', 12); // delivered, cancelled, returned, refunded, pending
            $table->string('medium', 20)->nullable(); // an order channel key
            $table->string('items', 1000)->nullable();
            $table->string('address', 500)->nullable();
            $table->timestamps();
            $table->unique(['source', 'external_no']);
            $table->index(['customer_id', 'ordered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_past_orders');
        Schema::dropIfExists('customer_imports');
        Schema::dropIfExists('import_templates');
    }
};
