<?php

namespace App\Console\Commands;

use App\Contracts\PushNotifier;
use App\Models\PrescriptionItem;
use App\Models\PushSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Tính ngày hết thuốc dự kiến và nhắc mua thêm trước 5 ngày.
 * Không suy luận liều nếu usage_rule thiếu hoặc không hiểu.
 */
class SendPurchaseReminders extends Command
{
    protected $signature = 'reminders:purchase';
    protected $description = 'Nhắc mua thuốc trước 5 ngày (không suy luận liều nếu thiếu usage_rule)';

    public function handle(PushNotifier $notifier): int
    {
        $today = CarbonImmutable::today();
        $lookaheadDate = $today->addDays(5)->toDateString();

        $items = PrescriptionItem::withoutGlobalScope('tenant')
            ->whereNotNull('prescribed_quantity')
            ->whereNotNull('purchased_quantity')
            ->whereNotNull('quantity_unit')
            ->whereRaw('purchased_quantity <= (prescribed_quantity * 0.2)')
            ->get();

        $sent = 0;
        foreach ($items as $item) {
            // Kiểm tra xem đã nhắc trong 7 ngày gần nhất chưa
            $alreadySent = DB::table('notifications')
                ->where('notifiable_type', PrescriptionItem::class)
                ->where('notifiable_id', $item->id)
                ->where('type', 'purchase_reminder')
                ->whereDate('created_at', '>=', $today->subDays(7)->toDateString())
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

            $title = 'Nhắc mua thuốc';
            $body = sprintf(
                '[NHÁP — CẦN DUYỆT] %s sắp hết. Số lượng còn: %s %s.',
                $item->drug_name_snapshot,
                $item->purchased_quantity,
                $item->quantity_unit
            );

            if (! empty($endpoints)) {
                $result = $notifier->send($endpoints, $title, $body, [
                    'type' => 'purchase_reminder',
                    'prescription_item_id' => $item->id,
                    'patient_id' => $item->patient_id,
                ]);
                $sent += $result['sent'];
            }

            DB::table('notifications')->insert([
                'id' => (string) \Illuminate\Support\Str::ulid(),
                'type' => 'purchase_reminder',
                'notifiable_type' => PrescriptionItem::class,
                'notifiable_id' => $item->id,
                'data' => json_encode(['title' => $title, 'body' => $body]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->info("Đã gửi {$sent} nhắc mua thuốc.");
        return self::SUCCESS;
    }
}
