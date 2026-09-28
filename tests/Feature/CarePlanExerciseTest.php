<?php

namespace Tests\Feature;

use App\Models\CarePlan;
use App\Services\CarePlan\ExerciseLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CarePlanExerciseTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private array $auth;

    private string $patientId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $data = $this->postJson('/api/v1/auth/register', [
            'name' => 'Trần Thị Dung', 'phone' => '0900000111', 'birth_date' => '1950-05-02', 'accepted' => true,
        ])->assertCreated()->json('data');

        $this->auth = ['Authorization' => 'Bearer '.$data['token'], 'X-Tenant-ID' => $data['tenant']['id']];
        $this->patientId = $data['patient']['id'];

        // AI giả đọc ra: đái tháo đường, Metformin, HbA1c cao.
        $file = UploadedFile::fake()->createWithContent('don.png', base64_decode(self::PNG));
        $this->withHeaders($this->auth)->post("/api/v1/patients/{$this->patientId}/documents", ['file' => $file], ['Accept' => 'application/json'])->assertCreated();
    }

    public function test_care_plan_keeps_only_library_exercises_with_video(): void
    {
        $exercises = $this->withHeaders($this->auth)->postJson("/api/v1/patients/{$this->patientId}/care-plan")
            ->assertCreated()
            ->assertJsonStructure(['data' => ['exercise_safety', 'exercises' => [['id', 'title', 'why', 'frequency', 'caution', 'youtube_id', 'source']]]])
            ->json('data.exercises');

        // Bài AI bịa ra (không có trong thư viện) bị loại; bài hợp lệ giữ lý do và tần suất của AI.
        $this->assertSame(['walk_after_meal'], array_column($exercises, 'id'));
        $this->assertSame('15 phút sau ăn tối', $exercises[0]['frequency']);
        $this->assertSame(ExerciseLibrary::ITEMS['walk_after_meal']['youtube_id'], $exercises[0]['youtube_id']);
    }

    public function test_old_care_plan_without_exercises_gets_rule_based_suggestions(): void
    {
        $this->withHeaders($this->auth)->postJson("/api/v1/patients/{$this->patientId}/care-plan")->assertCreated();
        $plan = CarePlan::withoutGlobalScopes()->firstOrFail();
        $content = $plan->content;
        unset($content['exercises']);
        $plan->update(['content' => $content]);

        $ids = array_column($this->withHeaders($this->auth)->getJson("/api/v1/patients/{$this->patientId}/care-plan")->assertOk()->json('data.exercises'), 'id');

        $this->assertNotEmpty($ids);
        $this->assertContains('walk_after_meal', $ids);
        $this->assertNotContains('insulin_exercise_tips', $ids, 'Không dùng insulin thì không gợi ý bài dành cho người tiêm insulin.');
        $this->assertNotContains('neck', $ids, 'Không có bệnh cột sống cổ thì không gợi ý bài cổ vai gáy.');
    }

    public function test_tags_from_diagnoses_and_drugs(): void
    {
        $tags = ExerciseLibrary::tags([
            'age' => 72,
            'diagnoses' => ['Tăng huyết áp', 'Thoái hóa khớp gối'],
            'medications' => [['drug' => 'Lantus 100IU/ml', 'type' => 'insulin'], ['drug' => 'Amlodipin 5mg', 'type' => 'medication']],
            'lab_results' => [],
        ]);

        foreach (['insulin', 'diabetes', 'hypertension', 'joint', 'elderly'] as $tag) {
            $this->assertContains($tag, $tags);
        }
        $this->assertContains('insulin_exercise_tips', array_column(ExerciseLibrary::suggest(['age' => 72, 'diagnoses' => [], 'medications' => [['drug' => 'Lantus', 'type' => 'insulin']], 'lab_results' => []]), 'id'));
    }

    public function test_weekly_menu_drops_weekday_prefix_and_needs_seven_days(): void
    {
        $service = app(\App\Services\CarePlan\CarePlanService::class);
        $normalize = fn (array $week) => (fn () => $this->normalize(['diet' => ['weekly_menu' => $week]]))->call($service)['diet']['weekly_menu'];

        $week = array_map(fn ($d) => ['breakfast' => "$d — Phở gà ít bánh", 'lunch' => 'Nửa bát cơm'], ['Thứ Hai', 'Thứ Ba', 'Thứ Tư', 'Thứ Năm', 'Thứ Sáu', 'Thứ Bảy', 'Chủ nhật']);
        $clean = $normalize($week);
        $this->assertCount(7, $clean);
        $this->assertSame('Phở gà ít bánh', $clean[0]['breakfast']);
        $this->assertSame('Phở gà ít bánh', $clean[6]['breakfast']);

        // Thiếu ngày thì bỏ, màn hình dùng thực đơn mẫu.
        $this->assertSame([], $normalize(array_slice($week, 0, 5)));
    }

    public function test_every_library_item_has_a_valid_youtube_id(): void
    {
        foreach (ExerciseLibrary::ITEMS as $id => $e) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{11}$/', $e['youtube_id'], $id);
        }
    }
}
