# MOSH ERP — frontend analysis checkpoint

## Status

The original frontend was recovered and inspected. Stage 1 is in progress; the final endpoint-by-endpoint API specification is not complete. No application implementation files have been changed. Backend implementation and frontend integration have not started.

The supplied Laravel Google Drive URL could not be opened by the public retrieval tool. Google Drive was subsequently installed, but no Drive metadata/fetch tools were exposed in this turn. The actual Laravel ZIP is still required; no substitute project has been created.

## Source and performed verification

- Original input: `MOSH_ERP_VANILLA.zip`, 592,051 bytes.
- SHA-256: `75d18ac1555dc983be7e94bd7e0ca33563a4a72e0d8f5edec43918b2a7f0c541`.
- Source contains `index.html`, one stylesheet, 52 ordered JavaScript files and a README.
- All 52 JavaScript files were successfully parsed as JavaScript. The AST inventory records 2548 named function expressions/declarations/assignments, including helpers, wrappers and repeated implementations; this count is not a feature count.
- The unmodified prototype was served locally in Chromium using a disposable browser context. Its first-account setup was exercised with a generated local test account.
- All 17 main navigation modules were opened. No page-level JavaScript runtime errors were observed during that smoke check. This does not verify every form, CRUD flow, permission combination or external PDF library.
- External CDN requests were blocked during the local smoke check. PDF export is therefore unverified.
- `original-dashboard.png` preserves a reference view. A single screenshot is not full visual regression coverage.
- The source currently persists operational data in localStorage and binary attachments/audits in IndexedDB; the integration must replace those persistence paths.

## Main screens observed

| Module | Active view after navigation | Observed scope |
|---|---|---|
| dashboard | dashboardApp | Employee daily hub, attendance, work status, notices, own requests/tasks, wisdom |
| tasks | tasksApp | Company tasks, search and pagination |
| admin | adminApp | Executive reports, approvals, presence, decisions and meetings |
| hr | hrApp | Employee records, requests, attendance, recruitment, contracts, alerts, wisdom and field work |
| sales | salesApp | Quotations, approved/cancelled quotations, contracts, customers, services, reports and configuration |
| marketing | marketingApp | Discovery, brand guide, plans, notes, task views and contact follow-ups |
| design | designApp | Department dashboard, distribution/running tasks, manager review, work report |
| photo | photoApp | Photography dashboard and task workflows |
| editing | editingApp | Editing dashboard and task workflows |
| promotion | promotionApp | Promotion dashboard and task workflows |
| logistics | logisticsApp | Requests, scheduling, reservations, delivery proof and operations reports |
| purchases | purchasesApp | Requests, supplier offers, purchase orders, approvals, receipts and suppliers |
| accounting | accountingApp | Invoices, bills, payments, cash, journal, statements, payroll and print design |
| warehouses | warehousesApp | Warehouses, stock, barcode issue, receipts, custody, customer goods and movement reports |
| platform | platformApp | Existing placeholder; source explicitly says content can be added later |
| instructions | instructionsApp | Static handbook with local search, topic navigation and printing |
| archive | archiveApp | Closed tasks by billing result and audit history |

## Behavior that the backend must preserve

### Accounts and permissions

`43-mosh-auth-v37-js.js` defines 17 module identifiers, 16 module actions, 11 global permission keys, four base roles, per-user allow/deny/inherit overrides, task scope and employee links. Later scripts 44–51 further restrict task visibility and actions. Client-side filtering must not be the authorization boundary. Employee-scoped accounts require a linked employee record; name matching must become stable identifier relationships.

The current root account is protected against removal/deactivation; the current user cannot delete their own account. In-use roles cannot be deleted. Login implements password minimum length, temporary lockout, session expiry, remembered username and forced password changes. The production replacement must enforce these on the server.

### Task execution and shared work

The final v48 employee overlay combines receive and start for a new task. Working tasks may pause/resume or submit to a manager. Submission is not final billing approval. Revision returns a task to the original employee and records a chat/history entry. Transfer closes timing, clears assignment and retains the task's data and attachments.

