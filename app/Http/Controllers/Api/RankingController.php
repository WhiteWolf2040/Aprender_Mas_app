<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Progress;
use App\Models\ChildProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RankingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ChildProfile::query()
            ->select(['id', 'name', 'avatar', 'level', 'total_stars'])
            ->orderByDesc('total_stars')
            ->orderByDesc('level')
            ->limit(100);

        return response()->json($query->get()->values());
    }

    public function progress(Request $request): JsonResponse
    {
        $progress = $request->user()->role === 'maestro'
            ? Progress::with(['child', 'activity.subject'])->latest()->paginate(50)
            : Progress::with(['activity.subject'])->where('child_profile_id', $request->user()->id)->latest()->paginate(50);
        return response()->json($progress);
    }
}
