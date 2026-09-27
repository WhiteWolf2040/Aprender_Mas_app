<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('plan_key')->default('free')->after('role');
            $table->unsignedInteger('energy')->default(5)->after('plan_key');
            $table->timestamp('energy_reset_at')->nullable()->after('energy');
            $table->string('stripe_customer_id')->nullable()->index();
            $table->string('stripe_subscription_id')->nullable()->index();
            $table->string('subscription_status')->nullable();
            $table->timestamp('subscription_ends_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'plan_key',
                'energy',
                'energy_reset_at',
                'stripe_customer_id',
                'stripe_subscription_id',
                'subscription_status',
                'subscription_ends_at',
            ]);
        });
    }
};
