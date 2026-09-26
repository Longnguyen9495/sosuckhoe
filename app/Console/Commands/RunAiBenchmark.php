<?php

namespace App\Console\Commands;

use App\Contracts\AiOcrClient;
use App\Services\Ai\AiBenchmarkEvaluator;
use Illuminate\Console\Command;

class RunAiBenchmark extends Command
{
    protected $signature = 'ai:benchmark {--dataset=storage/app/ai_benchmark/dataset.json}';
    protected $description = 'Chạy bộ đánh giá AI OCR trên tập dữ liệu mẫu';

    public function handle(AiOcrClient $client): int
    {
        $path = base_path($this->option('dataset'));
        if (! file_exists($path)) {
            $this->error("Không tìm thấy file dataset: {$path}");
            return self::FAILURE;
        }

        $dataset = json_decode(file_get_contents($path), true);
        $prescriptions = $dataset['prescriptions'] ?? [];

        if (empty($prescriptions)) {
            $this->error('Dataset rỗng.');
            return self::FAILURE;
        }

        $evaluator = new AiBenchmarkEvaluator($client);
        $result = $evaluator->evaluate($prescriptions);

        $this->info('=== Kết quả đánh giá AI OCR ===');
        $this->table(
            ['Chỉ số', 'Giá trị'],
            [
                ['Tổng số dòng', $result['total_lines']],
                ['Dòng đúng', $result['correct_lines']],
                ['Độ chính xác (%)', $result['accuracy_percent'] . '%'],
            ]
        );

        if ($result['accuracy_percent'] >= 95) {
            $this->info('✅ Đạt mục tiêu ≥ 95%');
        } else {
            $this->warn('⚠️ Chưa đạt mục tiêu 95%, cần điều chỉnh prompt hoặc mô hình.');
        }

        if ($this->option('verbose')) {
            foreach ($result['details'] as $detail) {
                $status = $detail['correct'] ? '✅' : '❌';
                $this->line("{$status} {$detail['prescription_id']} — line {$detail['line_index']}: {$detail['expected']['drug_name']}");
            }
        }

        return self::SUCCESS;
    }
}
