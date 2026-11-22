<?php
namespace App\Support;

/** Completed billing records retain their original executor; this is not a new assignment. */
final class TaskBillingHandoff
{
    public static function preservesExecutor(?array $old, array $next): bool
    {
        if (!$old || empty($old['assigneeId'])
            || (string)($next['assigneeId'] ?? '') !== (string)$old['assigneeId']
            || ($next['assignee'] ?? '') !== ($old['assignee'] ?? '')
            || ($next['department'] ?? '') !== 'المحاسبة'
            || ($next['employeeState'] ?? '') !== 'finished') return false;

        $billing = ['awaiting_invoice','invoiced','archived_invoiced','archived_no_invoice'];
        if (!in_array($next['status'] ?? '', $billing, true)) return false;
        if (($old['status'] ?? '') === 'in_progress') {
            return ($old['employeeState'] ?? '') === 'submitted'
                && ($next['status'] ?? '') === 'awaiting_invoice'
                && !empty($old['department'])
                && ($next['closedFromDepartment'] ?? '') === $old['department'];
        }
        return in_array($old['status'] ?? '', $billing, true)
            && ($old['department'] ?? '') === 'المحاسبة'
            && ($old['employeeState'] ?? '') === 'finished'
            && !empty($old['closedFromDepartment'])
            && ($next['closedFromDepartment'] ?? '') === $old['closedFromDepartment'];
    }
}
