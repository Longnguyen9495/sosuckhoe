<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Thực đơn + bài tập của từng ngày do lệnh `careplan:daily` (chạy mỗi sáng) lên, để mỗi ngày một khác.
 * Không có dòng cho ngày nào thì màn Hôm nay dùng thực đơn 7 ngày của kế hoạch chăm sóc.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_plans', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('patient_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('care_plan_id')->nullable()->constrained()->nullOnDelete();
            $t->date('plan_date');
            $t->json('menu');
            $t->json('exercise_ids');
            $t->string('tip', 500)->nullable();
            $t->string('source', 20); // ai | weekly (AI lỗi → thực đơn 7 ngày)
            $t->timestamps();
            $t->unique(['patient_id', 'plan_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_plans');
    }
};
