<?php

namespace Tests\Feature;

use App\Models\ShareLink;
use App\Models\ShareLinkView;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Link chia sẻ hồ sơ chỉ xem: PIN, khoá khi sai nhiều lần, hạn dùng, thu hồi, không lộ thông tin định danh. */
class ShareLinkTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private array $auth;

    private string $patientId;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $data = $this->postJson('/api/v1/auth/register', [
            'name' => 'Trần Thị Dung', 'phone' => '0900005678', 'birth_date' => '1968-05-02', 'accepted' => true,
        ])->assertCreated()->json('data');
        $this->auth = ['Authorization' => 'Bearer '.$data['token'], 'X-Tenant-ID' => $data['tenant']['id']];
        $this->patientId = $data['patient']['id'];
        $this->tenantId = $data['tenant']['id'];

        // AI giả đọc ra đơn thuốc, xét nghiệm, chẩn đoán.
        $file = UploadedFile::fake()->createWithContent('don.png', base64_decode(self::PNG));
        $this->withHeaders($this->auth)->post("/api/v1/patients/{$this->patientId}/documents", ['file' => $file], ['Accept' => 'application/json'])->assertCreated();
    }

    private function create(array $o = []): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($this->auth)->postJson("/api/v1/patients/{$this->patientId}/share-links", $o + [
            'label' => 'Dược sĩ', 'expires_in_days' => 7, 'pin' => null, 'show_full_name' => false, 'include_documents' => false, 'consent' => true,
        ]);
    }

    private function token(string $url): string
    {
        return substr($url, strrpos($url, '/') + 1);
    }

    /** Gọi trang công khai như người lạ: bỏ header đăng nhập của người bệnh. */
    private function guest(): static
    {
        $this->flushHeaders();

        return $this;
    }

    public function test_link_without_pin_shows_medical_summary_but_no_identifiers(): void
    {
        $today = now()->toDateString();
        $this->withHeaders($this->auth)->postJson("/api/v1/patients/{$this->patientId}/prescriptions", [
            'prescribed_at' => $today, 'starts_at' => $today,
            'items' => [['drug_name' => 'Metformin 500mg', 'dose_text' => 'Sáng 1 viên sau ăn', 'usage_rule' => ['type' => 'medication', 'doses' => [['anchor' => 'breakfast', 'offset_min' => 30, 'amount_text' => '1 viên']]]]],
        ])->assertCreated();
        $created = $this->create()->assertCreated()->assertJsonPath('data.has_pin', false)->json('data');
        $this->assertStringContainsString('/#/s/', $created['url']);
        $this->assertStringStartsWith('<?xml', $created['qr_svg']);
        $token = $this->token($created['url']);

        $this->guest()->getJson("/api/v1/shared/{$token}")->assertOk()->assertJsonPath('data.requires_pin', false)
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $res = $this->guest()->postJson("/api/v1/shared/{$token}/open")->assertOk();
        $res->assertJsonPath('data.patient.name', 'D.')
            ->assertJsonPath('data.medications.0.drug', 'Metformin 500mg')
            ->assertJsonPath('data.lab_results.0.name', 'HbA1c')
            ->assertJsonPath('data.documents', []);
        $body = $res->getContent();
        foreach (['0900005678', '5678', 'Trần Thị Dung', 'DN4797931234567', '001234567890'] as $secret) {
            $this->assertStringNotContainsString($secret, $body, "Lộ: {$secret}");
        }

        app(TenantContext::class)->set(Tenant::findOrFail($this->tenantId));
        $link = ShareLink::firstOrFail();
        $this->assertSame(1, $link->view_count);
        $view = ShareLinkView::firstOrFail();
        $this->assertNotNull($view->device);
        // Máy chủ không lưu mã link bản rõ.
        $this->assertNotSame($token, $link->token_hash);
        $this->assertSame(hash('sha256', $token), $link->token_hash);
    }

    public function test_weak_pins_are_rejected(): void
    {
        foreach (['1234', '0000', '9876', '1212', '1968', '0205', '5678', '12a4', '123'] as $pin) {
            $this->create(['pin' => $pin])->assertStatus(422)->assertJsonValidationErrors('pin');
        }
        $suggested = $this->withHeaders($this->auth)->getJson("/api/v1/patients/{$this->patientId}/share-links/pin")->assertOk()->json('pin');
        $this->create(['pin' => $suggested])->assertCreated()->assertJsonPath('data.pin', $suggested)->assertJsonPath('data.has_pin', true);
    }

    public function test_pin_is_required_and_locks_after_five_wrong_tries(): void
    {
        $token = $this->token($this->create(['pin' => '4827'])->assertCreated()->json('data.url'));

        $this->guest()->getJson("/api/v1/shared/{$token}")->assertJsonPath('data.requires_pin', true);
        $this->guest()->postJson("/api/v1/shared/{$token}/open")->assertStatus(401)->assertJsonPath('code', 'PIN_REQUIRED')->assertJsonMissingPath('data');

        for ($i = 1; $i <= 4; $i++) {
            $this->guest()->postJson("/api/v1/shared/{$token}/open", ['pin' => '1111'])->assertStatus(401)->assertJsonPath('attempts_left', 5 - $i);
        }
        $this->guest()->postJson("/api/v1/shared/{$token}/open", ['pin' => '1111'])->assertStatus(423)->assertJsonPath('code', 'LOCKED');
        // Đang khoá thì PIN đúng cũng không mở được.
        $this->guest()->postJson("/api/v1/shared/{$token}/open", ['pin' => '4827'])->assertStatus(423);

        // Người bệnh thấy cảnh báo có người nhập sai nhiều lần.
        $this->withHeaders($this->auth)->getJson("/api/v1/patients/{$this->patientId}/share-links")->assertOk()->assertJsonPath('data.0.view_count', 0)
            ->assertJson(fn ($json) => $json->whereType('data.0.last_lockout_at', 'string')->etc());

        $this->travel(16)->minutes();
        $this->guest()->postJson("/api/v1/shared/{$token}/open", ['pin' => '4827'])->assertOk()->assertJsonPath('data.patient.age', 58);
    }

    public function test_revoked_and_expired_links_look_the_same_as_unknown_links(): void
    {
        $created = $this->create()->json('data');
        $token = $this->token($created['url']);

        $this->withHeaders($this->auth)->deleteJson("/api/v1/patients/{$this->patientId}/share-links/{$created['id']}")->assertOk()->assertJsonPath('data.status', 'revoked');
        $gone = $this->guest()->getJson("/api/v1/shared/{$token}")->assertNotFound()->json('message');
        $this->guest()->postJson("/api/v1/shared/{$token}/open")->assertNotFound();

        $expired = $this->token($this->withHeaders($this->auth)->postJson("/api/v1/patients/{$this->patientId}/share-links", ['label' => 'Bác sĩ', 'expires_in_days' => 1, 'consent' => true])->json('data.url'));
        $this->travel(25)->hours();
        $this->assertSame($gone, $this->guest()->getJson("/api/v1/shared/{$expired}")->assertNotFound()->json('message'));
        $this->assertSame($gone, $this->guest()->getJson('/api/v1/shared/'.str_repeat('a', 40))->assertNotFound()->json('message'));
    }

    public function test_full_name_and_documents_only_when_patient_allows(): void
    {
        $token = $this->token($this->create()->json('data.url'));
        $session = $this->guest()->postJson("/api/v1/shared/{$token}/open")->json('session');
        app(TenantContext::class)->set(Tenant::findOrFail($this->tenantId));
        $docId = \App\Models\Document::firstOrFail()->id;
        $this->guest()->withHeaders(['X-Share-Session' => $session])->get("/api/v1/shared-documents/{$docId}")->assertNotFound();

        $token = $this->token($this->create(['show_full_name' => true, 'include_documents' => true])->json('data.url'));
        $open = $this->guest()->postJson("/api/v1/shared/{$token}/open")->assertJsonPath('data.patient.name', 'Trần Thị Dung')->assertJsonPath('data.documents.0.id', $docId);
        $this->guest()->withHeaders(['X-Share-Session' => $open->json('session')])->get("/api/v1/shared-documents/{$docId}")->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->guest()->get("/api/v1/shared-documents/{$docId}")->assertNotFound();
    }

    public function test_consent_is_required(): void
    {
        $this->create(['consent' => false])->assertStatus(422)->assertJsonValidationErrors('consent');
    }
}
