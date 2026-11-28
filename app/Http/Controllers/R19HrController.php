<?php
namespace App\Http\Controllers;

use App\Support\R19HrCompensation;
use App\Support\R19HrPresence;
use App\Support\R19HrWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class R19HrController extends Controller
{
    public function index(Request $request)
    {
        $today = now()->timezone(config('app.timezone', 'Asia/Damascus'))->format('Y-m-d');
        $data = $request->validate(['date' => 'sometimes|date_format:Y-m-d', 'month' => 'sometimes|date_format:Y-m']);
        $date = $data['date'] ?? $today; $month = $data['month'] ?? substr($date, 0, 7);
        abort_unless(R19HrWorkflow::validDate($date) && $date <= $today, 422, 'حدد تاريخ اليوم أو يوماً سابقاً.');
        return $this->ok(R19HrWorkflow::snapshot($request->user(), $date, $month));
    }

    public function presence(Request $request)
    {
        return $this->ok(R19HrPresence::today($request->user()))->header('Cache-Control', 'private, no-store');
    }

    public function compensation(Request $request, ?string $id = null)
    {
        return $this->ok(R19HrCompensation::show($request->user(), $this->compensationEmployeeId($request, $id)))->header('Cache-Control', 'private, no-store');
    }

    public function saveCompensation(Request $request, ?string $id = null)
    {
        return $this->ok(R19HrCompensation::save($request->user(), $this->compensationEmployeeId($request, $id), $request->all()))
            ->header('Cache-Control', 'private, no-store');
    }

    private function compensationEmployeeId(Request $request, ?string $id): string
    {
        return $id ?? Validator::make($request->query(), [
            'employeeId' => ['required', 'string', 'max:190'],
        ])->validate()['employeeId'];
    }

    public function show(Request $request, string $id) { return $this->ok(R19HrWorkflow::disciplinaryFor($request->user(), $id)); }
    public function store(Request $request) { return $this->ok(R19HrWorkflow::createDisciplinary($request->user(), $request->all()), 201); }
    public function decide(Request $request, string $id) { return $this->ok(R19HrWorkflow::decideDisciplinary($request->user(), $id, $request->all())); }
    public function overtime(Request $request, string $id) { return $this->ok(R19HrWorkflow::decideOvertime($request->user(), $id, $request->all())); }
    public function policy(Request $request) { return $this->ok(R19HrWorkflow::updatePolicy($request->user(), $request->all())); }
}
