<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ParentDashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $parent = $request->user();

        $children = $parent->children()->get()->map(function ($child) {
            $progress = $child->progress()->get();

            return [
                'id' => $child->id,
                'parent_id' => $child->parent_id,
                'name' => $child->name,
                'age' => $child->age,
                'avatar' => $child->avatar,
                'level' => $child->level,
                'total_stars' => $child->total_stars,
                'streak' => $child->streak,
                'activities_completed' => $progress->count(),
                'average_score' => round((float) $progress->avg(function ($item) {
                    return $item->total > 0 ? ($item->score / $item->total) * 100 : 0;
                })),
                'last_activity' => optional($progress->sortByDesc('completed_at')->first())->completed_at,
                'has_premium' => $child->hasActivePlan(),
            ];
        });

        return response()->json([
            'plan' => [
                'key' => $parent->plan_key,
                'status' => $parent->subscription_status,
                'ends_at' => $parent->subscription_ends_at,
                'children_limit' => 4,
                'children_count' => $children->count(),
            ],
            'children' => $children,
            'subjects' => Subject::whereNull('created_by')
                ->orWhere('created_by', $parent->id)
                ->orderBy('name')
                ->get(['id', 'name', 'icon']),
        ]);
    }
}
