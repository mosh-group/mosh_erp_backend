<?php

namespace App\Support;

use App\Models\{Setting, Task, User};
use Illuminate\Database\Eloquent\Builder;

final class TaskReadQuery
{
    public function __construct(private readonly bool $mysql)
    {
    }

    public static function forDatabase(): self
    {
        return new self(in_array((new Task)->getConnection()->getDriverName(), ['mysql', 'mariadb'], true));
    }

    public function visibleTo(User $user, array $filters = []): Builder
    {
        $query = $this->undeleted();
        $department = $this->scalar('department');
        $status = $this->scalar('status');

        if (($filters['view'] ?? null) === 'main') {
            $parent = $this->scalar('parentTaskId');
            $query->whereNull('parent_id')
                ->whereRaw("COALESCE($parent, '') IN ('', '0')")
                ->whereRaw("COALESCE($status, '') NOT IN (?, ?)", ['archived_invoiced', 'archived_no_invoice']);
        }

        if (($filters['scope'] ?? null) === 'mine') {
            // The explicit resource scope historically uses employeeId before assigneeId.
            $owner = $this->binary('COALESCE('.$this->scalar('employeeId').', '.$this->scalar('assigneeId').", '')");
            $query->whereRaw("$owner = ?", [(string)$user->employee_id]);
            if (!$user->employee_id) $query->whereRaw('1 = 0');
        } elseif (($filters['scope'] ?? null) === 'department') {
            $query->whereRaw($this->binary("COALESCE($department, '')").' = ?', [$user->department]);
        }

        foreach ($filters['filter'] ?? [] as $field => $value) {
            $query->whereRaw($this->binary('COALESCE('.$this->field($field).", '')").' = ?', [(string)$value]);
        }
        $date = 'COALESCE('.$this->scalar('date').', '.$this->scalar('createdDate').', SUBSTR('.$this->scalar('createdAt').", 1, 10), '')";
        if (!empty($filters['from'])) $query->whereRaw("$date >= ?", [$filters['from']]);
        if (!empty($filters['to'])) $query->whereRaw("$date <= ?", [$filters['to']]);

        if (!$user->active) return $query->whereRaw('1 = 0');
        $this->restrictAccess($query, $user, $department);

        if (!empty($filters['q'])) {
            $text = $this->mysql ? 'CAST(data AS CHAR CHARACTER SET utf8mb4)' : 'CAST(data AS TEXT)';
            $needle = mb_strtolower($filters['q']);
            $find = $this->mysql ? 'LOCATE(?, LOWER(%s) COLLATE utf8mb4_bin)' : 'INSTR(LOWER(%s), ?)';
            $query->where(function (Builder $search) use ($text, $needle, $find) {
                $search->whereRaw(sprintf($find, $text).' > 0', [$needle])
                    ->orWhereRaw(sprintf($find, 'id').' > 0', [$needle]);
                if (!$this->mysql) {
                    // SQLite may store escaped JSON. Search decoded keys and scalar values.
                    $search->orWhereExists(fn($tree) => $tree->selectRaw('1')->fromRaw('json_tree(tasks.data) AS task_search')
                        ->whereRaw('INSTR(LOWER(CAST(task_search.atom AS TEXT)), ?) > 0 OR INSTR(LOWER(CAST(task_search.key AS TEXT)), ?) > 0',
                            [$needle, $needle]));
                }
                else {
                    // MariaDB keeps JSON as text, so decode the main visible fields as well.
                    foreach (['taskNo', 'number', 'title', 'customer', 'department', 'assignee', 'description', 'notes', 'origin'] as $field) {
                        $search->orWhereRaw(sprintf($find, $this->scalar($field)).' > 0', [$needle]);
                    }
                }
            });
        }

        return $query;
    }

