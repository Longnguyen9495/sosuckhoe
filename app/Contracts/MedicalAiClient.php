<?php

namespace App\Contracts;

interface MedicalAiClient
{
    /**
     * Đọc một ảnh phiếu khám / đơn thuốc / kết quả xét nghiệm / vỏ thuốc.
     * Kết quả CHƯA lọc dữ liệu định danh — bên gọi phải cho qua SensitiveDataScrubber trước khi lưu.
     *
     * @return array<string, mixed> Cấu trúc mô tả trong App\Services\Ai\MedicalPrompts::DOCUMENT_SCHEMA
     */
    public function analyzeDocument(string $binary, string $mime): array;

    /**
     * Lập kế hoạch chăm sóc (chế độ ăn, sinh hoạt, theo dõi) từ thuốc đang dùng và kết quả xét nghiệm.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed> Cấu trúc mô tả trong App\Services\Ai\MedicalPrompts::CARE_PLAN_SCHEMA
     */
    public function generateCarePlan(array $context): array;

    /**
     * Thực đơn MỘT ngày, đa dạng, bám nguyên tắc ăn uống của kế hoạch chăm sóc và tránh lặp món gần đây.
     *
     * @param  array<string, mixed>  $context  xem DailyPlanService::menuContext()
     * @return array{breakfast?: string, lunch?: string, dinner?: string, snacks?: string, tip?: string}
     */
    public function generateDailyMenu(array $context): array;

    /**
     * Nhận xét đường huyết MỘT ngày, viết từ số liệu đã tính sẵn (không tự tính lại), không bao giờ khuyên đổi liều.
     *
     * @param  array<string, mixed>  $context  xem GlucoseInsightService::aiContext()
     * @return array{summary?: string, points?: list<array{tone?: string, text?: string}>, ask_doctor?: string|null}
     */
    public function generateGlucoseNote(array $context): array;

    /** Tên model để ghi nguồn. */
    public function model(): string;
}