Shared work uses root → department unit → optional team members. Completion aggregates upward. Adding departments must preserve existing work. Package completion and accounting handoff differ from single-task completion. Archive restoration reopens the task in a selected department and resets workflow metadata while retaining history.

Work sessions, idle detection, work-away-from-computer classification, global break/no-work/end-work and field-work status interact. Durations and identities must become server-controlled. A double-click or network retry must not start two sessions or bill a task twice.

### HR

Employee forms have identity, employment, contact, health/emergency, education, assets, documents, attendance, leave, evaluation, disciplinary, reward, payroll and audit sections. Later scripts stage documents/leave/attendance until Save and Close; cancel must discard unsaved changes.

Leave cannot precede hire date, overlap approved/pending leave, or silently exceed available annual entitlement. Hourly leave has explicit start/end time and possible annual-balance deduction. Attendance checks dates, duplicate days, breaks and delays. These are separate from passive dashboard elapsed-time display.

Signed employment contracts are immutable. Duplicate creates an unsigned new version; deletion of signed contracts is archival. Signature side, signer name, consent and strokes are recorded once. Updating clause templates must not rewrite existing contracts.

HR service requests and legacy HR approvals are two distinct stored structures; both need explicit migration and API coverage. Their current-stage actor and final side effects must be checked server-side. Final-result acknowledgement belongs to the affected employee.

Announcement recipient snapshots and per-employee alert archives preserve who received a notice at send time. Removing one employee's archived copy must not remove colleagues' copies.

### Purchasing, logistics and stock

Purchasing enforces versioned offers and approvals, supplier validity, reasoned recommendation, delegated approvers, conditional direct-manager approval, partial receipt quantities and stock integration. Editing lines invalidates outdated offer review. Receipted orders cannot simply reopen pricing or be cancelled.

Logistics scheduling rejects overlapping employee assignments and resource overbooking. Stock availability subtracts time-overlapping reservations. A serial item moves one unit, cannot exist twice in stock/custody, and customer-owned items cannot be issued to another customer's work.

Custody issue/return is distinct from consumable issue. Returns cannot exceed outstanding custody. Damage requires elevated authority and reason. Stock receipt, approval snapshots and custody updates must commit atomically. Some linked movements cannot be reversed independently.

### Accounting and payroll

The finance file implements three successive layers: document/payment processing, journal and party accounting, then payroll and print layouts. All are active; replacing only legacy invoices would omit most accounting behavior.

Amounts are currency-aware and must be calculated without binary floating-point drift. Source-linked documents validate supplier/employee/customer identity, currency and amount. Cash accounts cannot go negative under the existing rules. Overpayment, duplicate references, invalid advance allocation and posting into locked periods must be rejected.

Payments and journals use explicit reversal records. Paid documents cannot be cancelled until dependencies are reversed. Journal postings must remain balanced and idempotent. Standalone vendor bills require management review; approval routes differ by document kind and source.

Payroll uses approved monthly hours with an attendance/calculation snapshot. Runs group one period and currency, create linked employee payables and move through submission/decision/cancellation together. Salary advances are settled separately to avoid deducting them twice. Payroll-specific operations cannot be bypassed by editing one linked payable.

### Sales and marketing

Customers and services are shared catalogs. Names currently act as references in several places; backend IDs must preserve labels without breaking links on rename. Quotations include product/section/note rows, optionals, pricing, tax, discounts, costs, follow-ups and PDF layout fields. One approved order cannot create duplicate sales tasks or active linked invoices.

Marketing scripts 40 and 41 supersede and extend script 34. The final interface includes editable discovery questions, brand guide, plans with manager review, pinned notes and contact interactions with future follow-up. Submitted plans require title/objective/audience/message; only draft or returned plans can be deleted.

### Local interactions

Keep navigation, accordion/dropdown/tab/modal behavior, dirty indicators, draft editing, Arabic labels, responsive layout and print rendering intact. The handbook and placeholder page do not justify invented backend modules. The assistant is a deterministic helper over application data; no external AI service is needed to reproduce it.

Global undo currently restores browser snapshots. It cannot overwrite other users' newer backend changes; the final design must retain local draft undo and specify safe version-checked inverse operations for already-saved changes.

