<?php

namespace App\Models;

class TemplateMonitoring extends UlidModel
{
    // Bảng tạo trong migration là "template_monitoring" (số ít); Laravel mặc định đoán "template_monitorings".
    protected $table = 'template_monitoring';

    protected function casts(): array
    {
        return [
            'schedule' => 'array',
        ];
    }
}
