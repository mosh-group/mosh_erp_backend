<?php

namespace App\Http\Controllers;

use App\Models\{AuditEvent, Setting, Task, User};
use Illuminate\Http\Request;

final class MarketingTaskTrackingController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();
        $employeeId = (string)$user->employee_id;
        $marketing = 'التسويق';
        $managerId = (string)(Setting::valueOf('department-managers')[$marketing] ?? '');
        $isManager = $employeeId !== '' && $employeeId === $managerId && $user->manages($marketing);
        $isEmployee = User::departmentApp((string)$user->department) === 'marketing';
        abort_unless($user->active && $employeeId !== '' && ($isEmployee || $isManager)
            && $user->allows('marketing', 'view'), 403);

        $followUp = $request->boolean('followUp');
        if ($followUp) abort_unless($isManager, 403);
        $departments = $followUp ? [$marketing, 'السوشال ميديا'] : [$marketing];
        $previousAssignments = AuditEvent::query()
            ->select('record_id')
            ->where('resource', 'tasks')
            ->whereIn('before_data->department', $departments)
            ->when($followUp, fn ($query) => $query->where('after_data->department', 'المهمات'))
            ->when(!$isManager, fn ($query) => $query->where('before_data->assigneeId', $employeeId));

        $createdTasks = AuditEvent::query()
            ->select('record_id')
            ->where('resource', 'tasks')
            ->where('actor_id', $user->id)
            ->where('action', 'create');

        $tasks = Task::query()
            ->where(function ($query) use ($marketing, $employeeId, $isManager, $previousAssignments, $followUp, $departments, $createdTasks) {
                if ($followUp) {
                    $query->whereIn('data->followUpFromDepartment', $departments);
                } else {
                    $query->where(fn ($current) => $current->where('department', $marketing)
                        ->when(!$isManager, fn ($assigned) => $assigned->where('employee_id', $employeeId)));
                }
                $query->orWhereIn('id', $previousAssignments);
                if ($isManager) {
                    // Track the original task once, including team tasks stored in the general Tasks department.
                    $query->orWhere(fn ($created) => $created->whereNull('parent_id')->whereIn('id', $createdTasks));
                    $query->orWhere(fn ($shared) => $shared->whereNull('parent_id')
                        ->where('data->sharedDistributionRoot', true)
                        ->where('data->sharedOriginalDepartment', $marketing));
                }
            })
            ->whereNull('data->deletedAt')
            ->latest('updated_at')
            ->get(['id', 'department', 'status', 'data'])
            ->map(fn (Task $task) => [
                'id' => (string)$task->id,
                'title' => $task->data['title'] ?? '',
                'department' => $task->department,
                'status' => $task->status,
                'employeeState' => $task->data['employeeState'] ?? 'new',
            ] + ($followUp ? [
                'customer' => $task->data['customer'] ?? '',
                'assignee' => ($task->data['sharedDistributionMode'] ?? '') === 'team' ? 'فريق عمل' : ($task->data['assignee'] ?? ''),
            ] : []));

        return $this->ok(['tasks' => $tasks])->header('Cache-Control', 'private, no-store');
    }
}
