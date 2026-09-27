<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mission_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('mission_key', 100);
            $table->unsignedInteger('stars');
            $table->timestamps();
            $table->unique(['user_id', 'mission_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mission_completions');
    }
};
