<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Mỗi sáng lên thực đơn + bài tập mới cho ngày hôm đó (mỗi ngày một khác). Server cần cron:
//   * * * * * cd /var/www/sosuckhoe && php artisan schedule:run >> /dev/null 2>&1
Schedule::command('careplan:daily')->dailyAt('04:30')->timezone('Asia/Ho_Chi_Minh')->withoutOverlapping()->onOneServer();
