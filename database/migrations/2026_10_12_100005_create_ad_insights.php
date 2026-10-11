<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ad results below the account: one row per ad and day (or per ad and
 * report period when the export has no daily breakdown), from a Meta Ads
 * Manager CSV for now. Re-uploading the same ad and day overwrites it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_insights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_account_id')->constrained();
            $table->date('day');                                   // the day, or the report's first day
            $table->date('period_end')->nullable();                // set when the row covers several days
            $table->string('row_key', 40);                         // sha1 of the ids (or names): one ad
            $table->string('campaign_id', 40)->nullable();
            $table->string('campaign_name', 255);
            $table->string('adset_id', 40)->nullable();
            $table->string('adset_name', 255)->nullable();
            $table->string('ad_id', 40)->nullable();
            $table->string('ad_name', 255)->nullable();
            $table->decimal('spend_usd', 12, 2)->default(0);
            $table->decimal('results', 12, 2)->default(0);
            $table->string('result_type', 120)->nullable();        // Meta's "Result indicator", e.g. messaging conversations
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('reach')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('messages')->default(0);
            $table->unsignedInteger('purchases')->default(0);
            $table->decimal('purchase_value', 12, 2)->default(0);
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['ad_account_id', 'day', 'row_key']);
            $table->index(['day', 'ad_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_insights');
    }
};
