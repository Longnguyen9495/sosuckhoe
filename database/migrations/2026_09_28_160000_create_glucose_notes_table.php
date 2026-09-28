<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nhận xét đường huyết của từng ngày (GlucoseInsightService): AI viết dựa trên số liệu code đã tính.
 * `input_hash` = dấu của các lần đo + xét nghiệm đã dùng; có số đo mới thì hash đổi → viết lại.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('glucose_notes', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('patient_id')->constrained()->cascadeOnDelete();
            $t->date('note_date');
            $t->string('input_hash', 64);
            $t->json('content');
            $t->string('source', 20); // ai | rule (AI lỗi → nhận xét theo quy tắc)
            $t->string('model', 100)->nullable();
            $t->timestamps();
            $t->unique(['patient_id', 'note_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('glucose_notes');
    }
};
