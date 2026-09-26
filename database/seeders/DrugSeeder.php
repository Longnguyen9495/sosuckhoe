<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DrugSeeder extends Seeder
{
    private const DRAFT_WARNING = '[NHÁP — CẦN DUYỆT] ';

    public function run(): void
    {
        $drugs = [
            ['NovoMix 30 FlexPen', 'insulin aspart hai pha', null, 'bút tiêm', 'UI', 'Bảo quản và sử dụng đúng hướng dẫn của bác sĩ.'],
            ['Kim NovoFine 31G', null, '31G · 6 mm', 'kim tiêm', 'cái', null],
            ['Janumet', 'sitagliptin/metformin', '50/850 mg', 'viên', 'viên', null],
            ['Esserose', 'phospholipid', '450 mg', 'viên', 'viên', null],
            ['Hepazid', 'tenofovir alafenamide', '25 mg', 'viên', 'viên', 'Không tự ngừng thuốc; làm theo chỉ định bác sĩ.'],
            ['Livosil', 'silymarin', '140 mg', 'viên', 'viên', null],
            ['Etiheso', 'esomeprazole', '40 mg', 'viên', 'viên', null],
            ['Celebrex', 'celecoxib', '200 mg', 'viên', 'viên', 'Trao đổi với bác sĩ nếu có triệu chứng bất thường.'],
            ['Abricotis', 'calci carbonat/vitamin D3', '1500 mg/500 IU', 'viên', 'viên', null],
            ['Oztis', 'glucosamine/chondroitin', '750 mg/250 mg', 'viên', 'viên', null],
            ['Myopain', 'tolperisone', '50 mg', 'viên', 'viên', 'Thuốc có thể gây chóng mặt; trao đổi với bác sĩ hoặc dược sĩ khi có triệu chứng bất thường.'],
            ['Gel Nociceptol', 'chiết xuất đuôi ngựa/vuốt quỷ/đất sét xanh', '120 ml', 'gel bôi', 'tuýp', null],

            // Danh mục tham khảo thường gặp; không phải đơn thuốc hay gợi ý điều trị.
            ['Metformin', 'metformin', '500 mg', 'viên', 'viên', 'Chỉ sử dụng theo đơn và hướng dẫn của bác sĩ.'],
            ['Gliclazide MR', 'gliclazide', '30 mg', 'viên phóng thích kéo dài', 'viên', 'Có nguy cơ hạ đường huyết; chỉ sử dụng theo đơn và hướng dẫn của bác sĩ.'],
            ['Empagliflozin', 'empagliflozin', '10 mg', 'viên', 'viên', 'Chỉ sử dụng theo đơn; trao đổi với bác sĩ khi có dấu hiệu mất nước hoặc nhiễm trùng.'],
            ['Sitagliptin', 'sitagliptin', '100 mg', 'viên', 'viên', 'Chỉ sử dụng theo đơn và hướng dẫn của bác sĩ.'],
            ['Amlodipine', 'amlodipine', '5 mg', 'viên', 'viên', 'Chỉ sử dụng theo đơn; theo dõi triệu chứng bất thường và trao đổi với bác sĩ.'],
            ['Losartan', 'losartan', '50 mg', 'viên', 'viên', 'Chỉ sử dụng theo đơn và hướng dẫn của bác sĩ.'],
            ['Telmisartan', 'telmisartan', '40 mg', 'viên', 'viên', 'Chỉ sử dụng theo đơn và hướng dẫn của bác sĩ.'],
            ['Perindopril', 'perindopril', '5 mg', 'viên', 'viên', 'Chỉ sử dụng theo đơn; trao đổi với bác sĩ khi có triệu chứng bất thường.'],
            ['Atorvastatin', 'atorvastatin', '20 mg', 'viên', 'viên', 'Chỉ sử dụng theo đơn; trao đổi với bác sĩ khi đau cơ hoặc có triệu chứng bất thường.'],
            ['Rosuvastatin', 'rosuvastatin', '10 mg', 'viên', 'viên', 'Chỉ sử dụng theo đơn; trao đổi với bác sĩ khi đau cơ hoặc có triệu chứng bất thường.'],
            ['Fenofibrate', 'fenofibrate', '160 mg', 'viên', 'viên', 'Chỉ sử dụng theo đơn và hướng dẫn của bác sĩ.'],
        ];

        DB::transaction(function () use ($drugs): void {
            foreach ($drugs as [$brandName, $ingredient, $strength, $form, $unit, $warning]) {
                $identity = ['brand_name' => $brandName, 'strength' => $strength];
                $values = [
                    'active_ingredient' => $ingredient,
                    'form' => $form,
                    'unit' => $unit,
                    'general_warning' => $warning === null ? null : self::DRAFT_WARNING.$warning,
                ];
                $id = DB::table('drugs')->where($identity)->value('id');

                if ($id !== null) {
                    DB::table('drugs')->where('id', $id)->update([...$values, 'updated_at' => now()]);
                    continue;
                }

                DB::table('drugs')->insert([
                    ...$identity,
                    ...$values,
                    'id' => (string) Str::ulid(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }
}
