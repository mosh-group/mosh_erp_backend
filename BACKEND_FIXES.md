# Backend audit fixes — B01–B48

Completed backend correction • 21 September 2026

All 48 backend findings from MOSH_API_Audit_Report.md are addressed in this archive. This is the backend stage requested by the user. The original frontend has not been edited; F01–F15 remain pending, and the full application is not yet certified ready for production.

## Verification

- **77 PHPUnit tests, 949 assertions passed**, including 61 new audit regression tests and the 16 existing tests (with contract-valid fixtures).
- **23 real HTTP checks passed** against a fresh SQLite database: initial setup, CSRF rejection, current account, CRUD, stale version rejection, search/pagination, real private upload/link/deletion restriction, bootstrap, logout/login and invalidating a second authenticated session after password change.
- **226/226 documented method/path pairs** match registered backend routes; no missing or undocumented API routes.
- Fresh migrations and normal seeders passed. DemoSeeder repeatability passed. There are no default login credentials. Deleted wisdom content stays deleted on reseeding.
- PHP source lint, Composer manifest/lock validation, configuration caching, route caching and `artisan serve` startup passed.
- No new packages, architecture layers or database migrations were introduced.

Tests are reproducible with `php vendor/bin/phpunit`. The finding-specific tests are in `tests/Feature/BackendAuditTest.php`; test names below are exact. The original `ApiTest.php` and `DomainTest.php` remain in the suite.

## Finding-by-finding completion matrix

