<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Support\TaskIdReservations;
use Illuminate\Http\Request;

final class TaskIdReservationController extends Controller
{
    public function __invoke(Request $request)
    {
        $canReserve = Setting::readOnlySnapshot(fn() => TaskIdReservations::mayReserve($request->user()));
        abort_unless($canReserve, 403, 'لا تملك صلاحية إنشاء المهام.');
        $data = $request->validate(['count' => 'required|integer|min:1|max:500']);
        return $this->ok(TaskIdReservations::reserve($request->user(), (int)$data['count']))
            ->header('Cache-Control', 'private, no-store');
    }
}

