<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'plan_key')) {
                $table->string('plan_key')->default('free')->after('role');
            }
            if (!Schema::hasColumn('users', 'energy')) {
                $table->unsignedInteger('energy')->default(5)->after('plan_key');
            }
            if (!Schema::hasColumn('users', 'energy_reset_at')) {
                $table->timestamp('energy_reset_at')->nullable()->after('energy');
            }
            if (!Schema::hasColumn('users', 'stripe_customer_id')) {
                $table->string('stripe_customer_id')->nullable()->index();
            }
            if (!Schema::hasColumn('users', 'stripe_subscription_id')) {
                $table->string('stripe_subscription_id')->nullable()->index();
            }
            if (!Schema::hasColumn('users', 'subscription_status')) {
                $table->string('subscription_status')->nullable();
            }
            if (!Schema::hasColumn('users', 'subscription_ends_at')) {
                $table->timestamp('subscription_ends_at')->nullable();
            }
        });

    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $columns = [
                'plan_key',
                'energy',
                'energy_reset_at',
                'stripe_customer_id',
                'stripe_subscription_id',
                'subscription_status',
                'subscription_ends_at',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
