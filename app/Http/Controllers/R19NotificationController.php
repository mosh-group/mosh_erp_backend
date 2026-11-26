<?php
namespace App\Http\Controllers;

use App\Support\R19Notifications;
use Illuminate\Http\Request;

final class R19NotificationController extends Controller
{
    public function index(Request $request)
    {
        return \App\Models\Setting::readOnlySnapshot(fn () => $this->ok(R19Notifications::inbox($request->user())));
    }

    public function publishCircular(Request $request)
    {
        return $this->ok(R19Notifications::publishCircular($request->user(), $request->all()), 201);
    }

    public function acknowledge(Request $request, string $id)
    {
        return $this->ok(R19Notifications::acknowledge($request->user(), $id, $request->all()));
    }
}
