<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Đăng ký nhanh (tên + SĐT + ngày sinh, mật khẩu mặc định), AI đọc ảnh phiếu
 * và kế hoạch chăm sóc (chế độ ăn, sinh hoạt, theo dõi) do AI lập.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->date('birth_date')->nullable()->after('phone');
            $t->timestamp('password_changed_at')->nullable()->after('password');
        });

        Schema::table('patients', function (Blueprint $t) {
            $t->date('birth_date')->nullable()->after('birth_year');
        });

        Schema::table('documents', function (Blueprint $t) {
            // pending | done | failed | skipped — trạng thái AI đọc ảnh.
            $t->string('ai_status', 20)->nullable()->after('analysis');
            $t->string('ai_error')->nullable()->after('ai_status');
            $t->foreignUlid('prescription_id')->nullable()->after('ai_error')->constrained()->nullOnDelete();
        });

        Schema::create('care_plans', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('patient_id')->constrained()->cascadeOnDelete();
            $t->json('content');
            $t->json('sources')->nullable();
            $t->string('model')->nullable();
            $t->foreignUlid('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['patient_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('care_plans');
        Schema::table('documents', function (Blueprint $t) {
            $t->dropConstrainedForeignId('prescription_id');
            $t->dropColumn(['ai_status', 'ai_error']);
        });
        Schema::table('patients', fn (Blueprint $t) => $t->dropColumn('birth_date'));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['birth_date', 'password_changed_at']));
    }
};
