<?php

namespace App\Console\Commands;

use App\Models\CarePlan;
use App\Models\Patient;
use App\Models\Tenant;
use App\Services\CarePlan\DailyPlanService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Lên thực đơn + bài tập cho một ngày (mặc định hôm nay) cho mọi người bệnh đã có kế hoạch chăm sóc.
 * Chạy tự động mỗi sáng (routes/console.php). AI lỗi thì dùng thực đơn 7 ngày, bài tập vẫn luân phiên.
 */
final class GenerateDailyPlans extends Command
{
    protected $signature = 'careplan:daily
        {--date= : Ngày cần lên (YYYY-MM-DD), mặc định hôm nay}
        {--patient= : Chỉ một người bệnh (id)}
        {--no-ai : Không gọi AI — chỉ luân phiên bài tập + thực đơn 7 ngày}';

    protected $description = 'Lên thực đơn và bài tập mới cho từng ngày, để mỗi ngày một khác';

    public function handle(TenantContext $context, DailyPlanService $daily): int
    {
        $day = $this->option('date') ? CarbonImmutable::parse($this->option('date')) : CarbonImmutable::today();
        $patientIds = CarePlan::withoutGlobalScopes()
            ->when($this->option('patient'), fn ($q, $id) => $q->where('patient_id', $id))
            ->distinct()->pluck('patient_id');

        $ok = 0;
        foreach ($patientIds as $id) {
            $patient = Patient::withoutGlobalScopes()->find($id);
            if ($patient === null) {
                continue;
            }
            try {
                $context->set(Tenant::findOrFail($patient->tenant_id));
                $row = $daily->generate($patient, $day, ! $this->option('no-ai'));
                $ok++;
                $this->line("  {$patient->id}: thực đơn ".($row?->source === 'ai' ? 'AI mới' : 'theo tuần').', '.count($row?->exercise_ids ?? []).' bài tập');
            } catch (Throwable $e) {
                $this->warn("  {$patient->id}: lỗi ".class_basename($e));
            }
        }
        $this->info("Đã lên kế hoạch ngày {$day->format('d/m/Y')} cho {$ok}/{$patientIds->count()} người bệnh.");

        return self::SUCCESS;
    }
}
