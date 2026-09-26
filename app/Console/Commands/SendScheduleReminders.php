<?php

namespace App\Console\Commands;

use App\Contracts\PushNotifier;
use App\Models\PushSubscription;
use App\Models\ScheduleItem;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Gửi nhắc nhở trước giờ uống thuốc/tiêm insulin:
 * - 30 phút trước giờ tiêm insulin
 * - 5 phút trước giờ uống thuốc
 */
class SendScheduleReminders extends Command
{
    protected $signature = 'reminders:schedule {--window-minutes=1}';
    protected $description = 'Gửi nhắc nhở trước giờ lịch uống thuốc / tiêm insulin';

    public function handle(PushNotifier $notifier): int
    {
        $now = CarbonImmutable::now();
        $window = (int) $this->option('window-minutes');
        $today = $now->toDateString();
        $currentTime = $now->format('H:i');

        $items = ScheduleItem::withoutGlobalScope('tenant')
            ->whereDate('starts_at', '<=', $today)
            ->where(function ($q) use ($today) {
                $q->whereNull('ends_at')->orWhereDate('ends_at', '>=', $today);
            })
            ->whereIn('type', ['medication', 'insulin'])
            ->get();

        $sent = 0;
        foreach ($items as $item) {
            $scheduled = CarbonImmutable::createFromFormat('H:i', $item->scheduled_time);
            $minutesBefore = $item->type === 'insulin' ? 30 : 5;
            $reminderAt = $scheduled->subMinutes($minutesBefore);
            $diffMinutes = abs($now->diffInMinutes($reminderAt));

            if ($diffMinutes > $window) {
                continue;
            }

            // Tránh gửi trùng trong cùng 1 giờ
            $alreadySent = DB::table('notifications')
                ->where('notifiable_type', ScheduleItem::class)
                ->where('notifiable_id', $item->id)
                ->whereDate('created_at', $today)
                ->exists();

            if ($alreadySent) {
                continue;
            }

            $endpoints = PushSubscription::withoutGlobalScope('tenant')
                ->where('user_id', function ($q) use ($item) {
                    $q->select('user_id')
                        ->from('patient_access')
                        ->where('patient_id', $item->patient_id)
                        ->whereIn('role', ['caregiver', 'patient']);
                })
                ->where('enabled', true)
                ->pluck('endpoint')
                ->toArray();

            if (empty($endpoints)) {
                continue;
            }

            $title = $item->type === 'insulin'
                ? 'Nhắc tiêm insulin'
                : 'Nhắc uống thuốc';
            $body = sprintf(
                '%s — %s',
                $item->title,
                $item->dose_text ?? ''
            );

            $result = $notifier->send($endpoints, $title, $body, [
                'type' => 'schedule_reminder',
                'schedule_item_id' => $item->id,
                'patient_id' => $item->patient_id,
            ]);

            $sent += $result['sent'];

            DB::table('notifications')->insert([
                'id' => (string) \Illuminate\Support\Str::ulid(),
                'type' => 'schedule_reminder',
                'notifiable_type' => ScheduleItem::class,
                'notifiable_id' => $item->id,
                'data' => json_encode(['title' => $title, 'body' => $body]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->info("Đã gửi {$sent} nhắc nhở lịch.");
        return self::SUCCESS;
    }
}