| Finding | Implemented correction | Primary regression test |
|---|---|---|
| **B01** — Read policy bypasses explicit denies and detail actions | Explicit denies and detail actions are enforced; administration has no blanket read bypass. Employee directory redaction and report/bootstrap/file scope use the shared policy. | `test_b01_explicit_read_denials_and_details_apply_to_admin_and_bootstrap` |
| **B02** — List sort/filter validation does not enforce scalar fields | Scalar sort/filter fields and scalar values are validated before iterating results, including empty lists. | `test_b02_invalid_scalar_filters_are_rejected_even_with_no_rows` |
| **B03** — Public auth status can report an invalid session as authenticated | Public auth status and protected requests share active-user, auth-version and idle-session checks. | `test_b03_status_checks_session_version_and_idle_expiry` |
| **B04** — Account lockout 429 lacks Retry-After | Custom account lockouts return a positive Retry-After header. | `test_b04_lockout_response_includes_retry_after` |
| **B05** — Current-account response omits effective permissions | Current-user response adds the role document, effective module/global permissions and effectiveTaskScope. | `test_b05_current_user_resolves_role_permissions_and_scope` |
| **B06** — Password change accepts the current password as the new password | Password changes reject reuse of the existing password. | `test_b06_password_must_change` |
| **B07** — User/role PATCH is implemented as a full-form replacement | User/role PATCH validates the merged record and preserves every omitted field. | `test_b07_partial_user_and_role_updates_preserve_omitted_fields` |
| **B08** — Security PATCH requires every setting | Security PATCH validates supplied settings and preserves the remainder. | `test_b08_partial_security_update_preserves_omitted_values` |
| **B09** — Undo violates actor and replay requirements | Undo is limited to the original actor; the replay lookup precedes the already-undone check inside the transaction lock. | `test_b09_undo_is_actor_bound_and_replay_safe` |
| **B10** — Transaction operation validation permits missing data | Create/update data is required; malformed payloads fail validation and unsupported actions return 400. | `test_b10_transaction_payloads_are_validated_before_execution` |
| **B11** — File validation/access is only partly conformant | Unsafe original paths/names and script extensions are rejected; oversize files return 413; linked files require current parent detail access. | `test_b11_file_names_size_and_linked_owner_access` |
| **B12** — Missing attachment keys are silently accepted | Referenced attachment keys must exist; validation failure rolls back the record and file links. | `test_b12_missing_file_keys_abort_the_entire_save` |
| **B13** — Settings reads do not enforce the documented settings permissions | Settings GET and bootstrap use the same settings-management permission policy as writes. | `test_b13_settings_reads_and_bootstrap_use_settings_permissions` |
| **B14** — Report queries can bypass row scope or ignore filters | Finance reports filter records through authorization; ledger search and common filters are applied to actual rows and opening balances. | `test_b14_finance_and_ledger_reports_enforce_scope_and_search` |
| **B15** — Customer field ownership is not partitioned as documented | Customer currency/paymentDays/taxNumber/accountCode22 changes require accounting permission. | `test_b15_financial_customer_fields_require_accounting_access` |
| **B16** — Salesperson team references are not validated | Assigned salesperson teams must exist by ID or the original name. | `test_b16_salesperson_team_must_exist` |
| **B17** — Sales-team leader existence is not checked | Sales-team leaders must reference existing salespeople. | `test_b17_sales_team_leader_must_exist` |
| **B18** — Quotation-template default invariant is absent | Atomic saves enforce at most one default quotation template and require it to be active. | `test_b18_default_template_is_unique_and_active` |
| **B19** — Service-contract lines lack required content validation | Service-contract lines require service, description and valid amounts; customer relationships must resolve. | `test_b19_service_contract_lines_and_customer_are_validated` |
| **B20** — Quotation validation and provenance are incomplete | Quotation product/section/note/optional lines and costs are validated; follow-up provenance is server-generated; linked orders cannot be deleted. | `test_b20_quotation_lines_authorship_and_linked_deletion` |
| **B21** — Task assignment and workflow rules are incomplete | Assignment checks active employees and appointed department managers; worker transitions and timing actions are actor-bound; shared children cannot escape roots; completed children and accounting approval are required for package billing. | `test_b21_tasks_validate_assignment_worker_transitions_and_shared_roots` |
| **B22** — Employee updates can bypass protected nested workflows | Manual HR edits cannot invent/remove warehouse custody, erase linked approval sources, forge approval transitions, bypass responsible payment actors or disable the current account. | `test_b22_employee_custody_discipline_and_current_profile_are_protected` |
| **B23** — HR request type validation and effects are incomplete | All documented HR request-type fields are validated, including owned uploads/currency; approved hourly leave creates its employee effect once. | `test_b23_hr_type_validation_and_hourly_leave_effect_once` |
| **B24** — Legacy HR approvals accept invalid source/stage data | Legacy approval kinds, source references/payloads, statuses and forward-only stages are validated; approved source effects remain server-generated. | `test_b24_legacy_approvals_require_source_and_forward_stages` |
| **B25** — Closing offboarding does not update the employee on the server | Closing cleared offboarding marks the employee resigned/inactive and disables the linked login in the same transaction. | `test_b25_closing_offboarding_disables_employee_and_linked_login` |
| **B26** — Announcement archive, receipt and deletion effects are incomplete | Alert archive copies and read timestamps are server-generated; acknowledgements are append-only; deletion soft-deletes the master and visible employee copies. | `test_b26_notices_generate_archives_preserve_reads_and_soft_delete` |
| **B27** — Employment-contract clause/version validation is incomplete | Clause lists require ordered arrays, unique stable IDs and content; successors validate parent/employee, next version, uniqueness and cleared signatures. | `test_b27_contract_clauses_and_successor_versions_are_validated` |
| **B28** — Executive-report original content is editable during review | Review cannot rewrite the original report author, summary or submission content. | `test_b28_executive_report_original_content_is_frozen` |
| **B29** — Administrative decision/meeting times are not validated | Nonempty administrative meeting/decision times require HH:mm. | `test_b29_meeting_and_decision_time_formats_are_validated` |
| **B30** — Presence timestamps can remain client-controlled | Presence timestamps are generated/preserved by the server, including repeated same-mode PATCH requests. | `test_b30_presence_timestamps_are_server_owned` |
| **B31** — Field-work event identity/timing validation is incomplete | Activity/status events reference active template identifiers; event and shift timestamps are server-controlled. | `test_b31_field_activity_ids_and_server_times` |
| **B32** — Wisdom ordering has no persisted representation | settings/wisdom.order persists unique existing IDs; resource lists and bootstrap return that order. | `test_b32_wisdom_order_survives_reads_and_bootstrap` |
| **B33** — Purchase/logistics workflow does not enforce all required effects | Offers require active suppliers and matching quantity/unit/kind. Stock receipts require matching movements atomically; services cannot enter stock. Logistics completion requires receiver/note and releases unfulfilled reservations. | `test_b33_purchase_stock_receipts_are_atomic_and_logistics_release_reservations` |
| **B34** — Warehouse validation differs from the contract | Warehouse managers must be active; stock/custody/reservations prevent deactivation. general/equipment/internal/customer are supported, preserving real seed values. | `test_b34_warehouse_managers_and_outstanding_custody` |
| **B35** — Customer-owned stock does not require an existing customer | Customer-owned stock requires an existing customer. | `test_b35_customer_owned_stock_requires_existing_customer` |
| **B36** — Stock movement authorization/receipt matching is incomplete | Transfers authorize both warehouses; purchase movements match receipt item/warehouse/unit/type/quantity and cannot duplicate a receipt line. | `test_b36_transfer_requires_both_warehouse_permissions` |
| **B37** — Reservations do not enforce active resources or fulfillment links | Reservations require active resources; issued reservations link a matching authorized issue once, validated after the atomic batch. | `test_b37_reservations_require_active_resources_and_real_fulfillment` |
| **B38** — Legacy invoices bypass server financial validation | Legacy invoices derive canonical finance21 lines/totals/source from an approved quotation; malformed/source-less legacy invoices are rejected and source uniqueness is enforced. | `test_b38_legacy_invoice_recalculates_and_enforces_source_uniqueness` |
| **B39** — Financial-document endpoint accepts an undocumented kind | financial-documents accepts vendor/employee/expense only; customer invoices remain on the invoice endpoint. | `test_b39_financial_documents_disallow_customer_kind` |
| **B40** — Journal validation and PATCH contract conflict | Manual journal defaults are safe; zero-sided lines are rejected; reversal currency matches the original. PATCH remains an explicit 422 rejection under append-only rules. | `test_b40_manual_journal_defaults_zero_lines_and_reversal_currency` |
| **B41** — Payroll-hour supersede guard is missing for live salary documents | Live salary documents prevent superseding their approved hour sheets. | `test_b41_hours_cannot_be_superseded_while_used_by_live_salary` |
| **B42** — Payroll source fingerprint is not compared | Payroll validates approved source fingerprints and snapshots, calculation inputs and atomic run/payable status consistency. | `test_b42_payroll_requires_matching_fingerprint_and_snapshot` |
| **B43** — Print-family access and block-size bounds are too broad | Print layout create/edit/delete/default changes enforce family permissions; block sizes are 0 or 8–48. | `test_b43_print_layout_access_is_limited_to_family_and_valid_sizes` |
| **B44** — HR policy bounds are not validated | HR policy workday hours, review day and probation values have documented bounds. | `test_b44_hr_policy_limits_apply_to_direct_and_batch_updates` |
| **B45** — Manager/purchasing settings accept inactive employees | Department-manager maps validate department keys and active employees; purchasing approvers must also be active. | `test_b45_settings_require_active_employees_and_known_departments` |
| **B46** — Shared employment clauses are not validated | Shared contract-template clauses validate ordered content and IDs; their version increments server-side. | `test_b46_shared_clauses_require_content_and_unique_stable_ids` |
| **B47** — Executive review setting trusts submitted actor/time | Review-map records reference existing reports and use authenticated actor/server time. | `test_b47_review_map_validates_report_and_overwrites_actor_time` |
| **B48** — Finance-link setting is broader than its stated contract | Finance-link changes require administration and an unposted document without an existing journal posting. | `test_b48_finance_party_links_require_admin_and_unposted_documents` |