## HR request types found in the source

| Type | Visible label | Prototype route |
|---|---|---|
| `annual_leave` | إجازة سنوية | المدير المباشر → الموارد البشرية |
| `hourly_leave` | إجازة ساعية / إذن خروج | المدير المباشر → الموارد البشرية |
| `sick_leave` | إجازة مرضية | المدير المباشر → الموارد البشرية |
| `attendance_correction` | تصحيح حضور / انصراف | المدير المباشر → الموارد البشرية |
| `advance` | طلب سلفة | المدير المباشر → الموارد البشرية → المحاسبة |
| `salary_certificate` | شهادة راتب / تعريف | الموارد البشرية |
| `document_update` | تحديث بيانات أو وثيقة | الموارد البشرية |
| `recruitment` | طلب توظيف | الموارد البشرية → الإدارة |
| `promotion` | ترقية | الموارد البشرية → الإدارة → المحاسبة |
| `transfer` | نقل موظف | الموارد البشرية → مدير القسم الحالي → مدير القسم الجديد |
| `secondment` | انتداب / مهمة عمل | المدير المباشر → الموارد البشرية |
| `reward` | مكافأة | الموارد البشرية → الإدارة → المحاسبة |
| `deduction` | خصم | الموارد البشرية → الإدارة → المحاسبة |
| `warning` | إنذار / لفت نظر | الموارد البشرية |
| `appreciation` | تقدير موظف | الموارد البشرية |
| `star_month` | نجم الشهر | الموارد البشرية → الإدارة |
| `termination` | إنهاء خدمة | الموارد البشرية → الإدارة → اللوجستيات → المحاسبة |

## Existing storage to replace or deliberately retain

| Store | Current use | Integration treatment |
|---|---|---|
| `mosh_erp_sales_deep_v1` | Main business state | Replace persistence with domain APIs |
| `mosh_erp_auth_v37` | Accounts, reversible passwords, roles, policies, audit | Replace with backend users/auth/authorization |
| `mosh_erp_session_v37` | Browser authentication session | Replace with validated server session |
| `mosh_exec_reports_step2` | Executive reports | Persist via executive-report APIs |
| `mosh_hr_wisdom_v26` | Wisdom library and rotation settings | Persist shared records/settings server-side |
| `mosh_wisdom_runtime_v32b` | Rotation runtime cache | Derived local cache only, never authoritative shared settings |
| `mosh_erp_archive_v1` | IndexedDB audit archive | Server append-only audit |
| `mosh_erp_task_attachments_v1` | IndexedDB file blobs | Server file storage and authorized downloads |
| Sidebar and remembered-username preferences | Local presentation/convenience | May stay local; never imply authentication |
| Visual/control settings | UI appearance configuration | Preserve visible controls; decide shared versus user scope explicitly |

## Remaining work

1. Obtain and inspect the supplied Laravel ZIP, its version, composer lock, middleware structure, existing authentication and database setup.
2. Finish Stage 1: review every delegated/dynamically generated handler and screen state; turn API_INVENTORY.md into API_DOCUMENTATION.md with complete per-endpoint contracts and a coverage matrix. The preliminary inventory must not be treated as a finished spec.
3. Implement migrations, models, controllers, validation, authorization and routes in the supplied backend. Use ordinary Laravel conventions; no generic whole-state upload, frontend framework or unnecessary repository layer.
4. Integrate each current frontend persistence path. Define a single global API base URL. Preserve staging/cancel semantics, loading/error states and all visible UI.
5. Verify migrations, seeders, authentication/authorization, every route, CRUD, workflow concurrency, pagination, real file transfers and full UI behavior. None of these backend checks has been performed yet.
6. Package the completed backend/frontend, final API_DOCUMENTATION.md and exact SETUP.md. These final deliverables do not yet exist.

## JavaScript source inventory

The following covers all source scripts in execution order. Function counts include named local helpers and wrappers. The checkpoint ZIP also includes the detailed AST inventory with function names, source locations, field keys, input references and called functions.

