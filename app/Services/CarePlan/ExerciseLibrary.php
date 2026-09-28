<?php

namespace App\Services\CarePlan;

/**
 * Thư viện bài tập tại nhà có video YouTube chọn sẵn (đã kiểm tra mở được, cho phép nhúng).
 * AI chỉ được chọn bài theo `id` trong danh sách này — không tự đưa link video, tránh link bịa.
 * `for` là nhóm người bệnh phù hợp; khớp với nhãn do `tags()` suy ra từ chẩn đoán, thuốc, xét nghiệm.
 */
final class ExerciseLibrary
{
    public const ITEMS = [
        'walk_after_meal' => [
            'title' => 'Đi bộ nhẹ sau bữa ăn',
            'summary' => 'Đi bộ chậm 10–15 phút sau bữa chính giúp đường huyết sau ăn tăng ít hơn.',
            'duration' => '10–30 phút sau bữa chính',
            'intensity' => 'Nhẹ',
            'for' => ['diabetes', 'overweight', 'general'],
            'caution' => 'Đi giày vừa chân, kiểm tra bàn chân sau khi đi. Dừng lại nếu chóng mặt hoặc vã mồ hôi.',
            'youtube_id' => 'BA4NV_K6PeU',
            'source' => 'Bác sĩ Ngọc - Sức Khỏe Trường Thọ',
        ],
        'indoor_walk' => [
            'title' => 'Đi bộ tại chỗ trong nhà',
            'summary' => 'Đi bộ tại chỗ theo video, không nhảy — dùng khi trời mưa, nắng gắt hoặc ngại ra ngoài.',
            'duration' => '15–20 phút',
            'intensity' => 'Nhẹ – vừa',
            'for' => ['diabetes', 'hypertension', 'overweight', 'general'],
            'caution' => 'Đau gối nhiều thì bước nhỏ, chậm lại hoặc chuyển sang bài tập trên ghế.',
            'youtube_id' => 'lgdGOeP5Eug',
            'source' => 'Hải Ninh Yoga',
        ],
        'bp_light_move' => [
            'title' => 'Vận động tại chỗ cho người huyết áp cao',
            'summary' => 'Chuỗi động tác nhẹ nhàng, thở đều, hợp người tăng huyết áp.',
            'duration' => '15 phút',
            'intensity' => 'Nhẹ',
            'for' => ['hypertension', 'heart'],
            'caution' => 'Không nín thở khi gắng sức, không cúi đầu thấp đột ngột. Huyết áp đang rất cao hoặc đau đầu, đau ngực thì không tập, hỏi bác sĩ.',
            'youtube_id' => 'ieR1U3jRO2k',
            'source' => 'Bác sĩ Ngọc - Sức Khỏe Trường Thọ',
        ],
        'diaphragm_breathing' => [
            'title' => 'Thở cơ hoành (thở bụng)',
            'summary' => 'Hít chậm bằng mũi cho bụng phồng, thở ra chậm bằng miệng. Giúp thư giãn, dễ ngủ, hỗ trợ hạ huyết áp.',
            'duration' => '5–10 phút, 2 lần mỗi ngày',
            'intensity' => 'Rất nhẹ',
            'for' => ['hypertension', 'lung', 'heart', 'elderly', 'general'],
            'caution' => 'Thấy choáng váng thì thở chậm lại và nghỉ.',
            'youtube_id' => 'CdZB4Kxlup8',
            'source' => 'Chương trình Chống lao Quốc gia',
        ],
        'chair_exercise' => [
            'title' => 'Tập trên ghế cho người cao tuổi',
            'summary' => 'Cử động tay, chân, vai khi ngồi ghế — an toàn cho người yếu, khó đứng lâu.',
            'duration' => '10–15 phút',
            'intensity' => 'Nhẹ',
            'for' => ['elderly', 'joint', 'weak', 'general'],
            'caution' => 'Dùng ghế chắc chắn có tựa lưng, không có bánh xe.',
            'youtube_id' => '2OgDfWTyU88',
            'source' => 'Dr Lê Văn',
        ],
        'balance' => [
            'title' => 'Giữ thăng bằng, phòng té ngã',
            'summary' => 'Bài tập đứng vững, chuyển trọng tâm — giảm nguy cơ ngã ở người lớn tuổi, người tê bì bàn chân.',
            'duration' => '10–15 phút',
            'intensity' => 'Nhẹ',
            'for' => ['elderly', 'neuropathy', 'diabetes'],
            'caution' => 'Luôn đứng cạnh tường hoặc bàn ghế chắc để vịn; có người ở bên nếu hay chóng mặt.',
            'youtube_id' => 'MUo4QJM2r5M',
            'source' => 'Bác sĩ Ngọc - Sức Khỏe Trường Thọ',
        ],
        'muscle_strength' => [
            'title' => 'Tập chống mất cơ',
            'summary' => 'Động tác tăng sức cơ chân tay cho người trung niên và cao tuổi; cơ khỏe giúp dùng đường trong máu tốt hơn.',
            'duration' => '15–20 phút, 2–3 ngày mỗi tuần',
            'intensity' => 'Vừa',
            'for' => ['elderly', 'diabetes', 'weak', 'general'],
            'caution' => 'Tăng dần số lần. Không tập khi đang đau khớp cấp.',
            'youtube_id' => 'TDzi9erdT8Q',
            'source' => 'Video AloBacsi — ThS.BS.CK2 Hồ Phạm Thục Lan',
        ],
        'knee' => [
            'title' => 'Bài tập giảm đau đầu gối',
            'summary' => 'Động tác nhẹ cho khớp gối ở người cao tuổi, giữ khớp linh hoạt và cơ đùi khỏe.',
            'duration' => '10 phút',
            'intensity' => 'Nhẹ',
            'for' => ['joint'],
            'caution' => 'Gối đang sưng nóng, đỏ thì nghỉ và hỏi bác sĩ.',
            'youtube_id' => '5dLOQnPaxEA',
            'source' => 'ACC Chiropractic',
        ],
        'neck' => [
            'title' => 'Tập cổ vai gáy',
            'summary' => '5 bài tập tại nhà cho người thoái hóa cột sống cổ, mỏi cổ vai gáy.',
            'duration' => '5–10 phút',
            'intensity' => 'Nhẹ',
            'for' => ['neck'],
            'caution' => 'Xoay cổ chậm, không bẻ mạnh. Tê tay tăng lên thì dừng và đi khám.',
            'youtube_id' => 'M1Ij0t_lLZE',
            'source' => 'Bệnh viện Đa khoa Hồng Ngọc',
        ],
        'low_back' => [
            'title' => 'Tập cho cột sống thắt lưng',
            'summary' => '5 bài tập giảm đau cho người thoái hóa cột sống thắt lưng.',
            'duration' => '10 phút',
            'intensity' => 'Nhẹ',
            'for' => ['back'],
            'caution' => 'Đau lan xuống chân, tê yếu chân thì đi khám trước khi tập.',
            'youtube_id' => '9OrAFoMr9-Q',
            'source' => 'Bệnh viện ĐKQT Vinmec',
        ],
        'joint_warmup' => [
            'title' => 'Khởi động khớp trước khi tập',
            'summary' => 'Làm nóng các khớp trước khi đi bộ hay tập, giảm đau mỏi và chấn thương.',
            'duration' => '5 phút',
            'intensity' => 'Rất nhẹ',
            'for' => ['elderly', 'joint', 'general'],
            'caution' => 'Cử động trong tầm không đau.',
            'youtube_id' => 'cNExS1TVlMI',
            'source' => 'Bác sĩ Ngọc - Sức Khỏe Trường Thọ',
        ],
        'insulin_exercise_tips' => [
            'title' => 'Tập an toàn khi đang tiêm insulin',
            'summary' => 'Video tư vấn: chọn bài tập, thời điểm tập và cách phòng hạ đường huyết khi dùng insulin.',
            'duration' => 'Video tư vấn — xem một lần',
            'intensity' => 'Kiến thức',
            'for' => ['insulin'],
            'caution' => 'Đo đường huyết trước khi tập, mang theo kẹo hoặc đường; không tập lúc đói hay lúc insulin tác dụng mạnh nhất.',
            'youtube_id' => '9bK2QqUP7u8',
            'source' => 'Viện NC Y Dược học Tuệ Tĩnh',
        ],
    ];

