<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->string('icon', 10)->default('📚');
            $table->timestamps();
        });

        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('type', 50);
            $table->text('prompt')->nullable();
            $table->json('content')->nullable();
            $table->timestamps();
        });

        Schema::create('progresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('activity_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject', 50)->nullable();
            $table->unsignedInteger('score');
            $table->unsignedInteger('total')->default(1);
            $table->unsignedInteger('elapsed_seconds')->nullable();
            $table->timestamp('completed_at');
            $table->timestamps();
            $table->index(['user_id', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('progresses');
        Schema::dropIfExists('activities');
        Schema::dropIfExists('subjects');
    }
};
