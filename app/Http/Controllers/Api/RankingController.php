<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Progress;
use App\Models\ChildProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class RankingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $scope = $request->query('scope', 'global');
        if (!in_array($scope, ['global', 'family'], true)) {
            throw ValidationException::withMessages(['scope' => ['Elige un ranking válido.']]);
        }

        $query = ChildProfile::query()
            ->select(['id', 'name', 'avatar', 'level', 'total_stars'])
            ->orderByDesc('total_stars')
            ->orderByDesc('level');
        if ($scope === 'family') {
            $account = Auth::guard('sanctum')->user();
            abort_unless($account, 401, 'Inicia sesión para consultar el ranking familiar.');
            $parentId = $account instanceof ChildProfile ? $account->parent_id : $account->id;
            abort_unless($parentId, 403, 'El ranking familiar requiere una cuenta de padre o tutor.');
            $query->where('parent_id', $parentId);
        } else {
            $query->limit(100);
        }

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
