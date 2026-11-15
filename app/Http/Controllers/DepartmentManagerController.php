<?php
namespace App\Http\Controllers;
use App\Support\DepartmentManagers;
use Illuminate\Http\Request;
final class DepartmentManagerController extends Controller
{
    public function update(Request $r) {
        $input = $r->validate(['department'=>'required|string|max:100', 'managerId'=>'present|nullable|string|max:190',
            'expectedManagerId'=>'present|nullable|string|max:190', 'requestId'=>'required|string|min:8|max:128']);
        $input['managerId'] = (string)($input['managerId'] ?? '');
        $input['expectedManagerId'] = (string)($input['expectedManagerId'] ?? '');
        return $this->ok(DepartmentManagers::assign($r->user(), $input));
    }
}
