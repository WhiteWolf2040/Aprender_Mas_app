<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Progress;
use App\Models\Subject;
use App\Models\ChildProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $students = ChildProfile::query();
        $progress = Progress::query();

        $average = (float) ($progress
            ->selectRaw('COALESCE(AVG(score * 100.0 / NULLIF(total, 0)), 0) as average')
            ->value('average') ?? 0);

        return response()->json([
            'stats' => [
                'students' => (clone $students)->count(),
                'subjects' => Subject::count(),
                'completed_activities' => (clone $progress)->count(),
                'average_score' => round($average, 1),
                'total_stars' => (clone $students)->sum('total_stars'),
            ],
            'top_students' => (clone $students)
                ->select(['id', 'name', 'avatar', 'level', 'total_stars'])
                ->orderByDesc('total_stars')
                ->orderByDesc('level')
                ->limit(5)
                ->get(),
            'recent_progress' => Progress::with(['child:id,name,avatar', 'activity:id,title,subject_id'])
                ->latest('completed_at')
                ->limit(10)
                ->get(),
            'progress_by_subject' => Progress::query()
                ->select('subject', DB::raw('COUNT(*) as attempts'), DB::raw('ROUND(AVG(score * 100.0 / NULLIF(total, 0)), 1) as average_score'))
                ->groupBy('subject')
                ->orderByDesc('attempts')
                ->get(),
        ]);
    }
}
