<?php

namespace Tests\Unit;

use App\Services\Privacy\ImageMetadataStripper;
use App\Services\Privacy\SensitiveDataScrubber;
use App\Services\Schedule\MedicationScheduleMapper;
use App\Support\DefaultPassword;
use PHPUnit\Framework\TestCase;

class PrivacyTest extends TestCase
{
    public function test_default_password_strips_accents_spaces_and_uses_last_four_digits(): void
    {
        $this->assertSame('nguyenvanan5678', DefaultPassword::for('Nguyễn Văn An', '0912345678'));
        $this->assertSame('tranthidung0001', DefaultPassword::for('  Trần Thị  Dung ', '+84 900 000 001'));
        $this->assertSame('dangduc9999', DefaultPassword::for('Đặng Đức', '0900009999'));
    }

    public function test_scrubber_removes_citizen_id_and_health_insurance_numbers(): void
    {
        $scrubber = new SensitiveDataScrubber();
        [$clean, $count] = $scrubber->scrub([
            'cccd' => '001234567890',
            'health_insurance_number' => 'DN4797931234567',
            'findings' => [
                'Số thẻ BHYT: DN4797931234567',
                'Mã thẻ: HC 4 79 79 312 34567, nơi ĐKKCB 01-001',
                'CCCD 001 234 567 890 cấp ngày 01/01/2021',
                'CMND số 012345678',
                'Mã BHXH: 0123456789',
                'HbA1c 10,16 % — Glucose 8,8 mmol/L',
                'SĐT bác sĩ 0912345678',
            ],
        ]);

        $flat = json_encode($clean, JSON_UNESCAPED_UNICODE);
        foreach (['001234567890', 'DN4797931234567', 'HC 4 79 79 312 34567', '001 234 567 890', '012345678', '0123456789'] as $secret) {
            $this->assertStringNotContainsString($secret, $flat);
        }
        $this->assertArrayNotHasKey('cccd', $clean);
        $this->assertArrayNotHasKey('health_insurance_number', $clean);
        $this->assertStringContainsString('HbA1c 10,16 %', $flat, 'Chỉ số y khoa giữ nguyên.');
        $this->assertStringContainsString('0912345678', $flat, 'Số điện thoại 10 số không bị nhầm là CCCD.');
        $this->assertGreaterThanOrEqual(7, $count);
    }

    public function test_jpeg_exif_is_removed(): void
    {
        $exif = "\xFF\xE1".pack('n', 16).'Exif'."\0\0".'GPS-DATA';
        $jfif = "\xFF\xE0".pack('n', 16).'JFIF'."\0".str_repeat("\1", 9);
        $jpeg = "\xFF\xD8".$jfif.$exif."\xFF\xDA".pack('n', 4)."\0\0".'IMAGEDATA'."\xFF\xD9";

        $out = (new ImageMetadataStripper())->strip($jpeg, 'image/jpeg');

        $this->assertStringNotContainsString('GPS-DATA', $out);
        $this->assertStringContainsString('JFIF', $out);
        $this->assertStringEndsWith('IMAGEDATA'."\xFF\xD9", $out);
    }

    public function test_schedule_mapper_builds_usage_rule_from_ai_slots(): void
    {
        $rule = MedicationScheduleMapper::toUsageRule([
            'type' => 'medication',
            'schedule' => [
                ['slot' => 'breakfast', 'relation' => 'before', 'amount_text' => '1 viên'],
                ['slot' => 'dinner', 'relation' => 'after', 'amount_text' => '½ viên'],
                ['slot' => 'sleep', 'relation' => 'none', 'amount_text' => ''],
                ['slot' => 'bogus'],
            ],
            'fixed_times' => ['12:30', '25:00'],
        ]);

        $this->assertSame('medication', $rule['type']);
        $this->assertSame([
            ['anchor' => 'breakfast', 'offset_min' => -30, 'amount_text' => '1 viên'],
            ['anchor' => 'dinner', 'offset_min' => 30, 'amount_text' => '½ viên'],
            ['anchor' => 'sleep', 'offset_min' => -30, 'amount_text' => '1 lần'],
            ['fixed_time' => '12:30', 'amount_text' => '1 viên'],
        ], $rule['doses']);

        $this->assertNull(MedicationScheduleMapper::toUsageRule(['type' => 'medication', 'schedule' => []]));
        $this->assertSame([], MedicationScheduleMapper::toUsageRule(['type' => 'supply'])['doses']);
    }
}
