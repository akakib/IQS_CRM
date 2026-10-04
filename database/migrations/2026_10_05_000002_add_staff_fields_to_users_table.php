<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 15)->nullable()->unique()->after('email');
            $table->enum('employment_type', ['onsite', 'remote', 'part_time'])->nullable()->after('phone');
            $table->foreignId('work_location_id')->nullable()->after('employment_type')->constrained('locations')->nullOnDelete();
            $table->string('telegram_user_id', 50)->nullable()->after('work_location_id');
            $table->boolean('is_active')->default(true)->after('telegram_user_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('work_location_id');
            $table->dropColumn(['phone', 'employment_type', 'telegram_user_id', 'is_active']);
        });
    }
};
