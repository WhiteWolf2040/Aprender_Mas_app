<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MissionCompletion;
use App\Models\SubjectCompletion;
use App\Models\Progress;
use App\Models\Activity;
use App\Models\ChildProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProgressController extends Controller
{
    private const MISSION_REWARDS = [
        'match-animals' => 30,
        'tap-apples' => 25,
        'word-sol' => 40,
    ];

    public function profile(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    public function activity(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:50'],
            'score' => ['required', 'integer', 'min:0'],
            'total' => ['required', 'integer', 'min:1', 'max:100'],
            'mistakes' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'activity_id' => ['nullable', 'integer', 'exists:activities,id'],
            'elapsed_seconds' => ['nullable', 'integer', 'min:0'],
        ]);
        $data['mistakes'] = $data['mistakes'] ?? 0;
        if ($data['score'] > $data['total']) {
            throw ValidationException::withMessages([
                'score' => ['El puntaje no puede superar el total de preguntas.'],
            ]);
        }

        $user = $request->user();
        $baseStars = $data['score'] * 6 + ($data['score'] === $data['total'] ? 10 : 0);
        $starsDeducted = min($baseStars, intdiv($data['mistakes'], 5));
        $starsAwarded = $baseStars - $starsDeducted;
        if (!empty($data['activity_id'])) {
            $activity = Activity::findOrFail($data['activity_id']);
            $isAvailable = $user instanceof ChildProfile
                && $activity->child_id === $user->id
                && $activity->created_by === $user->parent_id;
            abort_unless($isAvailable, 403, 'Esta actividad no está asignada a tu perfil.');
        }
        if (!$user->consumeEnergy()) {
            return response()->json([
                'message' => 'Te quedaste sin energía. Revisa los planes para continuar jugando.',
                'code' => 'ENERGY_EXHAUSTED',
                'energy' => 0,
            ], 422);
        }

        DB::transaction(function () use ($user, $data, $starsAwarded) {
            Progress::create([
                'child_profile_id' => $user->id,
                'activity_id' => $data['activity_id'] ?? null,
                'subject' => $data['subject'],
                'score' => $data['score'],
                'total' => $data['total'],
                'elapsed_seconds' => $data['elapsed_seconds'] ?? null,
                'completed_at' => now(),
            ]);
            $user->increment('total_stars', $starsAwarded);
            $user->forceFill([
                'last_activity_date' => now()->toDateString(),
                'streak' => $user->last_activity_date?->isToday()
                    ? $user->streak
                    : ($user->last_activity_date?->isYesterday() ? $user->streak + 1 : 1),
            ])->save();

            SubjectCompletion::firstOrCreate([
                'child_profile_id' => $user->id,
                'subject' => $data['subject'],
            ]);
            if ($data['score'] > 0) {
                $user->increment('level', 2);
            }
        });

        $freshUser = $user->fresh();

        return response()->json([
            'user' => $this->userPayload($freshUser),
            'base_stars' => $baseStars,
            'stars_deducted' => $starsDeducted,
            'stars_awarded' => $starsAwarded,
            'mistakes' => $data['mistakes'],
            'unlimited_energy' => $freshUser->hasUnlimitedEnergy(),
        ]);
    }

    public function completedMissions(Request $request): JsonResponse
    {
        $missions = MissionCompletion::where('child_profile_id', $request->user()->id)
            ->pluck('mission_key');

        return response()->json(['missions' => $missions]);
    }

    public function mission(Request $request, string $mission): JsonResponse
    {
        abort_unless(isset(self::MISSION_REWARDS[$mission]), 404, 'Esta misión no existe.');
        $user = $request->user();
        $stars = self::MISSION_REWARDS[$mission];

        $completion = DB::transaction(function () use ($user, $mission, $stars) {
            $lockedChild = ChildProfile::query()->lockForUpdate()->findOrFail($user->id);
            $existing = MissionCompletion::where('child_profile_id', $user->id)
                ->where('mission_key', $mission)
                ->first();
            if ($existing) {
                return 0;
            }

            MissionCompletion::create([
                'child_profile_id' => $user->id,
                'mission_key' => $mission,
                'stars' => $stars,
            ]);
            $lockedChild->increment('total_stars', $stars);
            return $stars;
        });

        return response()->json([
            'completed' => $completion > 0,
            'stars_awarded' => $completion,
            'user' => $this->userPayload($user->fresh()),
        ]);
    }

    public function customize(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:avatar,sticker,accessories'],
        ]);
        $data = array_merge($data, $request->validate(match ($data['type']) {
            'avatar' => ['item' => ['required', 'string', 'in:fox,panda,lion,unicorn,robot,trex']],
            'sticker' => ['item' => ['nullable', 'string', 'in:rainbow,pizza,bolt,icecream,unicorn,ufo']],
            'accessories' => [
                'item' => ['present', 'array', 'max:2'],
                'item.*' => ['required', 'string', 'distinct', 'in:cap,glasses,scarf,crown'],
            ],
        }));
        $user = $request->user();

        if ($data['type'] === 'avatar') {
            $avatarLevels = ['fox' => 1, 'panda' => 1, 'lion' => 1, 'unicorn' => 4, 'robot' => 8, 'trex' => 12];
            $avatarEmojis = ['fox' => '🦊', 'panda' => '🐼', 'lion' => '🦁', 'unicorn' => '🦄', 'robot' => '🤖', 'trex' => '🦖'];
            $avatar = $data['item'];
            if (!isset($avatarLevels[$avatar]) || $user->level < $avatarLevels[$avatar]) {
                return response()->json(['message' => 'Este personaje todavía está bloqueado.'], 422);
            }
            $user->avatar = $avatarEmojis[$avatar];
            if (!in_array($user->equipped_costume, ['cap', 'glasses', 'scarf', 'crown'], true)) {
                $user->equipped_costume = null;
            }
        } elseif ($data['type'] === 'sticker') {
            $sticker = $data['item'];
            abort_unless($sticker === null || in_array('sticker:' . $sticker, $user->purchased_items ?? [], true), 422, 'Compra este sticker en la tienda antes de equiparlo.');
            $user->equipped_sticker = $sticker;
        } else {
            $accessories = $data['item'];
            if (!is_array($accessories) || count($accessories) > 2 || count($accessories) !== count(array_unique($accessories))) {
                throw ValidationException::withMessages(['item' => ['Elige hasta dos accesorios distintos.']]);
            }
            $validAccessories = ['cap', 'glasses', 'scarf', 'crown'];
            foreach ($accessories as $accessory) {
                if (!in_array($accessory, $validAccessories, true)
                    || !in_array('accessory:' . $accessory, $user->purchased_items ?? [], true)) {
                    return response()->json(['message' => 'Compra los accesorios en la tienda antes de equiparlos.'], 422);
                }
            }
            $user->equipped_accessories = array_values($accessories);
            $user->equipped_costume = $accessories[0] ?? null;
        }

        $user->save();

        return response()->json(['user' => $this->userPayload($user->fresh())]);
    }

    public function purchase(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:sticker,accessory'],
            'item' => ['required', 'string', 'max:30'],
        ]);
        $prices = [
            'sticker:rainbow' => 25,
            'sticker:pizza' => 25,
            'sticker:bolt' => 30,
            'sticker:icecream' => 120,
            'sticker:unicorn' => 150,
            'sticker:ufo' => 180,
            'accessory:cap' => 50,
            'accessory:glasses' => 75,
            'accessory:scarf' => 100,
            'accessory:crown' => 200,
        ];
        $itemKey = $data['type'] . ':' . $data['item'];
        $price = $prices[$itemKey] ?? null;
        if ($price === null) {
            throw ValidationException::withMessages(['item' => ['Este objeto no se puede comprar.']]);
        }

        $user = $request->user();
        abort_unless($user->hasActivePlan(), 403, 'La tienda requiere una suscripción Premium activa.');
        $alreadyOwned = false;
        DB::transaction(function () use ($user, $itemKey, $price, &$alreadyOwned) {
            $lockedChild = ChildProfile::query()->lockForUpdate()->findOrFail($user->id);
            $ownedItems = $lockedChild->purchased_items ?? [];
            if (in_array($itemKey, $ownedItems, true)) {
                $alreadyOwned = true;
                return;
            }
            if ($lockedChild->total_stars < $price) {
                throw ValidationException::withMessages([
                    'stars' => ["Necesitas {$price} estrellas para comprar este objeto."],
                ]);
            }

            $lockedChild->total_stars -= $price;
            $lockedChild->purchased_items = [...$ownedItems, $itemKey];
            $lockedChild->save();
        });

        $freshUser = $user->fresh();
        return response()->json([
            'message' => $alreadyOwned ? 'Ya tienes este objeto.' : 'Compra realizada.',
            'purchased' => !$alreadyOwned,
            'user' => $this->userPayload($freshUser),
        ]);
    }

    private function userPayload($user): array
    {
        $parent = $user instanceof ChildProfile ? $user->parent : null;

        return [
            ...$user->only([
            'id', 'name', 'role', 'avatar', 'total_stars', 'streak', 'level',
            'equipped_sticker', 'equipped_costume',
            'equipped_accessories', 'purchased_items',
            'age', 'energy', 'energy_reset_at',
            ]),
            'role' => 'estudiante',
            'plan_key' => $parent?->plan_key ?? 'free',
            'has_premium' => $user->hasActivePlan(),
        ];
    }
}
