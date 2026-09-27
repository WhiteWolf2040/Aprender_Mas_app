<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChildProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ParentStudentController extends Controller
{
    public function createProfile(Request $request): JsonResponse
    {
        $parent = $request->user();
        abort_unless($parent->role === 'padre' && $parent->hasActivePlan(), 403, 'Se requiere un plan activo para crear perfiles infantiles.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:16', Rule::unique('child_profiles', 'name')->where('parent_id', $parent->id)],
            'age' => ['nullable', 'integer', 'min:2', 'max:18'],
            'avatar' => ['required', 'string', 'in:🦁,🐼,🦊'],
        ]);

        $child = DB::transaction(function () use ($parent, $data) {
            $lockedParent = $parent->newQuery()->lockForUpdate()->findOrFail($parent->id);
            abort_if($lockedParent->children()->count() >= 4, 422, 'El plan permite hasta 4 perfiles infantiles.');

            return ChildProfile::create([
                'parent_id' => $lockedParent->id,
                'name' => $data['name'],
                'age' => $data['age'] ?? null,
                'avatar' => $data['avatar'],
                'progress_data' => [],
                'energy' => 3,
                'energy_reset_at' => now()->addDay(),
            ]);
        });

        return response()->json([
            'message' => 'Perfil infantil creado.',
            'child' => [
                ...$child->only(['id', 'parent_id', 'name', 'age', 'avatar', 'level', 'total_stars', 'streak']),
                'has_premium' => $child->hasActivePlan(),
            ],
        ], 201);
    }
}
