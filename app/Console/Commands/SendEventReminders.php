<?php

namespace App\Console\Commands;

use App\Contracts\PushNotifier;
use App\Models\Event;
use App\Models\PushSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Gửi nhắc nhở mốc sự kiện:
 * - 3 ngày trước event_date
 * - 1 ngày trước event_date
 */
class SendEventReminders extends Command
{
    protected $signature = 'reminders:events';
    protected $description = 'Gửi nhắc nhở mốc sự kiện (3 ngày + 1 ngày trước)';

    public function handle(PushNotifier $notifier): int
    {
        $today = CarbonImmutable::today();
        $windows = [
            ['days' => 3, 'label' => '3 ngày nữa'],
            ['days' => 1, 'label' => 'ngày mai'],
        ];

        $sent = 0;
        foreach ($windows as $window) {
            $targetDate = $today->addDays($window['days'])->toDateString();

            $events = Event::withoutGlobalScope('tenant')
                ->whereDate('event_date', $targetDate)
                ->where('status', '!=', 'cancelled')
                ->get();

            foreach ($events as $event) {
                $alreadySent = DB::table('notifications')
                    ->where('notifiable_type', Event::class)
                    ->where('notifiable_id', $event->id)
                    ->where('type', 'event_reminder_' . $window['days'])
                    ->exists();

                if ($alreadySent) {
                    continue;
                }

                $endpoints = PushSubscription::withoutGlobalScope('tenant')
                    ->where('user_id', function ($q) use ($event) {
                        $q->select('user_id')
                            ->from('patient_access')
                            ->where('patient_id', $event->patient_id)
                            ->whereIn('role', ['caregiver', 'patient']);
                    })
                    ->where('enabled', true)
                    ->pluck('endpoint')
                    ->toArray();

                $title = sprintf('Nhắc: %s (%s)', $event->title, $window['label']);
                $body = $event->description
                    ? '[NHÁP — CẦN DUYỆT] ' . $event->description
                    : '[NHÁP — CẦN DUYỆT] Bạn có lịch vào ' . $event->event_date;

                if (! empty($endpoints)) {
                    $result = $notifier->send($endpoints, $title, $body, [
                        'type' => 'event_reminder',
                        'event_id' => $event->id,
                        'patient_id' => $event->patient_id,
                        'days_before' => $window['days'],
                    ]);
                    $sent += $result['sent'];
                }

                DB::table('notifications')->insert([
                    'id' => (string) \Illuminate\Support\Str::ulid(),
                    'type' => 'event_reminder_' . $window['days'],
                    'notifiable_type' => Event::class,
                    'notifiable_id' => $event->id,
                    'data' => json_encode(['title' => $title, 'body' => $body]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $this->info("Đã gửi {$sent} nhắc mốc sự kiện.");
        return self::SUCCESS;
    }
}