    private function restrictAccess(Builder $query, User $user, string $department): void
    {
        if ($user->root()) return;

        $scope = $user->taskScope();
        $owner = $this->binary('CASE WHEN '.$this->exists('assigneeId').' THEN '.$this->scalar('assigneeId')
            .' ELSE '.$this->scalar('employeeId').' END');
        $employee = (string)$user->employee_id;

        if ($scope === 'mine') {
            if ($employee === '') {
                $query->whereRaw('1 = 0');
                return;
            }
            $acting = DepartmentManagers::actingDepartments($user);
            $query->where(function (Builder $candidates) use ($acting, $department, $owner, $employee) {
                $candidates->whereRaw("$owner = ?", [$employee]);
                if ($acting) $candidates->orWhere(fn (Builder $cover) => $this->whereDepartments($cover, $department, $acting));
            });
            if (!$acting && empty($user->data['moduleOverrides'])) return;
        }

        if ($scope === 'department') {
            $managed = array_keys(array_filter(
                DepartmentManagers::effective(),
                fn($id) => $employee !== '' && (string)$id === $employee
            ));
            $departments = array_unique(array_merge([(string)$user->department], $managed));
            if (in_array('', $departments, true)) $departments[] = null;
            $query->where(function (Builder $candidates) use ($department, $departments, $owner, $employee) {
                $this->whereDepartments($candidates, $department, $departments);
                if ($employee !== '') $candidates->orWhereRaw("$owner = ?", [$employee]);
            });
        }

        // Department values (including custom legacy departments) use the same role
        // policy as task details. The query returns only the distinct keys, never documents.
        $values = (clone $query)->distinct()->toBase()
            ->selectRaw($this->binary($department).' AS access_department')->pluck('access_department');
        $all = [];
        $mine = [];
        foreach ($values as $value) {
            $access = RecordAccess::taskDepartmentAccess($user, $value);
            if ($access === 'all') $all[] = $value;
            if ($access === 'mine' && $employee !== '') $mine[] = $value;
        }
        if (!$all && !$mine) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->where(function (Builder $visible) use ($department, $all, $mine, $owner, $employee, $scope) {
            if ($all) $visible->where(fn(Builder $allRows) => $this->whereDepartments($allRows, $department, $all));
            if ($mine) {
                $visible->orWhere(function (Builder $ownRows) use ($department, $mine, $owner, $employee, $scope) {
                    if ($scope !== 'mine') $ownRows->whereRaw("$owner = ?", [$employee]);
                    $ownRows->where(fn(Builder $ownDepartments) => $this->whereDepartments($ownDepartments, $department, $mine));
                });
            }
        });
    }

    private function whereDepartments(Builder $query, string $department, array $values): void
    {
        $names = array_values(array_filter($values, fn($value) => $value !== null));
        if ($names) {
            $placeholders = implode(', ', array_fill(0, count($names), '?'));
            $query->whereRaw($this->binary($department)." IN ($placeholders)", $names);
        }
        if (in_array(null, $values, true)) $query->orWhereRaw("$department IS NULL");
    }

