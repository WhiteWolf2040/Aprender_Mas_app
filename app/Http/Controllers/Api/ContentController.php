<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\ChildProfile;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ContentController extends Controller
{
    public function subjects(Request $request): JsonResponse
    {
        $user = $request->user();
        $ownerId = $user instanceof ChildProfile ? $user->parent_id : $user->id;
        $query = Subject::query();
        if ($user->role !== 'maestro') {
            $query->where(function ($subjects) use ($ownerId) {
                $subjects->whereNull('created_by')->orWhere('created_by', $ownerId);
            });
        }
        return response()->json($query->withCount('activities')->orderBy('name')->get());
    }

    public function activities(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Activity::with(['subject', 'child:id,name,avatar'])->latest();
        if ($user->role === 'padre') {
            abort_unless($user->hasActivePlan(), 403, 'Se requiere un plan activo para administrar actividades.');
            $query->where('created_by', $user->id);
        } elseif ($user instanceof ChildProfile) {
            $query->where(function ($activities) use ($user) {
                $activities->where(function ($globalActivities) {
                    $globalActivities->whereNull('created_by')->orWhereHas('creator', function ($creator) {
                        $creator->where('role', 'maestro');
                    });
                })->orWhere('child_id', $user->id);
            });
        } else {
            abort_unless($user->role === 'maestro', 403);
        }
        if ($request->filled('subject_id')) {
            $query->where('subject_id', $request->integer('subject_id'));
        }
        return response()->json($query->get());
    }

    public function storeSubject(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeSubjectManagement($user);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:subjects,name'],
            'description' => ['nullable', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:10'],
        ]);
        $data['created_by'] = $user->role === 'maestro' ? null : $user->id;
        $subject = Subject::create($data);
        return response()->json($subject, 201);
    }

    public function updateSubject(Request $request, Subject $subject): JsonResponse
    {
        $user = $request->user();
        $this->authorizeSubjectManagement($user);
        abort_unless($user->role === 'maestro' || $subject->created_by === $user->id, 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('subjects', 'name')->ignore($subject->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:10'],
        ]);
        $subject->update($data);
        return response()->json($subject);
    }

    public function destroySubject(Request $request, Subject $subject): JsonResponse
    {
        $user = $request->user();
        $this->authorizeSubjectManagement($user);
        abort_unless($user->role === 'maestro' || $subject->created_by === $user->id, 403);
        $subject->delete();
        return response()->json(['message' => 'Materia eliminada.']);
    }

    public function storeActivity(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $this->activityData($request);
        if ($user->role === 'padre') {
            $data = $this->prepareParentActivity($user, $data);
        } else {
            abort_unless($user->role === 'maestro', 403);
            unset($data['child_id']);
        }
        $activity = Activity::create($data);
        return response()->json($activity->load('subject'), 201);
    }

    public function storeParentActivityBatch(Request $request): JsonResponse
    {
        $parent = $request->user();
        abort_unless($parent->role === 'padre' && $parent->hasActivePlan(), 403, 'Se requiere un plan familiar activo para crear tareas.');
        $data = $request->validate([
            'activities' => ['required', 'array', 'min:1', 'max:5'],
            'activities.*' => ['required', 'array'],
        ]);

        $activities = DB::transaction(function () use ($data, $parent) {
            return collect($data['activities'])->map(function (array $activityInput) use ($parent) {
                $activityRequest = Request::create('/', 'POST', $activityInput);
                $activityData = $this->activityData($activityRequest);
                $activityData = $this->prepareParentActivity($parent, $activityData);

                return Activity::create($activityData)->load('subject', 'child:id,name,avatar');
            })->all();
        });

        return response()->json(['activities' => $activities], 201);
    }

    public function updateActivity(Request $request, Activity $activity): JsonResponse
    {
        $user = $request->user();
        $data = $this->activityData($request);
        if ($user->role === 'padre') {
            abort_unless($user->hasActivePlan(), 403, 'Se requiere un plan activo para administrar actividades.');
            abort_unless($activity->created_by === $user->id, 403);
            $data = $this->prepareParentActivity($user, $data);
        } else {
            abort_unless($user->role === 'maestro', 403);
            unset($data['child_id']);
        }
        $activity->update($data);
        return response()->json($activity->load('subject'));
    }

    public function destroyActivity(Request $request, Activity $activity): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user->role === 'maestro' || ($user->role === 'padre' && $user->hasActivePlan() && $activity->created_by === $user->id),
            403
        );
        $activity->delete();
        return response()->json(['message' => 'Actividad eliminada.']);
    }

    private function activityData(Request $request): array
    {
        $data = $request->validate([
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'child_id' => ['nullable', 'integer', 'exists:child_profiles,id'],
            'title' => ['required', 'string', 'max:150'],
            'type' => ['required', 'in:order,match,wordbuild,fillblank,tap,puzzle,crossword,wordsearch'],
            'prompt' => ['nullable', 'string'],
            'content' => ['required', 'array'],
        ]);

        $contentRules = match ($data['type']) {
            'order' => [
                'content.numbers' => ['required', 'array', 'min:2'],
                'content.numbers.*' => ['required', 'string', 'max:20'],
            ],
            'match' => [
                'content.pairs' => ['required', 'array', 'min:2'],
                'content.pairs.*.left' => ['required', 'string', 'max:100'],
                'content.pairs.*.right' => ['required', 'string', 'max:100'],
            ],
            'wordbuild', 'fillblank' => [
                'content.word' => ['required', 'string', 'max:20'],
            ],
            'tap' => [
                'content.options' => ['required', 'array', 'min:2'],
                'content.options.*' => ['required', 'string', 'max:100'],
                'content.answer' => ['required', 'string', 'max:100'],
            ],
            'puzzle', 'crossword' => [
                'content.words' => ['required', 'array', 'min:1', 'max:10'],
                'content.words.*' => ['required', 'string', 'max:20'],
            ],
            'wordsearch' => [
                'content.words' => ['required', 'array', 'min:1', 'max:10'],
                'content.words.*' => ['required', 'string', 'max:12'],
            ],
        };
        return [...$data, ...$request->validate($contentRules)];
    }

    private function authorizeSubjectManagement($user): void
    {
        abort_unless(
            $user->role === 'maestro' || ($user->role === 'padre' && $user->hasActivePlan()),
            403,
            'Se requiere una cuenta familiar Premium o una cuenta de maestro.'
        );
    }

    private function prepareParentActivity($parent, array $data): array
    {
        abort_unless($parent->hasActivePlan(), 403, 'Se requiere un plan activo para crear actividades.');
        abort_unless(
            !empty($data['child_id']) && $parent->children()->whereKey($data['child_id'])->exists(),
            422,
            'Selecciona un perfil infantil de tu cuenta.'
        );
        abort_unless(
            Subject::whereKey($data['subject_id'])->where(function ($subjects) use ($parent) {
                $subjects->whereNull('created_by')->orWhere('created_by', $parent->id);
            })->exists(),
            403,
            'Esta materia no pertenece a tu cuenta familiar.'
        );
        $data['created_by'] = $parent->id;

        return $data;
    }
}
