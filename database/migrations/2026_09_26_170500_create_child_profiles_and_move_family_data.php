<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('child_profiles')) {
            Schema::create('child_profiles', function (Blueprint $table) {
                $table->id();
                $table->foreignId('parent_id')->nullable()->constrained('users')->cascadeOnDelete();
                $table->string('name', 16);
                $table->unsignedTinyInteger('age')->nullable();
                $table->string('avatar', 10)->default('🦊');
                $table->json('progress_data')->nullable();
                $table->unsignedInteger('total_stars')->default(0);
                $table->unsignedInteger('streak')->default(0);
                $table->unsignedInteger('level')->default(1);
                $table->string('equipped_sticker')->nullable();
                $table->string('equipped_costume')->nullable();
                $table->date('last_activity_date')->nullable();
                $table->unsignedTinyInteger('energy')->default(3);
                $table->timestamp('energy_reset_at')->nullable();
                $table->timestamps();
                $table->unique(['parent_id', 'name']);
            });
        }

        $parentsByChild = Schema::hasTable('parent_student')
            ? DB::table('parent_student')->orderBy('parent_id')->get()->groupBy('student_id')
            : collect();

        foreach (DB::table('users')->where('role', 'estudiante')->orderBy('id')->get() as $student) {
            $parentId = $parentsByChild->get($student->id)?->first()?->parent_id;
            DB::table('child_profiles')->insertOrIgnore([
                'id' => $student->id,
                'parent_id' => $parentId,
                'name' => $student->name,
                'avatar' => $student->avatar,
                'total_stars' => $student->total_stars,
                'streak' => $student->streak,
                'level' => $student->level,
                'equipped_sticker' => $student->equipped_sticker,
                'equipped_costume' => $student->equipped_costume,
                'last_activity_date' => $student->last_activity_date,
                'energy' => $student->energy ?? 3,
                'energy_reset_at' => $student->energy_reset_at,
                'progress_data' => json_encode(new stdClass()),
                'created_at' => $student->created_at,
                'updated_at' => $student->updated_at,
            ]);

            if ($parentId !== null) {
                DB::table('child_profiles')->where('id', $student->id)->whereNull('parent_id')
                    ->update(['parent_id' => $parentId]);
            }
        }

        foreach ([
            ['table' => 'progresses', 'owner_column' => Schema::hasColumn('progresses', 'child_profile_id') ? 'child_profile_id' : 'user_id'],
            ['table' => 'mission_completions', 'owner_column' => Schema::hasColumn('mission_completions', 'child_profile_id') ? 'child_profile_id' : 'user_id'],
            ['table' => 'subject_completions', 'owner_column' => Schema::hasColumn('subject_completions', 'child_profile_id') ? 'child_profile_id' : 'user_id'],
        ] as $reference) {
            $this->moveSoleChildAccountRecords($reference['table'], $reference['owner_column']);
        }

        $this->ensureForeignKey('activities', 'child_id', 'child_profiles');
        if (!Schema::hasIndex('activities', ['created_by', 'child_id'])) {
            Schema::table('activities', function (Blueprint $table) {
                $table->index(['created_by', 'child_id']);
            });
        }

        $this->migrateProfileReference('progresses', ['user_id', 'completed_at'], 'progresses_child_profile_id_completed_at_index');
        $this->migrateProfileReference('mission_completions', ['user_id', 'mission_key']);
        $this->migrateProfileReference('subject_completions', ['user_id', 'subject']);

        if (Schema::hasIndex('users', ['name'], 'unique')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropUnique(['name']);
            });
        }
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });

        $legacyChildIds = DB::table('users')->where('role', 'estudiante')->pluck('id');
        if (Schema::hasTable('personal_access_tokens')) {
            DB::table('personal_access_tokens')
                ->where('tokenable_type', 'App\\Models\\User')
                ->whereIn('tokenable_id', $legacyChildIds)
                ->delete();
        }
        DB::table('users')->whereIn('id', $legacyChildIds)->update([
            'role' => 'child_profile_legacy',
            'email' => null,
            'password' => null,
            'plan_key' => 'free',
            'subscription_status' => null,
            'subscription_ends_at' => null,
            'stripe_customer_id' => null,
            'stripe_subscription_id' => null,
        ]);
        foreach ($legacyChildIds as $legacyChildId) {
            DB::table('users')->where('id', $legacyChildId)->update([
                'name' => 'archived-child-' . $legacyChildId,
                'avatar' => '🦊',
                'total_stars' => 0,
                'streak' => 0,
                'level' => 1,
                'equipped_sticker' => null,
                'equipped_costume' => null,
                'last_activity_date' => null,
                'energy' => 3,
                'energy_reset_at' => null,
            ]);
        }
        if (Schema::hasTable('parent_student')) {
            DB::table('parent_student')->delete();
            Schema::drop('parent_student');
        }
    }

    private function migrateProfileReference(string $tableName, array $oldIndexColumns, ?string $newIndexName = null): void
    {
        if (Schema::hasColumn($tableName, 'user_id')) {
            $this->dropForeignKeyForColumn($tableName, 'user_id');
            if (Schema::hasIndex($tableName, $oldIndexColumns, 'unique')) {
                Schema::table($tableName, function (Blueprint $table) use ($oldIndexColumns) {
                    $table->dropUnique($oldIndexColumns);
                });
            } elseif ($oldIndexColumns === ['user_id', 'completed_at']
                && Schema::hasIndex($tableName, 'progresses_user_id_completed_at_index')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropIndex('progresses_user_id_completed_at_index');
                });
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->renameColumn('user_id', 'child_profile_id');
            });
        }

        $this->ensureForeignKey($tableName, 'child_profile_id', 'child_profiles');
        $indexColumns = array_replace($oldIndexColumns, [0 => 'child_profile_id']);
        $hasIndex = Schema::hasIndex($tableName, $indexColumns, $tableName === 'progresses' ? null : 'unique');
        if (!$hasIndex) {
            Schema::table($tableName, function (Blueprint $table) use ($indexColumns, $tableName, $newIndexName) {
                if ($tableName === 'progresses' && $newIndexName) {
                    $table->index($indexColumns, $newIndexName);
                } else {
                    $table->unique($indexColumns);
                }
            });
        }
    }

    private function ensureForeignKey(string $tableName, string $column, string $referencedTable): void
    {
        $foreignKey = collect(Schema::getForeignKeys($tableName))
            ->first(fn (array $key) => $key['columns'] === [$column]);

        if ($foreignKey && $foreignKey['foreign_table'] === $referencedTable) {
            return;
        }

        if ($foreignKey) {
            Schema::table($tableName, function (Blueprint $table) use ($foreignKey) {
                $table->dropForeign($foreignKey['columns']);
            });
        }

        Schema::table($tableName, function (Blueprint $table) use ($column, $referencedTable) {
            $table->foreign($column)->references('id')->on($referencedTable)->cascadeOnDelete();
        });
    }

    private function dropForeignKeyForColumn(string $tableName, string $column): void
    {
        $foreignKey = collect(Schema::getForeignKeys($tableName))
            ->first(fn (array $key) => $key['columns'] === [$column]);

        if (!$foreignKey) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($foreignKey) {
            $table->dropForeign($foreignKey['columns']);
        });
    }

    private function moveSoleChildAccountRecords(string $tableName, string $ownerColumn): void
    {
        $accountIds = DB::table($tableName)
            ->join('users', 'users.id', '=', $tableName . '.' . $ownerColumn)
            ->where('users.role', 'padre')
            ->distinct()
            ->pluck('users.id');

        foreach ($accountIds as $accountId) {
            $childIds = DB::table('child_profiles')->where('parent_id', $accountId)->pluck('id');
            if ($childIds->count() === 1) {
                DB::table($tableName)->where($ownerColumn, $accountId)
                    ->update([$ownerColumn => $childIds->first()]);
                continue;
            }

            throw new RuntimeException(
                "No se puede asociar de forma segura el progreso de la cuenta {$accountId} en {$tableName}: debe tener exactamente un perfil infantil."
            );
        }
    }

    public function down(): void
{
    Schema::create('parent_student', function (Blueprint $table) {
        $table->foreignId('parent_id')->constrained('users')->cascadeOnDelete();
        $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
        $table->primary(['parent_id', 'student_id']);
        $table->timestamps();
    });

    foreach (DB::table('child_profiles')->whereNotNull('parent_id')->get() as $child) {
        DB::table('parent_student')->insert([
            'parent_id' => $child->parent_id,
            'student_id' => $child->id,
            'created_at' => $child->created_at,
            'updated_at' => $child->updated_at,
        ]);
    }

    Schema::table('activities', function (Blueprint $table) {
        $table->dropForeign(['child_id']);
        $table->dropForeign(['created_by']);
        $table->dropIndex(['created_by', 'child_id']);
    });
    Schema::table('activities', function (Blueprint $table) {
        $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        $table->foreign('child_id')->references('id')->on('users')->cascadeOnDelete();
        $table->index(['created_by', 'child_id']);
    });

    Schema::table('progresses', function (Blueprint $table) {
        $table->dropForeign(['child_profile_id']);
        $table->dropIndex('progresses_child_profile_id_completed_at_index');
        $table->renameColumn('child_profile_id', 'user_id');
    });
    Schema::table('progresses', function (Blueprint $table) {
        $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        $table->index(['user_id', 'completed_at']);
    });

    Schema::table('mission_completions', function (Blueprint $table) {
        $table->dropForeign(['child_profile_id']);
        $table->dropUnique(['child_profile_id', 'mission_key']);
        $table->renameColumn('child_profile_id', 'user_id');
    });
    Schema::table('mission_completions', function (Blueprint $table) {
        $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        $table->unique(['user_id', 'mission_key']);
    });

    Schema::table('subject_completions', function (Blueprint $table) {
        $table->dropForeign(['child_profile_id']);
        $table->dropUnique(['child_profile_id', 'subject']);
        $table->renameColumn('child_profile_id', 'user_id');
    });
    Schema::table('subject_completions', function (Blueprint $table) {
        $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        $table->unique(['user_id', 'subject']);
    });

    foreach (DB::table('child_profiles')->get() as $child) {
        DB::table('users')->where('id', $child->id)->update([
            'role' => 'estudiante',
            'name' => $child->name,
            'email' => null,
            'password' => null,
            'avatar' => $child->avatar,
            'total_stars' => $child->total_stars,
            'streak' => $child->streak,
            'level' => $child->level,
            'equipped_sticker' => $child->equipped_sticker,
            'equipped_costume' => $child->equipped_costume,
            'last_activity_date' => $child->last_activity_date,
        ]);
    }

    Schema::dropIfExists('child_profiles');
}
};
