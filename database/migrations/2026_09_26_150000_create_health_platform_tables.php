<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drugs', function (Blueprint $t) {
            $t->ulid('id')->primary(); $t->string('brand_name'); $t->string('active_ingredient')->nullable();
            $t->string('strength')->nullable(); $t->string('form'); $t->string('unit'); $t->text('general_warning')->nullable(); $t->timestamps();
            $t->index(['brand_name', 'active_ingredient']);
        });
        Schema::create('condition_templates', function (Blueprint $t) {
            $t->ulid('id')->primary(); $t->string('code')->unique(); $t->string('icd_code')->nullable(); $t->string('name'); $t->json('metrics')->nullable(); $t->unsignedInteger('version')->default(1); $t->timestamps();
        });
        Schema::create('template_monitoring', function (Blueprint $t) {
            $t->ulid('id')->primary(); $t->foreignUlid('condition_template_id')->constrained()->cascadeOnDelete(); $t->string('phase'); $t->unsignedInteger('duration_days')->nullable(); $t->json('schedule'); $t->timestamps();
        });
        Schema::create('template_thresholds', function (Blueprint $t) {
            $t->ulid('id')->primary(); $t->foreignUlid('condition_template_id')->constrained()->cascadeOnDelete(); $t->string('metric'); $t->string('context')->default('general'); $t->json('ranges'); $t->timestamps();
        });
        Schema::create('content_articles', function (Blueprint $t) {
            $t->ulid('id')->primary(); $t->foreignUlid('condition_template_id')->nullable()->constrained()->nullOnDelete(); $t->string('type'); $t->string('title'); $t->longText('content'); $t->boolean('is_draft')->default(true); $t->timestamps();
        });
        Schema::create('consent_versions', function (Blueprint $t) {
            $t->ulid('id')->primary(); $t->string('version')->unique(); $t->string('type'); $t->longText('content'); $t->date('effective_date'); $t->boolean('is_draft')->default(true); $t->timestamps();
        });
        Schema::create('tenants', function (Blueprint $t) {
            $t->ulid('id')->primary(); $t->string('name'); $t->enum('type', ['family', 'clinic']); $t->string('plan')->default('free'); $t->string('status')->default('active'); $t->timestamps();
        });
        Schema::create('tenant_members', function (Blueprint $t) {
            $t->ulid('id')->primary(); $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete(); $t->foreignUlid('user_id')->constrained()->cascadeOnDelete(); $t->enum('role', ['owner', 'member']); $t->timestamps(); $t->unique(['tenant_id', 'user_id']);
        });
        Schema::create('patients', function (Blueprint $t) {
            $t->ulid('id')->primary(); $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete(); $t->string('full_name'); $t->unsignedSmallInteger('birth_year')->nullable(); $t->enum('gender', ['female', 'male', 'other', 'unknown'])->default('unknown'); $t->text('allergies')->nullable(); $t->string('timezone')->default('Asia/Ho_Chi_Minh'); $t->timestamps(); $t->index(['tenant_id', 'full_name']);
        });
        Schema::create('patient_access', function (Blueprint $t) {
            $t->ulid('id')->primary(); $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete(); $t->foreignUlid('patient_id')->constrained()->cascadeOnDelete(); $t->foreignUlid('user_id')->constrained()->cascadeOnDelete(); $t->enum('role', ['caregiver', 'patient', 'doctor', 'viewer']); $t->timestamps(); $t->unique(['patient_id', 'user_id']);
        });
        Schema::create('invitations', function (Blueprint $t) {
            $t->ulid('id')->primary(); $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete(); $t->foreignUlid('invited_by')->constrained('users')->cascadeOnDelete(); $t->foreignUlid('patient_id')->nullable()->constrained()->cascadeOnDelete(); $t->string('recipient'); $t->enum('role', ['caregiver', 'patient', 'doctor', 'viewer']); $t->string('token_hash', 64)->unique(); $t->timestamp('expires_at'); $t->timestamp('accepted_at')->nullable(); $t->timestamps();
        });
        Schema::create('consents', function (Blueprint $t) {
            $t->ulid('id')->primary(); $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete(); $t->foreignUlid('user_id')->constrained()->cascadeOnDelete(); $t->foreignUlid('consent_version_id')->constrained()->restrictOnDelete(); $t->timestamp('consented_at'); $t->string('ip_address', 45)->nullable(); $t->timestamp('withdrawn_at')->nullable(); $t->timestamps();
        });
        Schema::create('patient_routines', function (Blueprint $t) {
            $t->ulid('id')->primary(); $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete(); $t->foreignUlid('patient_id')->constrained()->cascadeOnDelete(); $t->time('wake_time'); $t->time('breakfast_time'); $t->time('lunch_time'); $t->time('dinner_time'); $t->time('sleep_time'); $t->date('effective_from'); $t->timestamps();
        });
        Schema::create('patient_conditions', function (Blueprint $t) {
            $t->ulid('id')->primary(); $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete(); $t->foreignUlid('patient_id')->constrained()->cascadeOnDelete(); $t->foreignUlid('condition_template_id')->nullable()->constrained()->nullOnDelete(); $t->date('diagnosed_at')->nullable(); $t->string('priority')->default('normal'); $t->text('notes')->nullable(); $t->timestamps();
        });
        Schema::create('patient_thresholds', function (Blueprint $t) {
            $t->ulid('id')->primary(); $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete(); $t->foreignUlid('patient_id')->constrained()->cascadeOnDelete(); $t->string('metric'); $t->string('context')->default('general'); $t->json('ranges'); $t->enum('source', ['template', 'doctor', 'owner'])->default('template'); $t->foreignUlid('confirmed_by')->nullable()->constrained('users')->nullOnDelete(); $t->timestamp('confirmed_at')->nullable(); $t->timestamps(); $t->unique(['patient_id', 'metric', 'context']);
        });
        Schema::create('threshold_histories', function (Blueprint $t) {
            $t->ulid('id')->primary(); $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete(); $t->foreignUlid('patient_id')->constrained()->cascadeOnDelete(); $t->foreignUlid('patient_threshold_id')->constrained()->cascadeOnDelete(); $t->foreignUlid('changed_by')->constrained('users'); $t->json('old_ranges')->nullable(); $t->json('new_ranges'); $t->timestamps();
        });
        Schema::create('documents', function (Blueprint $t) {
            $t->ulid('id')->primary(); $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete(); $t->foreignUlid('patient_id')->constrained()->cascadeOnDelete(); $t->string('encrypted_path'); $t->string('type'); $t->date('document_date')->nullable(); $t->string('department')->nullable(); $t->string('doctor_name')->nullable(); $t->json('analysis')->nullable(); $t->foreignUlid('duplicate_of_id')->nullable()->constrained('documents')->nullOnDelete(); $t->timestamps();
        });
        Schema::create('lab_results', function (Blueprint $t) {
            $t->ulid('id')->primary(); $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete(); $t->foreignUlid('patient_id')->constrained()->cascadeOnDelete(); $t->foreignUlid('document_id')->nullable()->constrained()->nullOnDelete(); $t->string('metric'); $t->string('value'); $t->string('unit')->nullable(); $t->string('reference_range')->nullable(); $t->string('flag')->nullable(); $t->date('measured_at'); $t->timestamps();
        });
        Schema::create('prescriptions', function (Blueprint $t) {
            $t->ulid('id')->primary(); $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete(); $t->foreignUlid('patient_id')->constrained()->cascadeOnDelete(); $t->foreignUlid('document_id')->nullable()->constrained()->nullOnDelete(); $t->string('doctor_name')->nullable(); $t->date('prescribed_at'); $t->date('starts_at'); $t->date('ends_at')->nullable(); $t->enum('status', ['draft', 'active', 'closed'])->default('active'); $t->timestamps();
        });
        Schema::create('prescription_items', function (Blueprint $t) {
            $t->ulid('id')->primary(); $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete(); $t->foreignUlid('patient_id')->constrained()->cascadeOnDelete(); $t->foreignUlid('prescription_id')->constrained()->cascadeOnDelete(); $t->foreignUlid('drug_id')->nullable()->constrained()->nullOnDelete(); $t->string('drug_name_snapshot'); $t->string('dose_text'); $t->json('usage_rule')->nullable(); $t->decimal('prescribed_quantity', 12, 2)->nullable(); $t->decimal('purchased_quantity', 12, 2)->nullable(); $t->string('quantity_unit')->nullable(); $t->boolean('is_long_term')->default(false); $t->boolean('requires_manual_time')->default(false); $t->timestamps();
        });
        Schema::create('schedule_items', function (Blueprint $t) {
            $t->ulid('id')->primary(); $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete(); $t->foreignUlid('patient_id')->constrained()->cascadeOnDelete(); $t->foreignUlid('prescription_item_id')->nullable()->constrained()->cascadeOnDelete(); $t->time('scheduled_time'); $t->string('type'); $t->string('title'); $t->string('dose_text')->nullable(); $t->json('day_rule')->nullable(); $t->date('starts_at'); $t->date('ends_at')->nullable(); $t->timestamps(); $t->index(['patient_id', 'starts_at', 'ends_at']);
        });
        Schema::create('monitoring_plans', function (Blueprint $t) {
            $t->ulid('id')->primary(); $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete(); $t->foreignUlid('patient_id')->constrained()->cascadeOnDelete(); $t->string('metric'); $t->string('phase'); $t->date('starts_at'); $t->date('ends_at')->nullable(); $t->json('schedule'); $t->timestamps();
        });
        Schema::create('logs', function (Blueprint $t) {
            $t->ulid('id')->primary(); $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete(); $t->foreignUlid('patient_id')->constrained()->cascadeOnDelete(); $t->date('log_date'); $t->foreignUlid('schedule_item_id')->nullable()->constrained()->nullOnDelete(); $t->boolean('completed')->default(false); $t->timestamp('completed_at')->nullable(); $t->foreignUlid('recorded_by')->nullable()->constrained('users')->nullOnDelete(); $t->json('meta')->nullable(); $t->timestamps(); $t->unique(['patient_id', 'log_date', 'schedule_item_id']);
        });
        Schema::create('readings', function (Blueprint $t) {
            $t->ulid('id')->primary(); $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete(); $t->foreignUlid('patient_id')->constrained()->cascadeOnDelete(); $t->string('type'); $t->string('context')->default('general'); $t->timestamp('measured_at'); $t->json('values'); $t->string('evaluation')->nullable(); $t->foreignUlid('recorded_by')->nullable()->constrained('users')->nullOnDelete(); $t->timestamps(); $t->index(['patient_id', 'type', 'measured_at']);
        });
        Schema::create('events', function (Blueprint $t) {
            $t->ulid('id')->primary(); $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete(); $t->foreignUlid('patient_id')->constrained()->cascadeOnDelete(); $t->date('event_date'); $t->date('due_date')->nullable(); $t->enum('type', ['appointment', 'test', 'purchase', 'vaccination', 'other']); $t->string('title'); $t->text('description')->nullable(); $t->string('status')->default('pending'); $t->timestamps();
        });
        Schema::create('alerts', function (Blueprint $t) {
            $t->ulid('id')->primary(); $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete(); $t->foreignUlid('patient_id')->constrained()->cascadeOnDelete(); $t->foreignUlid('reading_id')->nullable()->constrained()->cascadeOnDelete(); $t->foreignUlid('event_id')->nullable()->constrained()->cascadeOnDelete(); $t->enum('level', ['info', 'warning', 'red']); $t->text('content'); $t->json('sent_to')->nullable(); $t->timestamp('seen_at')->nullable(); $t->timestamps();
        });
        Schema::create('questions', function (Blueprint $t) {
            $t->ulid('id')->primary(); $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete(); $t->foreignUlid('patient_id')->constrained()->cascadeOnDelete(); $t->foreignUlid('doctor_id')->nullable()->constrained('users')->nullOnDelete(); $t->foreignUlid('asked_by')->constrained('users'); $t->text('question'); $t->timestamp('asked_at')->nullable(); $t->text('answer')->nullable(); $t->foreignUlid('answered_by')->nullable()->constrained('users')->nullOnDelete(); $t->timestamp('answered_at')->nullable(); $t->timestamps();
        });
        Schema::create('notes', function (Blueprint $t) {
            $t->ulid('id')->primary(); $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete(); $t->foreignUlid('patient_id')->constrained()->cascadeOnDelete(); $t->foreignUlid('author_id')->constrained('users'); $t->enum('type', ['family', 'doctor']); $t->text('content'); $t->timestamps();
        });
        Schema::create('push_subscriptions', function (Blueprint $t) {
            $t->ulid('id')->primary(); $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete(); $t->foreignUlid('user_id')->constrained()->cascadeOnDelete(); $t->text('endpoint'); $t->text('public_key'); $t->text('auth_token'); $t->string('device')->nullable(); $t->boolean('enabled')->default(true); $t->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->ulid('id')->primary(); $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete(); $t->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete(); $t->string('action'); $t->string('subject_type'); $t->string('subject_id', 26)->nullable(); $t->string('ip_address', 45)->nullable(); $t->json('metadata')->nullable(); $t->timestamps(); $t->index(['tenant_id', 'subject_type', 'subject_id']);
        });
        Schema::create('otp_codes', function (Blueprint $t) {
            $t->ulid('id')->primary(); $t->string('recipient')->index(); $t->string('code_hash'); $t->timestamp('expires_at'); $t->timestamp('used_at')->nullable(); $t->unsignedTinyInteger('attempts')->default(0); $t->timestamps();
        });
        Schema::create('ai_prescription_drafts', function (Blueprint $t) {
            $t->ulid('id')->primary(); $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete(); $t->foreignUlid('patient_id')->constrained()->cascadeOnDelete(); $t->foreignUlid('document_id')->constrained()->cascadeOnDelete(); $t->json('suggestions'); $t->json('confirmed_lines')->nullable(); $t->string('status')->default('pending'); $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['ai_prescription_drafts','otp_codes','audit_logs','push_subscriptions','notes','questions','alerts','events','readings','logs','monitoring_plans','schedule_items','prescription_items','prescriptions','lab_results','documents','threshold_histories','patient_thresholds','patient_conditions','patient_routines','consents','invitations','patient_access','patients','tenant_members','tenants','consent_versions','content_articles','template_thresholds','template_monitoring','condition_templates','drugs'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
