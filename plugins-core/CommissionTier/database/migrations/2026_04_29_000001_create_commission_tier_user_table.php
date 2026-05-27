<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_tier_user', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->primary();
            $table->unsignedSmallInteger('current_tier')->default(0);
            $table->unsignedBigInteger('current_amount')->default(0);
            $table->unsignedSmallInteger('peak_tier')->default(0);
            $table->unsignedBigInteger('peak_at')->nullable();
            $table->unsignedBigInteger('upgraded_at')->nullable();
            $table->unsignedBigInteger('last_demote_at')->nullable();
            $table->unsignedSmallInteger('below_threshold_streak_days')->default(0);
            $table->unsignedBigInteger('computed_at');

            $table->index('current_tier', 'idx_cti_current_tier');
            $table->index('peak_tier', 'idx_cti_peak_tier');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_tier_user');
    }
};
