<?php
namespace Tests\Feature;

use App\Models\{AuditEvent, Customer, Employee, FileLink, Setting, Task, User, WorkPresence};
use App\Support\{R19Attendance, R19HrWorkflow, R19Store};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{DB, Storage};
use Tests\TestCase;

/** Full HTTP decisions against an isolated :memory: database. No user data,
 * storage, device or production seed is touched by these fixtures. */
class R19HrWorkflowTest extends TestCase
{
    use RefreshDatabase;
    private array $accounts = [];
    private string $attachmentsRoot;
    private int $serial = 0;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
            throw new \RuntimeException('R19 tests require an isolated SQLite :memory: database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'Asia/Damascus', 'mosh.demo_data' => false, 'zkteco.enabled' => false, 'logging.default' => 'null']);
        $this->travelTo(Carbon::parse('2026-10-10T12:00:00+03:00'));
        $this->seed();
        $this->attachmentsRoot = sys_get_temp_dir().'/mosh-r19-hr-'.bin2hex(random_bytes(8));
        mkdir($this->attachmentsRoot, 0700, true);
        config(['filesystems.disks.local.root' => $this->attachmentsRoot]); Storage::forgetDisk('local');
        foreach ([
            'employee' => ['E', 'التصميم', 'role_employee', 'Manager'],
            'manager' => ['M', 'التصميم', 'role_manager', ''],
            'other' => ['O', 'التصوير', 'role_manager', ''],
            'admin' => ['A', 'الإدارة', 'role_admin', ''],
            'hr' => ['H', 'الموارد البشرية', 'role_manager', ''],
        ] as $role => [$id, $department, $roleId, $directManager]) {
            $name = $role === 'manager' ? 'Manager' : ucfirst($role);
            Employee::create(['id' => $id, 'department' => $department, 'data' => ['id' => $id, 'name' => $name,
                'department' => $department, 'active' => true, 'employmentStatus' => 'active', 'directManager' => $directManager,
                'attendanceRecords' => []], 'version' => 1]);
            $this->accounts[$role] = User::create(['id' => 'U-'.$id, 'name' => $name, 'username' => $role,
                'employee_id' => $id, 'department' => $department, 'role_id' => $roleId, 'active' => true,
                'password' => 'Test-only-Password123!', 'data' => [], 'must_change' => false, 'auth_version' => 1, 'version' => 1]);
        }
        Setting::whereKey('department-managers')->update(['value' => json_encode(['التصميم' => 'M', 'التصوير' => 'O'], JSON_UNESCAPED_UNICODE)]);
    }

