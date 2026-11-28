<?php

namespace App\Support;

use App\Models\{Task, User};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{DB, Validator};
use Illuminate\Support\Str;

final class PlatformPrograms
{
    private const DEPARTMENT = 'MOSH MEDIA';
    private const PREFIX = '_mosh_platform_program_';
    private const RESOURCE = 'platform-programs';
    private const PAGE_SIZE = 50;
    private const MAX_TASKS = 250;

    public static function index(User $actor): array
    {
        self::authorize($actor);

        return DB::transaction(function () use ($actor) {
            $programs = array_reverse(R19Store::documents(self::PREFIX));
            $ids = [];
            foreach ($programs as $program) {
                foreach ($program['taskIds'] as $id) {
                    $ids[$id] = $id;
                }
            }
            $tasks = self::visibleByIds($actor, array_values($ids));

            return [
                'programs' => array_map(fn (array $program) => self::project($program, $tasks), $programs),
                'canCreate' => $actor->allows('platform', 'create'),
                'canEdit' => $actor->allows('platform', 'edit'),
            ];
        });
    }

    public static function taskOptions(User $actor, array $input): array
    {
        self::authorize($actor);
        $filters = Validator::make($input, [
            'q' => ['nullable', 'string', 'max:120'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
        ])->validate();
        $page = (int) ($filters['page'] ?? 1);
        $query = self::visibleTasks($actor, true);
        $search = trim($filters['q'] ?? '');
        if ($search !== '') {
            $query->where(fn (Builder $match) => $match
                ->whereLike('id', '%'.$search.'%')
                ->orWhereLike('data->title', '%'.$search.'%')
                ->orWhereLike('data->assignee', '%'.$search.'%'));
        }
        $rows = $query->orderByDesc('created_at')->orderBy('id')
            ->offset(($page - 1) * self::PAGE_SIZE)->limit(self::PAGE_SIZE + 1)->get(['id', 'status', 'data']);

        return [
            'tasks' => $rows->take(self::PAGE_SIZE)->map(self::task(...))->values()->all(),
            'page' => $page,
            'hasMore' => $rows->count() > self::PAGE_SIZE,
        ];
    }

    public static function save(User $actor, array $input, ?string $id = null): array
    {
        $action = $id === null ? 'create' : 'edit';
        self::authorize($actor, $action);
        $values = Validator::make($input, [
            'name' => ['required', 'string', 'max:160'],
            'participantCount' => ['required', 'integer', 'min:0', 'max:1000000'],
            'completedEpisodeCount' => ['required', 'integer', 'min:0', 'max:1000000'],
            'taskIds' => ['present', 'array', 'list', 'max:'.self::MAX_TASKS],
            'taskIds.*' => ['required', 'string', 'max:190', 'distinct:strict'],
            '_version' => $id === null ? ['prohibited'] : ['required', 'integer', 'min:1'],
            'requestId' => ['required', 'string', 'min:8', 'max:128', 'regex:/^[A-Za-z0-9_.:-]+$/D'],
        ])->validate();
        $scope = self::RESOURCE.':'.($id ?? 'create');
        $result = R19Store::commit($actor, $scope, $values, function (string $transaction) use ($actor, $action, $id, $values) {
            self::authorize($actor, $action);
            $programId = $id ?? (string) Str::ulid();
            $key = self::PREFIX.$programId;
            $old = $id === null ? null : R19Store::get($key);
            abort_if($id !== null && $old === null, 404, 'البرنامج غير موجود.');
            $version = (int) ($values['_version'] ?? 0);
            abort_unless($version === ($old['_version'] ?? 0), 409, 'تغيّر البرنامج. حدّث القائمة قبل الحفظ.');

            $links = self::links($actor, $old['taskIds'] ?? [], $values['taskIds']);
            R19Store::put($key, [
                'id' => $programId,
                'name' => $values['name'],
                'participantCount' => (int) $values['participantCount'],
                'completedEpisodeCount' => (int) $values['completedEpisodeCount'],
                'taskIds' => $links,
            ], $version);
            Audit::record($actor, 'platform', self::RESOURCE, $programId, $action, transaction: $transaction);

            return ['id' => $programId];
        });

        self::authorize($actor, $action);
        $program = R19Store::get(self::PREFIX.$result['id']);
        abort_unless($program, 404, 'البرنامج غير موجود.');

        return self::project($program, self::visibleByIds($actor, $program['taskIds']));
    }

    private static function authorize(User $actor, ?string $action = null): void
    {
        abort_unless($actor->allows('platform', 'view') && $actor->allows('platform', 'dashboard')
            && ($action === null || $actor->allows('platform', $action)), 403);
    }

    private static function visibleTasks(User $actor, bool $platformOnly = false): Builder
    {
        $filters = $platformOnly ? ['filter' => ['department' => self::DEPARTMENT]] : [];

        return TaskReadQuery::forDatabase()->visibleTo($actor, $filters);
    }

    private static function visibleByIds(User $actor, array $ids): Collection
    {
        $byId = collect();
        if ($ids) {
            $query = self::visibleTasks($actor);
            foreach (array_chunk($ids, 400) as $batch) {
                foreach ((clone $query)->whereIn('id', $batch)->get(['id', 'status', 'data']) as $task) {
                    $byId->put((string) $task->id, self::task($task));
                }
            }
        }

        return $byId;
    }

    private static function links(User $actor, array $previous, array $requested): array
    {
        $visiblePrevious = self::visibleByIds($actor, $previous);
        $new = array_values(array_diff($requested, $visiblePrevious->keys()->all()));
        if ($new) {
            $choices = self::visibleTasks($actor, true)->whereIn('id', $new)->pluck('id')->all();
            abort_if(array_diff($new, $choices), 422, 'إحدى المهام المختارة غير متاحة ضمن مهام المنصة.');
        }

        // Saving a partial view must preserve existing links outside the editor's task scope.
        $hidden = array_values(array_diff($previous, $visiblePrevious->keys()->all()));
        $links = array_values(array_unique(array_merge($requested, $hidden)));
        abort_if(count($links) > self::MAX_TASKS, 422, 'تجاوز البرنامج الحدّ المسموح لعدد المهام المرتبطة.');

        return $links;
    }

    private static function project(array $program, Collection $visibleTasks): array
    {
        $tasks = [];
        foreach ($program['taskIds'] as $id) {
            if ($task = $visibleTasks->get($id)) {
                $tasks[] = $task;
            }
        }

        return [
            'id' => $program['id'],
            'name' => $program['name'],
            'participantCount' => $program['participantCount'],
            'completedEpisodeCount' => $program['completedEpisodeCount'],
            'taskCount' => count($tasks),
            'tasks' => $tasks,
            '_version' => $program['_version'],
        ];
    }

    private static function task(Task $task): array
    {
        $data = $task->data ?? [];

        return [
            'id' => (string) $task->id,
            'title' => (string) ($data['title'] ?? ''),
            'status' => (string) ($data['status'] ?? $task->status ?? 'new'),
            'assignee' => ($data['sharedDistributionMode'] ?? '') === 'team' ? 'فريق عمل' : (string) ($data['assignee'] ?? ''),
        ];
    }
}

