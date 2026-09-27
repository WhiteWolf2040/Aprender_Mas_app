<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('subject_id')->constrained('users')->nullOnDelete();
            $table->foreignId('child_id')->nullable()->after('created_by')->constrained('users')->cascadeOnDelete();
            $table->index(['created_by', 'child_id']);
        });

        foreach ([
            ['name' => 'Matemáticas', 'description' => 'Números y lógica', 'icon' => '🔢'],
            ['name' => 'Ciencias', 'description' => 'Naturaleza y descubrimientos', 'icon' => '🌱'],
            ['name' => 'Inglés', 'description' => 'Palabras en inglés', 'icon' => '🇬🇧'],
        ] as $subject) {
            DB::table('subjects')->updateOrInsert(
                ['name' => $subject['name']],
                [...$subject, 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropIndex(['created_by', 'child_id']);
            $table->dropConstrainedForeignId('child_id');
            $table->dropConstrainedForeignId('created_by');
        });
    }
};
