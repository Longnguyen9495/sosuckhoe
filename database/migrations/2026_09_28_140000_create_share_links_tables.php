<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link chia sẻ hồ sơ (chỉ xem) do người bệnh tự tạo để gửi dược sĩ / bác sĩ / người thân.
 * Chỉ lưu SHA-256 của mã link và bcrypt của PIN — không lưu bản rõ. Mỗi lượt mở ghi vào share_link_views.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('share_links', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('patient_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('label', 60);
            $t->string('token_hash', 64)->unique();
            $t->string('pin_hash')->nullable();
            $t->boolean('show_full_name')->default(false);
            $t->boolean('include_documents')->default(false);
            $t->timestamp('expires_at');
            $t->timestamp('revoked_at')->nullable();
            $t->unsignedInteger('view_count')->default(0);
            $t->timestamp('last_viewed_at')->nullable();
            $t->unsignedTinyInteger('failed_attempts')->default(0);
            $t->timestamp('locked_until')->nullable();
            $t->timestamp('last_lockout_at')->nullable(); // báo cho người bệnh: có người nhập sai PIN nhiều lần
            $t->timestamp('consented_at');
            $t->timestamps();
            $t->index(['patient_id', 'revoked_at', 'expires_at']);
        });

        Schema::create('share_link_views', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('share_link_id')->constrained()->cascadeOnDelete();
            $t->timestamp('viewed_at');
            $t->string('ip_hint', 45)->nullable(); // IP đã che phần cuối (VD 113.161.72.x)
            $t->string('device', 60)->nullable();  // "Điện thoại · Safari" — không lưu nguyên User-Agent
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('share_link_views');
        Schema::dropIfExists('share_links');
    }
};