| Source | Bytes | Named functions/assignments |
|---|---:|---:|
| `01-mosh-auth-v37-prelock.js` | 61 | 0 |
| `02-mosh-core-runtime-14.js` | 560,890 | 676 |
| `03-inline.js` | 504 | 1 |
| `04-v206-hr-enterprise-js.js` | 49,275 | 50 |
| `05-v207-safe-dashboard-js.js` | 472 | 1 |
| `06-v208-employee-portal-js.js` | 7,326 | 7 |
| `07-v209-hr-unified-js.js` | 25,341 | 23 |
| `08-v211-employee-daily-hub-js.js` | 14,246 | 23 |
| `09-work-evidence-v2161-js.js` | 10,735 | 26 |
| `10-step2-exec-reports-js.js` | 2,015 | 5 |
| `11-step3-department-exec-report-js.js` | 7,397 | 8 |
| `12-design-work-report-safe-v8a.js` | 6,064 | 11 |
| `13-mosh-department-dashboards-v9-js.js` | 24,776 | 39 |
| `14-hr-notices-v10-js.js` | 17,633 | 24 |
| `15-employee-space-v11-js-transport.js` | 13,247 | 27 |
| `16-mosh-hr13-runtime.js` | 31,974 | 76 |
| `17-mosh-documents-14-runtime.js` | 18,960 | 24 |
| `18-mosh-hr14-complete.js` | 80,181 | 114 |
| `19-mosh-v15-runtime.js` | 56,175 | 100 |
| `20-mosh-v16-runtime.js` | 25,471 | 52 |
| `21-mosh-handbook-v17-js.js` | 7,600 | 14 |
| `22-mosh-ops18-js.js` | 146,138 | 184 |
| `23-mosh-calm19-runtime.js` | 18,701 | 9 |
| `24-mosh-finance22-js.js` | 208,012 | 300 |
| `25-mosh-v25-dark-controls-js.js` | 5,907 | 13 |
| `26-mosh-wisdom-v26-js.js` | 21,091 | 47 |
| `27-mosh-sidebar-order-v27a.js` | 1,557 | 4 |
| `28-v27d-wisdom-merge.js` | 3,128 | 7 |
| `29-v27e-wisdom-layout-fix-js.js` | 1,058 | 2 |
| `30-mosh-manager-approvals-v28-js.js` | 21,174 | 42 |
| `31-mosh-request-results-v28b-js.js` | 6,918 | 28 |
| `32-mosh-v28c-request-routing-repair.js` | 3,779 | 13 |
| `33-mosh-v28e-request-actions-js.js` | 5,026 | 17 |
| `34-mosh-marketing-v29-js.js` | 30,425 | 69 |
| `35-mosh-field-work-v30b-js.js` | 31,483 | 56 |
| `36-mosh-v30c-field-admin-sync-js.js` | 8,091 | 25 |
| `37-mosh-v32-systematic-shared-distribution-js.js` | 26,826 | 59 |
| `38-mosh-v32c-runtime-safety.js` | 762 | 1 |
| `39-mosh-v32e-manager-inline-js.js` | 3,253 | 8 |
| `40-mosh-marketing-v33-js.js` | 44,296 | 87 |
| `41-mosh-marketing-v34-js.js` | 22,168 | 52 |
| `42-mosh-v36-prebeta-stability-js.js` | 3,861 | 8 |
| `43-mosh-auth-v37-js.js` | 72,257 | 128 |
| `44-mosh-v38-my-tasks-enforcement-js.js` | 11,774 | 34 |
| `45-mosh-v41-employee-task-sync-js.js` | 10,579 | 19 |
| `46-mosh-v42-employee-link-reconcile-js.js` | 1,296 | 3 |
| `47-mosh-v44-task-assignment-account-fix.js` | 845 | 1 |
| `48-mosh-v45-employee-tasks-unified-marker.js` | 524 | 1 |
| `49-mosh-v46-employee-task-open-nav-marker.js` | 51 | 0 |
| `50-mosh-v47-employee-task-overlay-js.js` | 6,670 | 13 |
| `51-mosh-v48-employee-task-workflow-js.js` | 7,148 | 17 |
| `52-mosh-v48-marker.js` | 55 | 0 |
