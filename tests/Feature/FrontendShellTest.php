<?php

namespace Tests\Feature;

use Tests\TestCase;

class FrontendShellTest extends TestCase
{
    public function test_frontend_shell_is_spa_mount_point_without_hardcoded_patient(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Sổ Sức Khỏe')
            ->assertSee('id="app"', false)
            ->assertSee('<div id="app"></div>', false)
            ->assertDontSee('Bà D.')
            ->assertDontSee('BHYT');
        // Khung trang không chứa dữ liệu người bệnh viết cứng (mã hồ sơ, số định danh…).
        $this->assertDoesNotMatchRegularExpression('/(?<![\w.-])\d{9,}(?![\w.-])/', $this->get('/')->getContent());
    }

    public function test_html_contains_base_url_meta_tag(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('meta name="app-base-url"', false);
    }

    public function test_manifest_webmanifest_returns_dynamic_json(): void
    {
        $response = $this->get('/manifest.webmanifest');
        $response->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json');
        $body = $response->json();
        $this->assertSame('Sổ Sức Khỏe', $body['name']);
        $this->assertStringStartsWith('http', $body['start_url']);
        $this->assertStringStartsWith('http', $body['scope']);
    }

    public function test_hidden_overlays_are_not_forced_visible_by_component_css(): void
    {
        $css = file_get_contents(resource_path('css/components.css'));

        $this->assertStringContainsString('.sheet-overlay[hidden]', $css);
        $this->assertStringContainsString('.confirm-overlay[hidden]', $css);
    }

    public function test_service_worker_uses_dynamic_base_path_and_api_prefix(): void
    {
        $this->assertFileExists(public_path('sw.js'));
        $content = file_get_contents(public_path('sw.js'));
        $this->assertStringContainsString('API_PREFIX', $content);
        $this->assertStringContainsString('BASE_PATH', $content);
        $this->assertStringContainsString('self.registration.scope', $content);
    }

    public function test_today_loading_state_preserves_async_card_mounts(): void
    {
        // Giao diện tách thành module: gộp mọi file JS để kiểm tra.
        $javascript = collect(\Illuminate\Support\Facades\File::allFiles(resource_path('js')))
            ->filter(fn ($file) => $file->getExtension() === 'js')
            ->map(fn ($file) => $file->getContents())
            ->implode("\n");

        $this->assertStringContainsString('id="today-screen"', $javascript);
        $this->assertStringContainsString('id="overview-card"', $javascript);
        // Màn Hôm nay chỉ dành cho người bệnh: thực đơn + bài tập của ngày, không còn lịch trong ngày / uống nước.
        $this->assertStringContainsString('id="menu-today"', $javascript);
        $this->assertStringContainsString('id="ex-today"', $javascript);
        $this->assertStringNotContainsString('id="schedule-card"', $javascript);
        $this->assertStringNotContainsString('id="water-card"', $javascript);
        $this->assertStringContainsString('id="readings-card"', $javascript);
        $this->assertStringContainsString('id="alerts-card"', $javascript);
        $this->assertStringNotContainsString("setLoading('today-content', true)", $javascript);
    }
}