    public function ordered(Builder $query, array $filters = []): Builder
    {
        $urgent = $this->scalar('isUrgent');
        $legacyUrgent = $this->scalar('urgent');
        $priority = $this->scalar('priority');
        $query->orderByRaw("CASE WHEN ".$this->exists('isUrgent')." THEN COALESCE($urgent, '') NOT IN ('', '0')
            WHEN ".$this->exists('urgent')." THEN COALESCE($legacyUrgent, '') NOT IN ('', '0')
            ELSE COALESCE($priority, '') = 'urgent' END DESC");
        if (!empty($filters['sort'])) {
            $sort = 'COALESCE('.$this->field($filters['sort']).", '')";
            $order = ($filters['order'] ?? '') === 'desc' ? 'DESC' : 'ASC';
            $query->orderByRaw($this->binary($sort)." $order");
        }
        return $query->orderBy('created_at')->orderBy('id');
    }

    public function mine(User $user): array
    {
        return $this->documents($user, $this->ordered($this->visibleTo($user, ['scope' => 'mine']))->get());
    }

    public function summary(Builder $query): array
    {
        $status = $this->binary('COALESCE('.$this->scalar('status').", 'new')");
        $department = $this->binary('COALESCE('.$this->scalar('department').", '')");
        $due = $this->scalar('dueDate');
        $groups = $query->toBase()
            ->selectRaw("$status AS task_status, $department AS task_department, COUNT(*) AS task_count")
            ->selectRaw("SUM(CASE WHEN $status = 'in_progress' AND $due <> '' AND $due < ? THEN 1 ELSE 0 END) AS overdue",
                [now()->toDateString()])
            ->groupBy('task_status', 'task_department')->get();

        $summary = ['total' => 0, 'byStatus' => [], 'byDepartment' => [], 'overdue' => 0];
        foreach ($groups as $group) {
            $count = (int)$group->task_count;
            $summary['total'] += $count;
            $summary['overdue'] += (int)$group->overdue;
            $summary['byStatus'][$group->task_status] = ($summary['byStatus'][$group->task_status] ?? 0) + $count;
            $summary['byDepartment'][$group->task_department] = ($summary['byDepartment'][$group->task_department] ?? 0) + $count;
        }
        return $summary;
    }

    public function documents(User $user, iterable $rows): array
    {
        $documents = [];
        foreach ($rows as $row) $documents[] = $row->document();
        return RecordAccess::taskDocuments($user, $documents);
    }

    public function family(User $user, string $id): array
    {
        $selected = Task::findOrFail($id);
        abort_unless(empty($selected->data['deletedAt'])
            && RecordAccess::readable($user, 'tasks', $selected->document(), 'details'), 404);

        $root = $selected;
        $ancestors = [(string)$root->id => true];
        while (!empty($root->data['parentTaskId'])) {
            $parent = Task::find((string)$root->data['parentTaskId']);
            if (!$parent || isset($ancestors[(string)$parent->id])) break;
            $root = $parent;
            $ancestors[(string)$root->id] = true;
        }

        $family = [];
        $seen = [];
        $batch = [$root];
        while ($batch) {
            $parents = [];
            foreach ($batch as $row) {
                $rowId = (string)$row->id;
                if (isset($seen[$rowId])) continue;
                $seen[$rowId] = true;
                $parents[] = $rowId;
                if (empty($row->data['deletedAt']) && RecordAccess::readable($user, 'tasks', $row->document(), 'details')) {
                    $family[$rowId] = $row;
                }
            }

            $batch = [];
            foreach (array_chunk($parents, 200) as $parentIds) {
                $children = $this->undeleted()->where(function (Builder $query) use ($parentIds) {
                    $query->whereIn('parent_id', $parentIds)->orWhere(function (Builder $legacy) use ($parentIds) {
                        $legacy->whereNull('parent_id');
                        $placeholders = implode(', ', array_fill(0, count($parentIds), '?'));
                        $legacy->whereRaw($this->binary($this->scalar('parentTaskId'))." IN ($placeholders)", $parentIds);
                    });
                })->orderBy('created_at')->orderBy('id')->get();
                foreach ($children as $child) $batch[] = $child;
            }
        }

        $family[(string)$selected->id] = $selected;
        return $this->documents($user, $family);
    }

    private function undeleted(): Builder
    {
        $deleted = $this->scalar('deletedAt');
        return Task::query()->whereRaw("COALESCE($deleted, '') IN ('', '0')");
    }

    private function field(string $field): string
    {
        return $field === 'id' ? 'COALESCE('.$this->scalar($field).', id)' : $this->scalar($field);
    }

    private function scalar(string $field): string
    {
        $json = "JSON_EXTRACT(data, '$.\"$field\"')";
        if ($this->mysql) {
            return "CASE JSON_TYPE($json) WHEN 'NULL' THEN NULL WHEN 'BOOLEAN' THEN
                CASE JSON_UNQUOTE($json) WHEN 'true' THEN '1' ELSE '' END ELSE JSON_UNQUOTE($json) END";
        }
        return "CASE JSON_TYPE(data, '$.\"$field\"') WHEN 'false' THEN '' ELSE CAST($json AS TEXT) END";
    }

    private function exists(string $field): string
    {
        $path = "'$.\"$field\"'";
        return $this->mysql ? "JSON_CONTAINS_PATH(data, 'one', $path)" : "JSON_TYPE(data, $path) IS NOT NULL";
    }

    private function binary(string $expression): string
    {
        return $this->mysql ? "CAST($expression AS BINARY)" : "($expression) COLLATE BINARY";
    }
}

