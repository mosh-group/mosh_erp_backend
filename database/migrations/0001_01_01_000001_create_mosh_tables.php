<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('customers', function (Blueprint $t) {
    $t->string('id',190)->primary(); $t->json('data'); $t->unsignedInteger('version')->default(1);
    $t->string('department')->nullable()->index(); $t->string('status')->nullable()->index();
    $t->timestamps();
  });
  Schema::create('services', function (Blueprint $t) {
    $t->string('id',190)->primary(); $t->json('data'); $t->unsignedInteger('version')->default(1);
    $t->string('department')->nullable()->index(); $t->string('status')->nullable()->index();
    $t->timestamps();
  });
  Schema::create('salespeople', function (Blueprint $t) {
    $t->string('id',190)->primary(); $t->json('data'); $t->unsignedInteger('version')->default(1);
    $t->string('department')->nullable()->index(); $t->string('status')->nullable()->index();
    $t->timestamps();
  });
  Schema::create('sales_teams', function (Blueprint $t) {
    $t->string('id',190)->primary(); $t->json('data'); $t->unsignedInteger('version')->default(1);
    $t->string('department')->nullable()->index(); $t->string('status')->nullable()->index();
    $t->timestamps();
  });
  Schema::create('quotation_templates', function (Blueprint $t) {
    $t->string('id',190)->primary(); $t->json('data'); $t->unsignedInteger('version')->default(1);
    $t->string('department')->nullable()->index(); $t->string('status')->nullable()->index();
    $t->timestamps();
  });
  Schema::create('service_contracts', function (Blueprint $t) {
    $t->string('id',190)->primary(); $t->json('data'); $t->unsignedInteger('version')->default(1);
    $t->string('department')->nullable()->index(); $t->string('status')->nullable()->index();
    $t->string('customer_id',190)->nullable()->index();
    $t->timestamps();
  });
  Schema::create('quotations', function (Blueprint $t) {
    $t->string('id',190)->primary(); $t->json('data'); $t->unsignedInteger('version')->default(1);
    $t->string('department')->nullable()->index(); $t->string('status')->nullable()->index();
    $t->string('customer_id',190)->nullable()->index();
    $t->timestamps();
  });
  Schema::create('tasks', function (Blueprint $t) {
    $t->string('id',190)->primary(); $t->json('data'); $t->unsignedInteger('version')->default(1);
    $t->string('department')->nullable()->index(); $t->string('status')->nullable()->index();
    $t->string('employee_id',190)->nullable()->index();
    $t->string('customer_id',190)->nullable()->index();
    $t->string('parent_id',190)->nullable()->index();
    $t->string('quotation_id',190)->nullable()->index();
    $t->timestamps();
  });
  Schema::create('employees', function (Blueprint $t) {
    $t->string('id',190)->primary(); $t->json('data'); $t->unsignedInteger('version')->default(1);
    $t->string('department')->nullable()->index(); $t->string('status')->nullable()->index();
    $t->timestamps();
  });
  Schema::create('hr_requests', function (Blueprint $t) {
    $t->string('id',190)->primary(); $t->json('data'); $t->unsignedInteger('version')->default(1);
    $t->string('department')->nullable()->index(); $t->string('status')->nullable()->index();
    $t->string('employee_id',190)->nullable()->index();
    $t->timestamps();
  });
  Schema::create('hr_approvals', function (Blueprint $t) {
    $t->string('id',190)->primary(); $t->json('data'); $t->unsignedInteger('version')->default(1);
    $t->string('department')->nullable()->index(); $t->string('status')->nullable()->index();
    $t->string('employee_id',190)->nullable()->index();
    $t->timestamps();
  });
  Schema::create('requisitions', function (Blueprint $t) {
    $t->string('id',190)->primary(); $t->json('data'); $t->unsignedInteger('version')->default(1);
    $t->string('department')->nullable()->index(); $t->string('status')->nullable()->index();
    $t->timestamps();
  });
  Schema::create('offboarding', function (Blueprint $t) {
    $t->string('id',190)->primary(); $t->json('data'); $t->unsignedInteger('version')->default(1);
    $t->string('department')->nullable()->index(); $t->string('status')->nullable()->index();
    $t->string('employee_id',190)->nullable()->index();
    $t->timestamps();
  });
  Schema::create('recognition', function (Blueprint $t) {
    $t->string('id',190)->primary(); $t->json('data'); $t->unsignedInteger('version')->default(1);
    $t->string('department')->nullable()->index(); $t->string('status')->nullable()->index();
    $t->string('employee_id',190)->nullable()->index();
    $t->timestamps();
  });
  Schema::create('announcements', function (Blueprint $t) {
    $t->string('id',190)->primary(); $t->json('data'); $t->unsignedInteger('version')->default(1);
    $t->string('department')->nullable()->index(); $t->string('status')->nullable()->index();
    $t->timestamps();
  });
  Schema::create('employment_contracts', function (Blueprint $t) {
    $t->string('id',190)->primary(); $t->json('data'); $t->unsignedInteger('version')->default(1);
    $t->string('department')->nullable()->index(); $t->string('status')->nullable()->index();
    $t->string('employee_id',190)->nullable()->index();
    $t->timestamps();
  });
  Schema::create('executive_reports', function (Blueprint $t) {
    $t->string('id',190)->primary(); $t->json('data'); $t->unsignedInteger('version')->default(1);
    $t->string('department')->nullable()->index(); $t->string('status')->nullable()->index();
    $t->timestamps();
  });
  Schema::create('admin_decisions', function (Blueprint $t) {
    $t->string('id',190)->primary(); $t->json('data'); $t->unsignedInteger('version')->default(1);
    $t->string('department')->nullable()->index(); $t->string('status')->nullable()->index();
    $t->timestamps();
  });
  Schema::create('admin_meetings', function (Blueprint $t) {
    $t->string('id',190)->primary(); $t->json('data'); $t->unsignedInteger('version')->default(1);
    $t->string('department')->nullable()->index(); $t->string('status')->nullable()->index();
    $t->timestamps();
  });
  Schema::create('work_presence', function (Blueprint $t) {
    $t->string('id',190)->primary(); $t->json('data'); $t->unsignedInteger('version')->default(1);
    $t->string('department')->nullable()->index(); $t->string('status')->nullable()->index();
    $t->string('employee_id',190)->nullable()->index();
    $t->timestamps();
  });
  Schema::create('field_work', function (Blueprint $t) {
    $t->string('id',190)->primary(); $t->json('data'); $t->unsignedInteger('version')->default(1);
    $t->string('department')->nullable()->index(); $t->string('status')->nullable()->index();
    $t->string('employee_id',190)->nullable()->index();
    $t->timestamps();
  });
  Schema::create('field_templates', function (Blueprint $t) {
    $t->string('id',190)->primary(); $t->json('data'); $t->unsignedInteger('version')->default(1);
    $t->string('department')->nullable()->index(); $t->string('status')->nullable()->index();
    $t->timestamps();
  });
  Schema::create('wisdom', function (Blueprint $t) {
    $t->string('id',190)->primary(); $t->json('data'); $t->unsignedInteger('version')->default(1);
    $t->string('department')->nullable()->index(); $t->string('status')->nullable()->index();
    $t->timestamps();
  });
  Schema::create('support_requests', function (Blueprint $t) {
    $t->string('id',190)->primary(); $t->json('data'); $t->unsignedInteger('version')->default(1);
    $t->string('department')->nullable()->index(); $t->string('status')->nullable()->index();
    $t->string('task_id',190)->nullable()->index();
    $t->string('employee_id',190)->nullable()->index();
    $t->timestamps();
  });
  Schema::create('warehouses', function (Blueprint $t) {
    $t->string('id',190)->primary(); $t->json('data'); $t->unsignedInteger('version')->default(1);
    $t->string('department')->nullable()->index(); $t->string('status')->nullable()->index();
    $t->timestamps();
  });
  Schema::create('stock_items', function (Blueprint $t) {
    $t->string('id',190)->primary(); $t->json('data'); $t->unsignedInteger('version')->default(1);
    $t->string('department')->nullable()->index(); $t->string('status')->nullable()->index();
    $t->timestamps();
  });
  Schema::create('stock_movements', function (Blueprint $t) {
    $t->string('id',190)->primary(); $t->json('data'); $t->unsignedInteger('version')->default(1);
    $t->string('department')->nullable()->index(); $t->string('status')->nullable()->index();
    $t->string('item_id',190)->nullable()->index();
    $t->string('from_warehouse_id',190)->nullable()->index();
    $t->string('to_warehouse_id',190)->nullable()->index();
    $t->string('employee_id',190)->nullable()->index();
    $t->string('request_id',190)->nullable()->index();
    $t->timestamps();
  });
  Schema::create('reservations', function (Blueprint $t) {
    $t->string('id',190)->primary(); $t->json('data'); $t->unsignedInteger('version')->default(1);
    $t->string('department')->nullable()->index(); $t->string('status')->nullable()->index();
    $t->string('item_id',190)->nullable()->index();
    $t->string('warehouse_id',190)->nullable()->index();
    $t->string('request_id',190)->nullable()->index();
    $t->timestamps();
  });
  Schema::create('suppliers', function (Blueprint $t) {
    $t->string('id',190)->primary(); $t->json('data'); $t->unsignedInteger('version')->default(1);
    $t->string('department')->nullable()->index(); $t->string('status')->nullable()->index();
    $t->timestamps();
  });
  Schema::create('invoices', function (Blueprint $t) {
    $t->string('id',190)->primary(); $t->json('data'); $t->unsignedInteger('version')->default(1);
    $t->string('department')->nullable()->index(); $t->string('status')->nullable()->index();
    $t->string('customer_id',190)->nullable()->index();
    $t->string('quotation_id',190)->nullable()->index();
    $t->timestamps();
  });
  Schema::create('financial_documents', function (Blueprint $t) {
    $t->string('id',190)->primary(); $t->json('data'); $t->unsignedInteger('version')->default(1);
    $t->string('department')->nullable()->index(); $t->string('status')->nullable()->index();
    $t->timestamps();
  });
  Schema::create('cash_accounts', function (Blueprint $t) {
    $t->string('id',190)->primary(); $t->json('data'); $t->unsignedInteger('version')->default(1);
    $t->string('department')->nullable()->index(); $t->string('status')->nullable()->index();
    $t->timestamps();
  });
  Schema::create('payments', function (Blueprint $t) {
    $t->string('id',190)->primary(); $t->json('data'); $t->unsignedInteger('version')->default(1);
    $t->string('department')->nullable()->index(); $t->string('status')->nullable()->index();
    $t->timestamps();
  });
  Schema::create('journal_entries', function (Blueprint $t) {
    $t->string('id',190)->primary(); $t->json('data'); $t->unsignedInteger('version')->default(1);
    $t->string('department')->nullable()->index(); $t->string('status')->nullable()->index();
    $t->timestamps();
  });
  Schema::create('payroll_hours', function (Blueprint $t) {
    $t->string('id',190)->primary(); $t->json('data'); $t->unsignedInteger('version')->default(1);
    $t->string('department')->nullable()->index(); $t->string('status')->nullable()->index();
    $t->string('employee_id',190)->nullable()->index();
    $t->timestamps();
  });
  Schema::create('payroll_runs', function (Blueprint $t) {
    $t->string('id',190)->primary(); $t->json('data'); $t->unsignedInteger('version')->default(1);
    $t->string('department')->nullable()->index(); $t->string('status')->nullable()->index();
    $t->timestamps();
  });
  Schema::create('print_layouts', function (Blueprint $t) {
    $t->string('id',190)->primary(); $t->json('data'); $t->unsignedInteger('version')->default(1);
    $t->string('department')->nullable()->index(); $t->string('status')->nullable()->index();
    $t->timestamps();
  });
  Schema::table('tasks', function (Blueprint $t) {
    $t->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();
    $t->foreign('customer_id')->references('id')->on('customers')->restrictOnDelete();
    $t->foreign('parent_id')->references('id')->on('tasks')->restrictOnDelete();
    $t->foreign('quotation_id')->references('id')->on('quotations')->restrictOnDelete();
  });
  Schema::table('service_contracts', function (Blueprint $t) {
    $t->foreign('customer_id')->references('id')->on('customers')->restrictOnDelete();
  });
  Schema::table('quotations', function (Blueprint $t) {
    $t->foreign('customer_id')->references('id')->on('customers')->restrictOnDelete();
  });
  Schema::table('hr_requests', function (Blueprint $t) {
    $t->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();
  });
  Schema::table('hr_approvals', function (Blueprint $t) {
    $t->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();
  });
  Schema::table('offboarding', function (Blueprint $t) {
    $t->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();
  });
  Schema::table('recognition', function (Blueprint $t) {
    $t->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();
  });
  Schema::table('employment_contracts', function (Blueprint $t) {
    $t->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();
  });
  Schema::table('work_presence', function (Blueprint $t) {
    $t->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();
  });
  Schema::table('field_work', function (Blueprint $t) {
    $t->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();
  });
  Schema::table('support_requests', function (Blueprint $t) {
    $t->foreign('task_id')->references('id')->on('tasks')->restrictOnDelete();
    $t->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();
  });
  Schema::table('stock_movements', function (Blueprint $t) {
    $t->foreign('item_id')->references('id')->on('stock_items')->restrictOnDelete();
    $t->foreign('from_warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
    $t->foreign('to_warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
    $t->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();
    $t->foreign('request_id')->references('id')->on('support_requests')->restrictOnDelete();
  });
  Schema::table('reservations', function (Blueprint $t) {
    $t->foreign('item_id')->references('id')->on('stock_items')->restrictOnDelete();
    $t->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
    $t->foreign('request_id')->references('id')->on('support_requests')->restrictOnDelete();
  });
  Schema::table('invoices', function (Blueprint $t) {
    $t->foreign('customer_id')->references('id')->on('customers')->restrictOnDelete();
    $t->foreign('quotation_id')->references('id')->on('quotations')->restrictOnDelete();
  });
  Schema::table('payroll_hours', function (Blueprint $t) {
    $t->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();
  });
  Schema::create('roles', function (Blueprint $t) {
    $t->string('id',190)->primary(); $t->string('name'); $t->json('data'); $t->boolean('protected')->default(false); $t->unsignedInteger('version')->default(1); $t->timestamps();
  });
  Schema::create('users', function (Blueprint $t) {
    $t->string('id',190)->primary(); $t->string('name'); $t->string('username')->unique(); $t->string('password');
    $t->string('role_id',190); $t->foreign('role_id')->references('id')->on('roles')->restrictOnDelete();
    $t->string('employee_id',190)->nullable()->unique(); $t->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();
    $t->string('department')->default(''); $t->json('data'); $t->boolean('active')->default(true); $t->boolean('is_root')->default(false);
    $t->boolean('must_change')->default(false); $t->unsignedInteger('failed_attempts')->default(0); $t->timestamp('locked_until')->nullable();
    $t->timestamp('last_login')->nullable(); $t->unsignedInteger('auth_version')->default(1); $t->unsignedInteger('version')->default(1);
    $t->rememberToken(); $t->timestamps();
  });
  Schema::create('sessions', function (Blueprint $t) {
    $t->string('id')->primary(); $t->string('user_id',190)->nullable()->index(); $t->string('ip_address',45)->nullable();
    $t->text('user_agent')->nullable(); $t->longText('payload'); $t->integer('last_activity')->index();
  });
  Schema::create('settings', function (Blueprint $t) { $t->string('id')->primary(); $t->json('value'); $t->unsignedInteger('version')->default(1); $t->timestamps(); });
  Schema::create('app_locks', function (Blueprint $t) { $t->string('id')->primary(); $t->unsignedBigInteger('revision')->default(0); });
  Schema::create('sequences', function (Blueprint $t) { $t->string('id')->primary(); $t->unsignedBigInteger('value')->default(0); });
  Schema::create('transactions', function (Blueprint $t) {
    $t->string('id',190)->primary(); $t->string('actor_id',190); $t->string('idempotency_key',190)->nullable();
    $t->string('request_hash',64); $t->json('changes'); $t->json('response')->nullable(); $t->string('undo_of',190)->nullable(); $t->timestamps();
    $t->unique(['actor_id','idempotency_key']);
  });
  Schema::create('audit_events', function (Blueprint $t) {
    $t->string('id',190)->primary(); $t->string('actor_id',190)->nullable()->index(); $t->string('actor_name');
    $t->string('transaction_id',190)->nullable()->index(); $t->string('module')->index(); $t->string('resource')->index();
    $t->string('record_id',190)->nullable()->index(); $t->string('action'); $t->text('message');
    $t->json('before_data')->nullable(); $t->json('after_data')->nullable(); $t->timestamp('created_at')->index();
  });
  Schema::create('stored_files', function (Blueprint $t) {
    $t->string('id',190)->primary(); $t->string('owner_id',190)->index(); $t->string('logical_key',190)->unique();
    $t->string('name'); $t->string('mime'); $t->string('kind'); $t->unsignedBigInteger('size'); $t->string('path'); $t->timestamps();
  });
  Schema::create('file_links', function (Blueprint $t) {
    $t->id(); $t->string('file_id',190); $t->foreign('file_id')->references('id')->on('stored_files')->cascadeOnDelete();
    $t->string('resource'); $t->string('record_id',190); $t->unique(['file_id','resource','record_id']);
  });
 }
 public function down(): void {
  Schema::disableForeignKeyConstraints();
  Schema::dropIfExists('file_links');
  Schema::dropIfExists('stored_files');
  Schema::dropIfExists('audit_events');
  Schema::dropIfExists('transactions');
  Schema::dropIfExists('sequences');
  Schema::dropIfExists('app_locks');
  Schema::dropIfExists('settings');
  Schema::dropIfExists('sessions');
  Schema::dropIfExists('users');
  Schema::dropIfExists('roles');
  Schema::dropIfExists('print_layouts');
  Schema::dropIfExists('payroll_runs');
  Schema::dropIfExists('payroll_hours');
  Schema::dropIfExists('journal_entries');
  Schema::dropIfExists('payments');
  Schema::dropIfExists('cash_accounts');
  Schema::dropIfExists('financial_documents');
  Schema::dropIfExists('invoices');
  Schema::dropIfExists('suppliers');
  Schema::dropIfExists('reservations');
  Schema::dropIfExists('stock_movements');
  Schema::dropIfExists('stock_items');
  Schema::dropIfExists('warehouses');
  Schema::dropIfExists('support_requests');
  Schema::dropIfExists('wisdom');
  Schema::dropIfExists('field_templates');
  Schema::dropIfExists('field_work');
  Schema::dropIfExists('work_presence');
  Schema::dropIfExists('admin_meetings');
  Schema::dropIfExists('admin_decisions');
  Schema::dropIfExists('executive_reports');
  Schema::dropIfExists('employment_contracts');
  Schema::dropIfExists('announcements');
  Schema::dropIfExists('recognition');
  Schema::dropIfExists('offboarding');
  Schema::dropIfExists('requisitions');
  Schema::dropIfExists('hr_approvals');
  Schema::dropIfExists('hr_requests');
  Schema::dropIfExists('employees');
  Schema::dropIfExists('tasks');
  Schema::dropIfExists('quotations');
  Schema::dropIfExists('service_contracts');
  Schema::dropIfExists('quotation_templates');
  Schema::dropIfExists('sales_teams');
  Schema::dropIfExists('salespeople');
  Schema::dropIfExists('services');
  Schema::dropIfExists('customers');
  Schema::enableForeignKeyConstraints();
 }
};