    public const SAFETY = 'Dừng tập ngay nếu đau ngực, khó thở, chóng mặt, vã mồ hôi, run tay. Bắt đầu nhẹ rồi tăng dần; hỏi bác sĩ trước khi tập nếu có bệnh tim hoặc vừa ốm dậy.';

    /** Danh sách gửi cho AI chọn (không có link). */
    public static function forPrompt(): array
    {
        return array_map(fn (string $id, array $e) => [
            'id' => $id,
            'title' => $e['title'],
            'suitable_for' => $e['for'],
            'caution' => $e['caution'],
        ], array_keys(self::ITEMS), self::ITEMS);
    }

    public static function exists(string $id): bool
    {
        return isset(self::ITEMS[$id]);
    }

    /** Dữ liệu đầy đủ để hiển thị một bài tập, kèm lý do / tần suất riêng cho người bệnh. */
    public static function present(string $id, ?string $why = null, ?string $frequency = null): array
    {
        $e = self::ITEMS[$id];

        return [
            'id' => $id,
            'title' => $e['title'],
            'summary' => $e['summary'],
            'why' => $why,
            'frequency' => $frequency ?: $e['duration'],
            'intensity' => $e['intensity'],
            'caution' => $e['caution'],
            'youtube_id' => $e['youtube_id'],
            'source' => $e['source'],
        ];
    }