    protected function tearDown(): void
    {
        if (isset($this->attachmentsRoot)) (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($this->attachmentsRoot);
        parent::tearDown();
    }

    private function asRole(string $role): void
    {
        $u = $this->accounts[$role]->fresh();
        $this->actingAs($u)->withSession(['auth_version' => $u->auth_version, 'last_activity' => time()]);
    }

    private function key(): string { return 'r19-test-request-'.(++$this->serial); }
    private function requestPayload(array $changes = []): array
    {
        return array_merge(['employeeId' => 'E', 'type' => 'deduction', 'reason' => 'Private disciplinary reason',
            'details' => 'Evidence and proposed action details.', 'amount' => 150000, 'currency' => 'SYP', 'attachments' => [], 'requestId' => $this->key()], $changes);
    }
    private function createRequest(array $changes = []): array
    {
        $this->asRole('manager');
        return $this->postJson('/api/r19/hr/disciplinary', $this->requestPayload($changes))->assertCreated()->json('data');
    }
    private function decision(string $role, array $row, string $decision = 'approve', array $extra = []): array
    {
        $this->asRole($role);
        return $this->postJson('/api/r19/hr/disciplinary/'.$row['id'].'/decision', array_merge([
            'decision' => $decision, 'note' => $decision === 'reject' ? 'Evidence did not support this case.' : '',
            'version' => $row['_version'], 'requestId' => $this->key()], $extra))->assertOk()->json('data');
    }
    private function records(array $rows, string $employeeId = 'E'): void
    {
        $e = Employee::findOrFail($employeeId); $d = $e->data; $d['attendanceRecords'] = $rows;
        $e->update(['data' => $d, 'version' => $e->version + 1]);
    }
    private function day(string $role = 'manager', string $date = '2026-10-08'): array
    {
        $this->asRole($role);
        $rows = $this->getJson('/api/r19/hr?date='.$date)->assertOk()->json('data.attendance');
        return collect($rows)->firstWhere('employeeId', 'E') ?? [];
    }
    private function overtimePayload(array $row, string $decision, array $extra = []): array
    {
        return array_merge(['decision' => $decision, 'note' => '', 'version' => $row['_version'],
            'sourceFingerprint' => $row['sourceFingerprint'], 'requestId' => $this->key()], $extra);
    }
    private function overtime(string $role, array $row, string $decision, array $extra = []): array
    {
        $this->asRole($role);
        return $this->postJson('/api/r19/hr/overtime/'.$row['id'].'/decision', $this->overtimePayload($row, $decision, $extra))->assertOk()->json('data');
    }
    private function closedDay(): void
    {
        $this->records([['id' => 'ATT-8', 'date' => '2026-10-08', 'inTime' => '09:00', 'outTime' => '19:00', 'breakMinutes' => 30, 'source' => 'zkteco', 'status' => 'recorded']]);
    }

    public function test_authentication_scope_and_private_store_are_enforced(): void
    {
        $this->getJson('/api/r19/hr')->assertUnauthorized();
        $r = $this->createRequest(); $this->asRole('employee');
        $this->getJson('/api/r19/hr')->assertOk()->assertJsonCount(0, 'data.disciplinary')->assertJsonCount(1, 'data.employees');
        $this->getJson('/api/r19/hr/disciplinary/'.$r['id'])->assertNotFound();
        $this->getJson('/api/settings/'.R19HrWorkflow::DISCIPLINARY_PREFIX.$r['id'])->assertNotFound();
        $this->assertStringNotContainsString('Private disciplinary reason', $this->getJson('/api/bootstrap')->assertOk()->getContent());
        $this->asRole('other'); $this->getJson('/api/r19/hr/disciplinary/'.$r['id'])->assertNotFound();
        $this->getJson('/api/r19/hr?date=2026-02-30')->assertUnprocessable();
        $this->getJson('/api/r19/hr?date=2026-10-11')->assertUnprocessable();
    }

    public function test_discipline_requires_manager_then_admin_then_hr_and_reaches_employee_archive(): void
    {
        $before = Employee::find('E')->data;
        $r = $this->createRequest(); $this->assertSame('pending_admin', $r['status']);
        $this->assertSame('U-M', $r['submittedBy']); $this->assertCount(1, $r['history']);
        foreach (['employee', 'manager', 'hr'] as $role) {
            $this->asRole($role);
            $response = $this->postJson('/api/r19/hr/disciplinary/'.$r['id'].'/decision', ['decision' => 'approve', 'version' => 1, 'requestId' => $this->key()]);
            $role === 'employee' ? $response->assertNotFound() : $response->assertForbidden();
        }
        $r = $this->decision('admin', $r); $this->assertSame('pending_hr', $r['status']);
        $this->assertFalse($r['canReview']);
        $this->postJson('/api/r19/hr/disciplinary/'.$r['id'].'/decision', ['decision' => 'approve', 'version' => 2, 'requestId' => $this->key()])->assertForbidden();
        $r = $this->decision('hr', $r); $this->assertSame('approved', $r['status']); $this->assertSame(3, $r['_version']);
        $this->assertSame('150000.00', $r['amount']); $this->assertSame('U-H', $r['approvedBy']); $this->assertCount(3, $r['history']);
        $this->asRole('employee');
        $this->getJson('/api/r19/hr/disciplinary/'.$r['id'])->assertOk()->assertJsonPath('data.reason', 'Private disciplinary reason');
        $this->getJson('/api/r19/hr')->assertOk()->assertJsonCount(1, 'data.disciplinary');
        $notices = R19HrWorkflow::noticesFor($this->accounts['employee']); $this->assertCount(1, $notices);
        $this->assertSame($r['id'], $notices[0]['details']['requestId']);
        $this->assertSame($before, Employee::find('E')->data); // Archive is canonical; no payroll or duplicate legacy approval.
        $this->assertSame(3, AuditEvent::where('resource', 'r19-disciplinary')->count());
    }

    public function test_employee_outside_manager_admin_create_and_forged_history_are_denied(): void
    {
        foreach (['employee', 'other', 'admin'] as $role) {
            $this->asRole($role); $this->postJson('/api/r19/hr/disciplinary', $this->requestPayload())->assertForbidden();
        }
        $this->asRole('manager');
        $this->postJson('/api/r19/hr/disciplinary', $this->requestPayload(['employeeId' => 'M']))->assertForbidden();
        $this->postJson('/api/r19/hr/disciplinary', $this->requestPayload(['history' => [['by' => 'Forged']]]))->assertUnprocessable();
        $this->postJson('/api/r19/hr/disciplinary', $this->requestPayload(['amount' => 10.123]))->assertUnprocessable();
        $this->postJson('/api/r19/hr/disciplinary', $this->requestPayload(['status' => 'approved']))->assertUnprocessable();
        $this->assertEmpty(R19Store::prefixed(R19HrWorkflow::DISCIPLINARY_PREFIX));
    }

    public function test_idempotent_creation_and_decision_do_not_duplicate_and_stale_versions_fail(): void
    {
        $this->asRole('manager'); $body = $this->requestPayload();
        $r = $this->postJson('/api/r19/hr/disciplinary', $body)->assertCreated()->json('data');
        $this->postJson('/api/r19/hr/disciplinary', $body)->assertCreated()->assertJsonPath('data.id', $r['id']);
        $this->assertCount(1, R19Store::prefixed(R19HrWorkflow::DISCIPLINARY_PREFIX));
        $this->postJson('/api/r19/hr/disciplinary', array_merge($body, ['reason' => 'different']))->assertConflict();
        $this->asRole('admin');
        $body = ['decision' => 'approve', 'version' => 1, 'note' => '', 'requestId' => $this->key()];
        $this->postJson('/api/r19/hr/disciplinary/'.$r['id'].'/decision', $body)->assertOk()->assertJsonPath('data._version', 2);
        $this->postJson('/api/r19/hr/disciplinary/'.$r['id'].'/decision', $body)->assertOk()->assertJsonCount(2, 'data.history');
        $this->asRole('hr');
        $this->postJson('/api/r19/hr/disciplinary/'.$r['id'].'/decision', ['decision' => 'approve', 'version' => 1, 'requestId' => $this->key()])->assertConflict();
        $this->assertSame(2, AuditEvent::where('resource', 'r19-disciplinary')->count());
        $tx = DB::table('transactions')->where('actor_id', 'U-A')->value('id'); $this->asRole('admin');
        $this->withHeader('Idempotency-Key', 'r19-deny-generic-undo')->postJson('/api/transactions/'.$tx.'/undo')->assertConflict();
    }

    public function test_rejection_requires_reason_and_preserves_management_archive_only(): void
    {
        $r = $this->createRequest(); $this->asRole('admin');
        $this->postJson('/api/r19/hr/disciplinary/'.$r['id'].'/decision', ['decision' => 'reject', 'note' => '', 'version' => 1, 'requestId' => $this->key()])->assertUnprocessable();
        $r = $this->decision('admin', $r, 'reject'); $this->assertSame('rejected', $r['status']);
        $this->assertNotEmpty($r['rejectionReason']); $this->asRole('manager');
        $this->getJson('/api/r19/hr/disciplinary/'.$r['id'])->assertOk();
        $this->asRole('employee'); $this->getJson('/api/r19/hr/disciplinary/'.$r['id'])->assertNotFound();
        $this->assertEmpty(R19HrWorkflow::noticesFor($this->accounts['employee']));
    }

    public function test_attachments_are_private_linked_and_delivered_only_after_final_approval(): void
    {
        $this->asRole('manager');
        $file = $this->post('/api/files', ['file' => UploadedFile::fake()->createWithContent('evidence.txt', 'real test attachment'), 'key' => 'R19-PROOF'])->assertCreated()->json('data');
        $r = $this->createRequest(['attachments' => [['key' => $file['key']]]]);
        $this->assertSame('evidence.txt', $r['attachments'][0]['name']); $this->assertSame(1, FileLink::count());
        $this->deleteJson('/api/files/'.$file['key'])->assertConflict();
        foreach (['employee', 'other'] as $role) { $this->asRole($role); $this->get('/api/files/'.$file['key'].'/content')->assertNotFound(); }
        $this->asRole('admin'); $this->get('/api/files/'.$file['key'].'/content')->assertOk();
        $r = $this->decision('admin', $r); $r = $this->decision('hr', $r);
        $this->asRole('employee'); $this->get('/api/files/'.$file['key'].'/content')->assertOk();
        $this->asRole('other');
        $this->postJson('/api/r19/hr/disciplinary', $this->requestPayload(['employeeId' => 'O', 'attachments' => [['key' => $file['key']]]]))->assertForbidden();
        $this->asRole('employee');
        $otherFile = $this->post('/api/files', ['file' => UploadedFile::fake()->createWithContent('worker.txt', 'worker private'), 'key' => 'R19-WORKER-PROOF'])->assertCreated()->json('data');
        $this->asRole('manager');
        $this->postJson('/api/r19/hr/disciplinary', $this->requestPayload(['attachments' => [['key' => $otherFile['key']]]]))->assertForbidden();
    }

    public function test_overtime_uses_actual_net_attendance_and_only_direct_manager_then_hr(): void
    {
        $this->closedDay(); $r = $this->day();
        $this->assertSame(570, $r['total']); $this->assertSame(480, $r['basic']); $this->assertSame(90, $r['extra']);
        $this->assertNull($r['delay']); $this->assertTrue($r['complete']); $this->assertSame('manager', $r['decisionStage']);
        foreach (['employee', 'admin', 'hr', 'other'] as $role) {
            $this->asRole($role);
            $response = $this->postJson('/api/r19/hr/overtime/'.$r['id'].'/decision', $this->overtimePayload($r, $role === 'hr' ? 'final' : 'full'));
            $role === 'other' ? $response->assertNotFound() : $response->assertForbidden();
        }
        $r = $this->overtime('manager', $r, 'partial', ['minutes' => 60]);
        $this->assertSame('pending_hr', $r['overtimeStatus']); $this->assertSame(60, $r['managerApproved']); $this->assertSame(0, $r['finalApproved']);
        $this->asRole('admin');
        $this->postJson('/api/r19/hr/overtime/'.$r['id'].'/decision', $this->overtimePayload($r, 'final'))->assertForbidden();
        $r = $this->overtime('hr', $r, 'final');
        $this->assertSame(60, $r['finalApproved']); $this->assertSame('finalized', $r['overtimeStatus']);
        $this->assertSame(['manager', 'hr'], array_column($r['approvalHistory'], 'role'));
        $this->assertSame(60, $this->day('employee')['finalApproved']);
    }

    public function test_overtime_partial_bounds_idempotency_and_version_conflict(): void
    {
        $this->closedDay(); $r = $this->day();
        foreach ([0, 91] as $minutes) $this->postJson('/api/r19/hr/overtime/'.$r['id'].'/decision', $this->overtimePayload($r, 'partial', ['minutes' => $minutes]))->assertUnprocessable();
        $this->assertEmpty(R19Store::prefixed(R19HrWorkflow::OVERTIME_PREFIX));
        $body = $this->overtimePayload($r, 'full');
        $r = $this->postJson('/api/r19/hr/overtime/'.$r['id'].'/decision', $body)->assertOk()->json('data');
        $this->postJson('/api/r19/hr/overtime/'.$r['id'].'/decision', $body)->assertOk()->assertJsonCount(1, 'data.approvalHistory');
        $this->asRole('hr');
        $this->postJson('/api/r19/hr/overtime/'.$r['id'].'/decision', $this->overtimePayload($r, 'final', ['version' => 0]))->assertConflict();
        $r = $this->overtime('hr', $r, 'final'); $this->assertSame(90, $r['finalApproved']);
        $this->assertSame(2, AuditEvent::where('resource', 'r19-overtime')->count());
    }

    public function test_overtime_returns_and_employee_resubmission_preserve_authoritative_history(): void
    {
        $this->closedDay(); $r = $this->day();
        $r = $this->overtime('manager', $r, 'return', ['note' => 'Explain the extra time.']);
        $this->assertSame('returned_employee', $r['overtimeStatus']); $this->assertFalse($r['canReviewOvertime']);
        $r = $this->day('employee'); $this->assertTrue($r['canResubmit']);
        $this->postJson('/api/r19/hr/overtime/'.$r['id'].'/decision', $this->overtimePayload($r, 'resubmit'))->assertUnprocessable();
        $r = $this->overtime('employee', $r, 'resubmit', ['note' => 'Worked on the delivery.']);
        $r = $this->overtime('manager', $r, 'full');
        $r = $this->overtime('hr', $r, 'return', ['note' => 'Please verify the duration.']);
        $this->assertSame('returned_manager', $r['overtimeStatus']); $this->assertSame(0, $r['finalApproved']);
        $r = $this->overtime('manager', $r, 'partial', ['minutes' => 45]);
        $r = $this->overtime('hr', $r, 'final');
        $this->assertCount(6, $r['approvalHistory']); $this->assertSame(45, $r['finalApproved']);
        $this->assertSame('U-E', $r['approvalHistory'][1]['byId']);
    }

    public function test_attendance_changes_invalidate_pending_and_final_credit_until_reapproval(): void
    {
        $this->closedDay(); $r = $this->overtime('manager', $this->day(), 'full');
        $this->records([['id' => 'ATT-8', 'date' => '2026-10-08', 'inTime' => '09:00', 'outTime' => '18:00', 'breakMinutes' => 30]]);
        $this->asRole('hr');
        $this->postJson('/api/r19/hr/overtime/'.$r['id'].'/decision', $this->overtimePayload($r, 'final'))->assertConflict();
        $changed = $this->day(); $this->assertTrue($changed['sourceChanged']); $this->assertSame(0, $changed['managerApproved']); $this->assertSame(30, $changed['extra']);
        $r = $this->overtime('manager', $changed, 'full'); $r = $this->overtime('hr', $r, 'final');
        $this->assertSame(30, $r['finalApproved']); $this->assertCount(4, $r['approvalHistory']);
        $this->records([]); $missing = $this->day();
        $this->assertSame(0, $missing['finalApproved']); $this->assertFalse($missing['complete']); $this->assertTrue($missing['sourceChanged']);
    }

    public function test_direct_manager_override_is_enforced_even_against_department_manager(): void
    {
        $this->closedDay(); $e = Employee::find('E'); $d = $e->data; $d['directManager'] = 'Other'; $e->update(['data' => $d]);
        $r = $this->day('manager'); $this->assertFalse($r['canReviewOvertime']);
        $this->postJson('/api/r19/hr/overtime/'.$r['id'].'/decision', $this->overtimePayload($r, 'full'))->assertForbidden();
        $r = $this->day('other'); $this->assertTrue($r['canReviewOvertime']);
        $r = $this->overtime('other', $r, 'full'); $this->assertSame('pending_hr', $r['overtimeStatus']);
    }

    public function test_missing_ambiguous_cancelled_and_duplicate_attendance_never_inflate_approved_time(): void
    {
        $this->records([
            ['id' => 'a', 'date' => '2026-10-08', 'inTime' => '09:00', 'outTime' => '18:00'],
            ['id' => 'copy', 'date' => '2026-10-08', 'inTime' => '09:00', 'outTime' => '18:00'],
            ['id' => 'cancelled', 'date' => '2026-10-08', 'inTime' => '06:00', 'outTime' => '20:00', 'status' => 'cancelled'],
            ['id' => 'zk-old', 'date' => '2026-10-08', 'inTime' => '06:00', 'outTime' => '20:00', 'source' => 'zkteco', 'status' => 'overridden'],
        ]);
        $r = $this->day(); $this->assertSame(540, $r['total']); $this->assertTrue($r['complete']);
        $this->records([
            ['date' => '2026-10-08', 'inTime' => '09:00', 'outTime' => '18:00'],
            ['date' => '2026-10-08', 'inTime' => '17:00', 'outTime' => '19:00'],
        ]);
        $r = $this->day(); $this->assertSame(600, $r['total']); $this->assertFalse($r['complete']);
        $this->postJson('/api/r19/hr/overtime/'.$r['id'].'/decision', $this->overtimePayload($r, 'full'))->assertForbidden();
        $this->records([['date' => '2026-10-08', 'inTime' => '09:00', 'outTime' => null]]);
        $r = $this->day(); $this->assertSame(0, $r['total']); $this->assertFalse($r['complete']);
        $this->records([]);
        WorkPresence::create(['id' => 'id:E', 'employee_id' => 'E', 'data' => ['employeeId' => 'E', 'mode' => 'active', 'startedAt' => now()->subHours(9)->getTimestampMs()], 'version' => 1]);
        $this->asRole('employee'); $this->getJson('/api/r19/hr')->assertOk()->assertJsonCount(0, 'data.attendance');
    }

    public function test_policy_has_no_default_days_requires_hr_and_overtime_offsets_deficit_first(): void
    {
        $this->closedDay(); $r = $this->overtime('manager', $this->day(), 'full'); $this->overtime('hr', $r, 'final');
        $e = Employee::find('E'); $records = $e->data['attendanceRecords'];
        $records[] = ['date' => '2026-10-07', 'inTime' => '09:00', 'outTime' => '16:00']; $this->records($records);
        $this->asRole('hr');
        $before = $this->getJson('/api/r19/hr?month=2026-10')->assertOk()->json('data');
        $monthly = collect($before['monthly'])->firstWhere('employeeId', 'E');
        $this->assertNull($monthly['required']); $this->assertNull($monthly['netExtra']);
        $policy = ['month' => '2026-10', 'workdays' => 2, 'version' => 0, 'requestId' => $this->key()];
        foreach (['manager', 'admin', 'employee'] as $role) { $this->asRole($role); $this->putJson('/api/r19/hr/policy', $policy)->assertForbidden(); }
        $this->asRole('hr');
        $this->putJson('/api/r19/hr/policy', array_merge($policy, ['month' => '2026-02', 'workdays' => 31]))->assertUnprocessable();
        $this->putJson('/api/r19/hr/policy', array_merge($policy, ['dailyHours' => 6]))->assertUnprocessable();
        $this->putJson('/api/r19/hr/policy', $policy)->assertOk()->assertJsonPath('data._version', 1);
        $this->putJson('/api/r19/hr/policy', $policy)->assertOk()->assertJsonPath('data._version', 1);
        $this->putJson('/api/r19/hr/policy', array_merge($policy, ['requestId' => $this->key()]))->assertConflict();
        $monthly = collect($this->getJson('/api/r19/hr?month=2026-10')->assertOk()->json('data.monthly'))->firstWhere('employeeId', 'E');
        $this->assertSame(900, $monthly['basic']); $this->assertSame(960, $monthly['required']); $this->assertSame(60, $monthly['deficit']);
        $this->assertSame(90, $monthly['finalApproved']); $this->assertSame(60, $monthly['compensation']); $this->assertSame(30, $monthly['netExtra']);
        $this->assertFalse($monthly['complete']); $this->assertFalse($monthly['payrollCreated']);
    }

    public function test_read_only_reports_do_not_create_policy_approvals_or_change_attendance(): void
    {
        $this->closedDay(); $before = [Setting::count(), AuditEvent::count(), Employee::find('E')->version, DB::table('transactions')->count()];
        $this->day(); $this->day('employee'); $this->day('hr');
        $this->assertSame($before, [Setting::count(), AuditEvent::count(), Employee::find('E')->version, DB::table('transactions')->count()]);
        $this->assertNull(Setting::find(R19HrWorkflow::POLICY_KEY));
    }

    public function test_urgent_is_server_protected_independent_of_revision_and_history_is_authoritative(): void
    {
        Customer::create(['id' => 'C', 'data' => ['id' => 'C', 'name' => 'Client'], 'version' => 1]);
        $d = ['id' => 'T', 'title' => 'Task', 'customer' => 'Client', 'customerId' => 'C', 'department' => 'التصميم',
            'assigneeId' => 'E', 'assignee' => 'Employee', 'status' => 'in_progress', 'employeeState' => 'paused',
            'returnedForRevision' => true, 'isUrgent' => false, 'urgent' => false, 'priority' => 'normal'];
        Task::create(['id' => 'T', 'employee_id' => 'E', 'customer_id' => 'C', 'department' => 'التصميم', 'data' => $d, 'version' => 1]);
        // A generic editor is deliberately granted edit rights, demonstrating
        // that broad editing alone cannot bypass the urgent-manager rule.
        $u = $this->accounts['employee']; $u->update(['data' => ['moduleOverrides' => ['design' => ['edit' => true]]]]);
        $this->asRole('employee');
        $this->patchJson('/api/tasks/T', ['_version' => 1, 'isUrgent' => true])->assertForbidden();
        $this->asRole('manager');
        $r = $this->patchJson('/api/tasks/T', ['_version' => 1, 'isUrgent' => true, 'urgentUpdatedBy' => 'forged', 'urgentUpdatedAt' => '1999-01-01'])->assertOk()->json('data');
        $this->assertTrue($r['isUrgent']); $this->assertTrue($r['returnedForRevision']); $this->assertSame('U-M', $r['urgentUpdatedBy']);
        $this->assertNotSame('1999-01-01', $r['urgentUpdatedAt']);
        $this->asRole('employee');
        $this->patchJson('/api/tasks/T', ['_version' => 2, 'urgent' => false])->assertForbidden();
        $this->asRole('admin');
        $r = $this->patchJson('/api/tasks/T', ['_version' => 2, 'priority' => 'normal'])->assertOk()->json('data');
        $this->assertFalse($r['isUrgent']); $this->assertFalse($r['urgent']); $this->assertTrue($r['returnedForRevision']);
        $this->assertSame('U-A', $r['urgentUpdatedBy']);
    }
}