## Contract resolutions and compatibility

- **Warehouse kinds (B34):** the supplied default data already uses equipment/internal/customer and the UI also uses general/customer. Validation now supports all four; no records or visible labels are renamed.
- **Journal PATCH (B40):** the append-only requirement is retained. The route remains registered, returns 422 for edits, and the contradictory success example is corrected. Authorized manual reversals are new entries.
- **Legacy termination approvals (B24):** the prototype stores a unique termination-request sourceId and payload rather than a nested employee termination row. Its employee, source identity, date/reason and stage progression are validated; other legacy kinds require matching nested source rows.
- **Wisdom ordering (B32):** persist the ordered IDs in the existing settings/wisdom value under order. Lists and bootstrap honor it. The frontend reorder control still needs F10.
- **Field activity (B31):** activityId/statusId identify template entries separately from each event's id. The pending frontend changes must send those identifiers.
- **Payroll (B42):** the existing payroll23.snapshot must contain the approved hour-sheet fingerprint and matching source inputs/calculation.
- **Finance links (B48):** the previous frontend cannot cache posted party links back into this protected setting. Posting derives canonical links; the frontend integration must respect that boundary.
- User/role list response examples and the requisition headcount required flag were also made consistent with their existing documented behavior. No endpoint was removed.

## Scope of verification

The tests establish the audited backend corrections for the exercised positive and negative cases. They are not an exhaustive proof of every possible business input or permission combination. This stage does not claim frontend F01–F15 are fixed, and the earlier browser workflow results were not reused as evidence that this revised backend is fully integrated. MySQL/PostgreSQL behavior, production deployment and live SMTP delivery were not tested in this stage; email remains covered by the existing fake-mail test. The fresh real-HTTP verification uses the same PHP/Laravel application with session cookies and CSRF enabled.

## Run the corrected backend

Follow SETUP.md. On an existing installation preserve the environment, database and private uploads; install the locked Composer dependencies, clear caches, run migrations/seeders and execute the tests. The project continues to require PHP 8.5+ and uses Laravel 13. No default password or test database is included in the ZIP.
