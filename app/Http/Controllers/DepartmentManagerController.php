<?php
namespace App\Http\Controllers;
use App\Support\DepartmentManagers;
use Illuminate\Http\Request;
final class DepartmentManagerController extends Controller
{
    public function updateBackup(Request $request)
    {
        $input = $request->validate([
            'department'=>'required|string|max:100', 'managerId'=>'present|nullable|string|max:190',
            'expectedManagerId'=>'present|nullable|string|max:190', 'requestId'=>'required|string|min:8|max:128',
        ]);
        $input['managerId'] = (string)($input['managerId'] ?? '');
        $input['expectedManagerId'] = (string)($input['expectedManagerId'] ?? '');
        return $this->ok(DepartmentManagers::assignBackup($request->user(), $input));
    }

    public function update(Request $r) {
        $batch = $r->has('departments');
        $rules = [
            'managerId' => $batch ? 'required|string|max:190' : 'present|nullable|string|max:190',
            'requestId' => 'required|string|min:8|max:128',
        ];
        if ($batch) {
            $rules += [
                'departments' => 'required|array|list|min:1|max:50',
                'departments.*' => 'array:department,expectedManagerId',
                'departments.*.department' => 'required|string|max:100|distinct:strict',
                'departments.*.expectedManagerId' => 'present|nullable|string|max:190',
            ];
        } else {
            $rules += [
                'department' => 'required|string|max:100',
                'expectedManagerId' => 'present|nullable|string|max:190',
            ];
        }
        $input = $r->validate($rules);
        $input['managerId'] = (string)($input['managerId'] ?? '');
        if ($batch) {
            foreach ($input['departments'] as &$department) {
                $department['expectedManagerId'] = (string)($department['expectedManagerId'] ?? '');
            }
            unset($department);
        } else {
            $input['expectedManagerId'] = (string)($input['expectedManagerId'] ?? '');
        }
        return $this->ok(DepartmentManagers::assign($r->user(), $input));
    }
}
