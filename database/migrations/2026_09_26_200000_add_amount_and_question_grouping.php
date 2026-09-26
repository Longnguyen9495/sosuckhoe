<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lượng dùng nguyên văn của từng lần (ví dụ "16 UI" buổi sáng, "14 UI" buổi tối).
        Schema::table('schedule_items', function (Blueprint $table): void {
            $table->string('amount_text')->nullable()->after('dose_text');
        });

        // Nhóm câu hỏi theo chuyên khoa / bác sĩ thay vì ghép vào nội dung câu hỏi (REVIEW M3).
        Schema::table('questions', function (Blueprint $table): void {
            $table->string('specialty')->nullable()->after('doctor_id');
            $table->string('doctor_name')->nullable()->after('specialty');
            $table->date('due_date')->nullable()->after('doctor_name');
        });
    }

    public function down(): void
    {
        Schema::table('schedule_items', fn (Blueprint $table) => $table->dropColumn('amount_text'));
        Schema::table('questions', fn (Blueprint $table) => $table->dropColumn(['specialty', 'doctor_name', 'due_date']));
    }
};
