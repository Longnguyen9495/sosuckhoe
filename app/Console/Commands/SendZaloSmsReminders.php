<?php

namespace App\Console\Commands;

use App\Contracts\SmsSender;
use App\Contracts\ZaloZnsSender;
use App\Models\Alert;
use App\Models\Event;
use App\Models\PatientAccess;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendZaloSmsReminders extends Command
{
    protected $signature = 'reminders:zalo-sms {--type=all : alert_red|appointment|all}';
    protected $description = 'Gửi Zalo ZNS hoặc SMS dự phòng cho cảnh báo đỏ và nhắc tái khám';

    public function handle(ZaloZnsSender $zalo, SmsSender $sms): int
    {
        $type = $this->option('type');
        $sentTotal = 0;

        if (in_array($type, ['alert_red', 'all'], true)) {
            $sentTotal += $this->sendRedAlerts($zalo, $sms);
        }

        if (in_array($type, ['appointment', 'all'], true)) {
            $sentTotal += $this->sendAppointmentReminders($zalo, $sms);
        }

        $this->info("Đã gửi {$sentTotal} tin Zalo/SMS.");
        return self::SUCCESS;
    }

    private function sendRedAlerts(ZaloZnsSender $zalo, SmsSender $sms): int
    {
        $alerts = Alert::withoutGlobalScope('tenant')
            ->where('level', 'red')
            ->whereNull('sent_to')
            ->get();

        $sent = 0;
        foreach ($alerts as $alert) {
            $phone = $this->recipientPhone($alert->patient_id);
            if (! $phone) {
                continue;
            }

            $message = "CẢNH BÁO SỨC KHỎE: {$alert->content}. Vui lòng kiểm tra ngay.";

            // Ưu tiên Zalo ZNS
            $result = $zalo->send($phone, 'alert_red', [
                'patient_name' => $alert->patient->full_name ?? 'Bệnh nhân',
                'alert_content' => $alert->content,
            ]);

            // Nếu Zalo thất bại, fallback SMS
            if (! $result['sent']) {
                $result = $sms->send($phone, $message);
            }

            if ($result['sent']) {
                $sent++;
                $alert->update([
                    'sent_to' => ['zalo_or_sms' => $result['message_id']],
                ]);
            }
        }

        $this->info("Đã gửi {$sent} cảnh báo đỏ.");
        return $sent;
    }

    private function sendAppointmentReminders(ZaloZnsSender $zalo, SmsSender $sms): int
    {
        $tomorrow = CarbonImmutable::tomorrow()->toDateString();

        $events = Event::withoutGlobalScope('tenant')
            ->whereDate('event_date', $tomorrow)
            ->where('type', 'appointment')
            ->where('status', '!=', 'cancelled')
            ->get();

        $sent = 0;
        foreach ($events as $event) {
            $phone = $this->recipientPhone($event->patient_id);
            if (! $phone) {
                continue;
            }

            $message = "NHẮC TÁI KHÁM: Bạn có lịch {$event->title} vào ngày {$event->event_date}.";

            $result = $zalo->send($phone, 'appointment_reminder', [
                'patient_name' => $event->patient->full_name ?? 'Bệnh nhân',
                'appointment_title' => $event->title,
                'appointment_date' => $event->event_date,
            ]);

            if (! $result['sent']) {
                $result = $sms->send($phone, $message);
            }

            if ($result['sent']) {
                $sent++;
            }
        }

        $this->info("Đã gửi {$sent} nhắc tái khám.");
        return $sent;
    }

    private function recipientPhone(string $patientId): ?string
    {
        $userId = PatientAccess::withoutGlobalScope('tenant')
            ->where('patient_id', $patientId)
            ->whereIn('role', ['caregiver', 'patient'])
            ->value('user_id');

        if (! $userId) {
            return null;
        }

        return DB::table('users')->where('id', $userId)->value('phone');
    }
}
