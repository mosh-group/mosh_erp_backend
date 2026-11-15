# MOSH Laravel Backend — ZKTeco Integration

This backend release adds the server side of the approved ZKTeco attendance integration without changing `work_presence` or task/workday timing.

## Added
- Dedicated bearer-token bridge endpoints: `POST /api/integrations/zkteco/events` and `/heartbeat`.
- Device, per-device employee mapping, and immutable raw attendance-event tables.
- Exact event idempotency plus 60-second repeat suppression for attendance calculation.
- Unknown enrollment preservation and authorized mapping/replay endpoints.
- First effective punch = check-in; latest effective punch = checkout candidate.
- ZKTeco-derived records are added to the existing employee `attendanceRecords` array; manual/correction records remain authoritative.
- Feature-level ZKTeco permissions for Admin/HR delegation.
- Employee JSON fields `zktecoEnrollmentNumber` and `zktecoExempt`.
- Safe rollout switch `ZKTECO_REQUIRE_EMPLOYEE_MAPPING=false`; enable only after the frontend employee form supports explicit mapping/exemption.

## Important compatibility rule
`work_presence` is intentionally untouched. Fingerprint attendance is HR attendance, not task/work-state presence.

## Deployment
1. Use PHP 8.5+ with the extensions required by the existing project.
2. Back up the production database and `.env`.
3. Configure a long random `ZKTECO_BRIDGE_TOKEN` and verify the primary device/timezone values.
4. Run `php artisan migrate`.
5. Run `php artisan test`.
6. Set `ZKTECO_ENABLED=true` only when the bridge is ready.
7. After the frontend mapping/exemption UI is deployed and existing employees are reviewed, set `ZKTECO_REQUIRE_EMPLOYEE_MAPPING=true`.

## 48-hour missing checkout
Open single-punch days are flagged with `needsHrReview=true`. Automatic 48-hour closure is deliberately not invented because the audited MOSH backend has no authoritative per-employee/per-date scheduled workday-end source. Once such a schedule source exists, D18 can be implemented without guessing a checkout time.

## 2026-10-01 timestamp correction
ZKTeco `deviceTime` is a terminal-local wall-clock attendance timestamp. The backend intentionally stores and derives attendance from that local value without converting it to UTC. `received_at`, processing timestamps, and server health timestamps remain normal server timestamps. This prevents a terminal punch such as `08:00` from being changed to `05:00` by timezone conversion and follows the integration decision to preserve the device attendance time.
