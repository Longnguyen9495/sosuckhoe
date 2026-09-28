<?php

namespace App\Services\Ai;

use App\Contracts\AiOcrClient;
use App\Contracts\MedicalAiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Gọi API tương thích OpenAI Chat Completions (base_url + api_key + model trong config/services.php → ai).
 * Không ghi nội dung ảnh hay kết quả y tế vào log; chỉ ghi mã lỗi.
 */
final class OpenAiCompatibleClient implements MedicalAiClient, AiOcrClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $modelName,
        private readonly int $timeout = 150,
    ) {
        if ($this->apiKey === '') {
            throw new RuntimeException('Chưa cấu hình AI_API_KEY.');
        }
    }

    public function model(): string
    {
        return $this->modelName;
    }

    public function analyzeDocument(string $binary, string $mime): array
    {
        $dataUrl = 'data:'.$mime.';base64,'.base64_encode($binary);

        return $this->chatJson([
            ['role' => 'system', 'content' => MedicalPrompts::documentSystem()],
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => 'Đọc ảnh giấy tờ y tế sau và trả về JSON theo đúng cấu trúc đã mô tả.'],
                ['type' => 'image_url', 'image_url' => ['url' => $dataUrl, 'detail' => 'high']],
            ]],
        ]);
    }

    public function generateCarePlan(array $context): array
    {
        return $this->chatJson([
            ['role' => 'system', 'content' => MedicalPrompts::carePlanSystem()],
            ['role' => 'user', 'content' => "Dữ liệu người bệnh (JSON):\n".json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)],
        ]);
    }

    public function generateDailyMenu(array $context): array
    {
        return $this->chatJson([
            ['role' => 'system', 'content' => MedicalPrompts::dailyMenuSystem()],
            ['role' => 'user', 'content' => "Dữ liệu (JSON):\n".json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)],
        ]);
    }

    public function generateGlucoseNote(array $context): array
    {
        return $this->chatJson([
            ['role' => 'system', 'content' => MedicalPrompts::glucoseNoteSystem()],
            ['role' => 'user', 'content' => "Dữ liệu (JSON):\n".json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)],
        ]);
    }

    /** Tương thích màn "Chụp đơn" cũ: chỉ lấy danh sách thuốc. */
    public function recognize(string $imageBase64): array
    {
        $binary = base64_decode($imageBase64, true);
        if ($binary === false) {
            return [];
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($binary) ?: 'image/jpeg';

        return array_map(fn (array $m) => [
            'drug_name' => (string) ($m['drug_name'] ?? ''),
            'strength' => null,
            'quantity' => trim(($m['quantity'] ?? '').' '.($m['unit'] ?? '')) ?: null,
            'dosage_instructions' => $m['dose_text'] ?? null,
        ], array_values(array_filter($this->analyzeDocument($binary, $mime)['medications'] ?? [], 'is_array')));
    }

    private function chatJson(array $messages): array
    {
        $payload = ['model' => $this->modelName, 'messages' => $messages, 'response_format' => ['type' => 'json_object']];

        try {
            $response = Http::withToken($this->apiKey)
                ->acceptJson()
                ->timeout($this->timeout)
                ->connectTimeout(15)
                ->retry(2, 1500, fn ($e) => $e instanceof ConnectionException || ($e instanceof RequestException && $e->response->serverError()), throw: false)
                ->post($this->baseUrl.'/chat/completions', $payload);
        } catch (ConnectionException) {
            throw new RuntimeException('Không kết nối được dịch vụ AI.');
        }

        if (! $response->successful()) {
            Log::warning('AI request failed', ['status' => $response->status()]);
            throw new RuntimeException('Dịch vụ AI trả lỗi ('.$response->status().').');
        }

        $content = (string) data_get($response->json(), 'choices.0.message.content', '');

        return self::decodeJson($content);
    }

    /** Lấy đối tượng JSON từ câu trả lời (chịu được ```json … ``` hoặc chữ thừa hai đầu). */
    public static function decodeJson(string $content): array
    {
        $content = trim($content);
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $content) ?? $content;
        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start === false || $end === false || $end < $start) {
            throw new RuntimeException('AI không trả về dữ liệu có cấu trúc.');
        }
        $data = json_decode(substr($content, $start, $end - $start + 1), true);
        if (! is_array($data)) {
            throw new RuntimeException('AI không trả về dữ liệu có cấu trúc.');
        }

        return $data;
    }
}