    /**
     * Nhãn tình trạng suy ra từ dữ liệu y khoa đã ẩn danh (CarePlanService::context()).
     *
     * @return list<string>
     */
    public static function tags(array $context): array
    {
        $diagnoses = mb_strtolower(implode(' | ', $context['diagnoses'] ?? []));
        $drugs = mb_strtolower(implode(' | ', array_map(fn ($m) => (string) ($m['drug'] ?? ''), $context['medications'] ?? [])));
        $types = array_map(fn ($m) => $m['type'] ?? '', $context['medications'] ?? []);
        $highLabs = mb_strtolower(implode(' | ', array_map(
            fn ($l) => (string) ($l['test'] ?? ''),
            array_filter($context['lab_results'] ?? [], fn ($l) => ($l['flag'] ?? null) === 'H'),
        )));
        $has = fn (string $text, string $pattern) => preg_match('/'.$pattern.'/iu', $text) === 1;

        $tags = [];
        $insulin = in_array('insulin', $types, true) || $has($drugs, 'insulin|lantus|toujeo|levemir|novomix|mixtard|humulin|ryzodeg|novorapid|apidra|basaglar|scilin|wosulin');
        if ($insulin) {
            $tags[] = 'insulin';
        }
        if ($insulin
            || $has($diagnoses, 'đái tháo đường|tiểu đường|đường huyết|\bE1[0-4]')
            || $has($drugs, 'metformin|glucophage|gliclazid|diamicron|glimepirid|amaryl|sitagliptin|januvia|vildagliptin|galvus|linagliptin|trajenta|empagliflozin|jardiance|dapagliflozin|forxiga|acarbose|pioglitazon')
            || $has($highLabs, 'hba1c|glucose|đường')) {
            $tags[] = 'diabetes';
        }
        if ($has($diagnoses, 'huyết áp|\bI1[0-5]')
            || $has($drugs, 'amlodipin|losartan|telmisartan|valsartan|irbesartan|candesartan|perindopril|enalapril|lisinopril|captopril|nifedipin|bisoprolol|metoprolol|indapamid|hydrochlorothiazid|coversyl')) {
            $tags[] = 'hypertension';
        }
        if ($has($diagnoses, 'tim|mạch vành|suy tim|\bI2[0-5]|\bI50')) {
            $tags[] = 'heart';
        }
        if ($has($diagnoses, 'khớp|gout|gút|thấp khớp|\bM1[5-9]|\bM0')) {
            $tags[] = 'joint';
        }
        if ($has($diagnoses, 'cột sống cổ|cổ vai|\bM47|\bM50')) {
            $tags[] = 'neck';
        }
        if ($has($diagnoses, 'thắt lưng|đau lưng|\bM51|\bM54')) {
            $tags[] = 'back';
        }
        if ($has($diagnoses, 'phổi|copd|hen|lao|hô hấp|\bJ4[0-7]')) {
            $tags[] = 'lung';
        }
        if ($has($diagnoses, 'thần kinh ngoại (vi|biên)|tê bì|bàn chân')) {
            $tags[] = 'neuropathy';
        }
        if ($has($diagnoses, 'béo phì|thừa cân|rối loạn (chuyển hóa )?lipid|mỡ máu')) {
            $tags[] = 'overweight';
        }
        if (($context['age'] ?? 0) >= 60) {
            $tags[] = 'elderly';
        }

        return $tags;
    }

    /**
     * Gợi ý theo quy tắc khi AI không chọn được bài (AI giả, kế hoạch cũ trước khi có mục bài tập).
     *
     * @return list<array>
     */
    public static function suggest(array $context, int $limit = 5): array
    {
        $tags = self::tags($context);
        $scores = [];
        foreach (self::ITEMS as $id => $e) {
            $score = count(array_intersect($e['for'], $tags)) * 2 + (in_array('general', $e['for'], true) ? 1 : 0);
            if ($score > 0 && ! ($id === 'insulin_exercise_tips' && ! in_array('insulin', $tags, true))) {
                $scores[$id] = $score;
            }
        }
        // Sắp theo điểm, giữ thứ tự thư viện khi bằng điểm.
        uksort($scores, fn ($a, $b) => [$scores[$b], array_search($a, array_keys(self::ITEMS))] <=> [$scores[$a], array_search($b, array_keys(self::ITEMS))]);
        $ids = array_slice(array_keys($scores), 0, $limit);
        // Người tiêm insulin luôn có bài hướng dẫn tập an toàn (phòng hạ đường huyết).
        if (in_array('insulin', $tags, true) && ! in_array('insulin_exercise_tips', $ids, true)) {
            $ids = [...array_slice($ids, 0, $limit - 1), 'insulin_exercise_tips'];
        }

        return array_map(fn ($id) => self::present($id), $ids);
    }
}
