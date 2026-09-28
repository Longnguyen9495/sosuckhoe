<?php

namespace App\Services\Dedup;

/**
 * So khớp dữ liệu trùng: cùng một phiếu chụp nhiều lần, cùng một thuốc / chỉ số / chẩn đoán
 * được AI đọc ra nhiều lần với cách viết hơi khác nhau (dấu, hoa thường, "typ" / "típ", 10,16 / 10.16).
 */
final class DuplicateMatcher
{
    /** Từ chung chung trong tên thuốc — không dùng để phân biệt thuốc. */
    private const DRUG_STOPWORDS = ['thuoc', 'vien', 'nang', 'nen', 'goi', 'ong', 'lo', 'tuyp', 'chai', 'hop', 'vi', 'mg', 'ml', 'g', 'mcg', 'iu', 'ui', 'dv', 'don', 'vi', 'va', 'x'];

    /** Chữ thường, bỏ dấu tiếng Việt, chỉ giữ chữ và số, cách nhau một khoảng trắng. */
    public static function text(?string $s): string
    {
        $s = mb_strtolower(trim((string) $s));
        // Dương tính / âm tính mang nghĩa ngược nhau — giữ lại trước khi bỏ dấu câu.
        $s = preg_replace(['/\(\s*\+\s*\)/u', '/\(\s*-\s*\)/u'], [' duongtinh ', ' amtinh '], $s);
        $s = strtr((string) $s, ['đ' => 'd', 'dương tính' => 'duongtinh', 'âm tính' => 'amtinh']);
        if (class_exists(\Normalizer::class)) {
            $s = preg_replace('/\p{Mn}+/u', '', (string) \Normalizer::normalize($s, \Normalizer::FORM_D));
        } else {
            $s = (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        }
        $s = preg_replace('/[^a-z0-9.]+/', ' ', (string) $s);
        // Dấu chấm chỉ giữ khi nằm giữa hai chữ số (10.16, E11.9).
        $s = preg_replace('/(?<!\d)\.|\.(?!\d)/', ' ', (string) $s);
        $s = ' '.preg_replace('/\s+/', ' ', trim((string) $s)).' ';
        $s = strtr($s, [' typ ' => ' tip ', ' type ' => ' tip ']);

        return trim($s);
    }

    /** Giá trị xét nghiệm: bỏ khoảng trắng, dấu phẩy thập phân = dấu chấm. */
    public static function labValue(?string $v): string
    {
        return str_replace(',', '.', preg_replace('/\s+/', '', mb_strtolower(trim((string) $v))));
    }

    /** Tên chỉ số không kèm nhóm ("Hóa sinh — HbA1c" → "hba1c"): AI hay đặt tên nhóm khác nhau giữa hai lần đọc. */
    public static function labName(?string $metric): string
    {
        $parts = preg_split('/\s+—\s+/u', (string) $metric);

        return self::text(end($parts) ?: (string) $metric);
    }

    public static function labKey(?string $metric, ?string $measuredAt, ?string $value): string
    {
        return self::labName($metric).'|'.substr((string) $measuredAt, 0, 10).'|'.self::labValue($value);
    }

    /** Mã ICD ở cuối tên chẩn đoán, VD "… (E11.9)" → "E11.9". */
    public static function icd(?string $title): ?string
    {
        return preg_match('/\(([A-Z]\d{2}(?:\.\d{1,3})?)\)\s*$/u', trim((string) $title), $m) ? $m[1] : null;
    }

    /** Tên chẩn đoán đã chuẩn hoá, bỏ mã ICD ở cuối (không cắt các ngoặc khác như "(+)"). */
    public static function diagnosisName(?string $title): string
    {
        return self::text(preg_replace('/\s*\([A-Z]\d{2}(?:\.\d{1,3})?\)\s*$/u', '', trim((string) $title)));
    }

    public static function sameDiagnosis(string $a, string $b, ?string $icdA = null, ?string $icdB = null): bool
    {
        $icdA ??= self::icd($a);
        $icdB ??= self::icd($b);
        $nameA = self::diagnosisName($a);
        $nameB = self::diagnosisName($b);

        return $nameA !== '' && $nameA === $nameB
            // Cùng mã ICD và tên gần giống (một tên nằm trong tên kia): "Bệnh ĐTĐ típ 2 (E11.9)" / "ĐTĐ típ 2, không kèm biến chứng (E11.9)".
            || ($icdA !== null && $icdA === $icdB && self::wordsSubset($nameA, $nameB));
    }

    /**
     * Hai tên thuốc là một thuốc: trùng hẳn sau chuẩn hoá, hoặc cùng hàm lượng và mọi từ riêng của tên ngắn
     * đều có trong tên dài ("Celebrex 200mg" ~ "Celecoxib (Celebrex) 200mg"). Khác hàm lượng là thuốc khác.
     */
    public static function sameDrug(?string $a, ?string $b, bool $loose = false): bool
    {
        $ka = self::text($a);
        $kb = self::text($b);
        if ($ka === '' || $kb === '') {
            return false;
        }
        if ($ka === $kb) {
            return true;
        }
        [$wa, $na] = self::drugTokens($ka);
        [$wb, $nb] = self::drugTokens($kb);
        if ($na !== $nb || $wa === [] || $wb === []) {
            return false;
        }
        [$short, $long] = count($wa) <= count($wb) ? [$wa, $wb] : [$wb, $wa];

        // Mỗi từ của tên ngắn phải có trong tên dài; cho lệch ký tự với từ dài (AI đọc nhầm chữ: ETHESO / ETIHESO).
        // Từ 6 chữ lệch 1 ký tự, từ 7 chữ trở lên lệch 2 ký tự (LIVISOL / LIVOSIL); $loose (chỉ dùng khi đã chắc cùng một phiếu)
        // nới thêm cho từ 5 chữ. Quy tắc chênh độ dài bên dưới giữ Prednison / Prednisolon, Losartan / Valsartan là thuốc khác.
        // Độ dài chênh quá 1 ký tự là tên khác (Prednison / Prednisolon), không phải đọc nhầm chữ.
        foreach ($short as $w) {
            $found = false;
            foreach ($long as $l) {
                $len = min(mb_strlen($w), mb_strlen($l));
                $max = abs(mb_strlen($w) - mb_strlen($l)) > 1 ? 0
                    : ($len >= 7 ? 2 : ($len >= ($loose ? 5 : 6) ? 1 : 0));
                if ($w === $l || ($max > 0 && levenshtein($w, $l) <= $max)) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                return false;
            }
        }

        return true;
    }

    /**
     * Mức giống nhau của hai danh sách: số phần tử khớp / số phần tử của danh sách ngắn hơn.
     * Hai lần đọc cùng một phiếu thường lệch nhau một vài dòng (AI đọc sót / đọc thêm), nên không đòi hỏi trùng hoàn toàn.
     * $same: hàm so hai phần tử (mặc định so bằng).
     */
    public static function overlap(array $a, array $b, ?callable $same = null): float
    {
        $a = array_values(array_unique(array_filter($a)));
        $b = array_values(array_unique(array_filter($b)));
        if ($a === [] || $b === []) {
            return 0.0;
        }
        [$short, $long] = count($a) <= count($b) ? [$a, $b] : [$b, $a];
        $same ??= fn ($x, $y) => $x === $y;
        $hits = 0;
        foreach ($short as $x) {
            foreach ($long as $y) {
                if ($same($x, $y)) {
                    $hits++;
                    break;
                }
            }
        }

        return $hits / count($short);
    }

    /**
     * Phiếu chụp lại: cùng loại, cùng ngày, cùng tiêu đề / khoa (cho lệch vài ký tự do AI đọc) và nội dung gần như
     * giống hệt (thuốc / xét nghiệm / chẩn đoán). Phiếu không có dòng nào để so thì so tiêu đề + nơi khám + ý chính.
     *
     * @param  array{type: ?string, date: ?string, title?: ?string, department?: ?string, medications?: array, lab_results?: array, diagnoses?: array, findings?: array}  $a
     * @param  array{type: ?string, date: ?string, title?: ?string, department?: ?string, medications?: array, lab_results?: array, diagnoses?: array, findings?: array}  $b
     */
    public static function sameDocument(array $a, array $b): bool
    {
        if (($a['type'] ?? null) !== ($b['type'] ?? null) || substr((string) ($a['date'] ?? ''), 0, 10) !== substr((string) ($b['date'] ?? ''), 0, 10) || empty($a['date'])) {
            return false;
        }
        // Tiêu đề / khoa khác nhau là hai phiếu khác nhau (VD siêu âm ổ bụng và siêu âm khớp vai cùng ngày,
        // dù đầu phiếu in cùng chẩn đoán chung của người bệnh). Khoa cho lệch vài ký tự: "KCBTƯC" / "KCBTVC".
        foreach (['title' => 0, 'department' => 2] as $field => $tolerance) {
            $x = self::text($a[$field] ?? '');
            $y = self::text($b[$field] ?? '');
            if ($x !== '' && $y !== '' && $x !== $y && levenshtein($x, $y) > $tolerance) {
                return false;
            }
        }

        $meds = fn (array $d) => array_map(fn ($m) => (string) ($m['drug_name'] ?? ''), $d['medications'] ?? []);
        $labs = fn (array $d) => array_map(fn ($r) => self::labName($r['name'] ?? '').'='.self::labValue($r['value'] ?? ''), $d['lab_results'] ?? []);
        // Chẩn đoán so theo tập từ: AI khi gộp thành một dòng ("A / B, C"), khi tách từng dòng.
        $diag = fn (array $d) => array_values(array_unique(array_filter(explode(' ', self::text(implode(' ', array_map(fn ($x) => self::diagnosisName($x['name'] ?? ''), $d['diagnoses'] ?? [])))))));

        $compared = false;
        foreach ([
            [$meds($a), $meds($b), fn ($x, $y) => self::sameDrug($x, $y, loose: true)],
            [$labs($a), $labs($b), null],
            [$diag($a), $diag($b), null],
        ] as [$x, $y, $same]) {
            $x = array_values(array_filter(array_unique($x)));
            $y = array_values(array_filter(array_unique($y)));
            if ($x === [] && $y === []) {
                continue;
            }
            $compared = true;
            // Một dòng thì phải trùng hẳn; từ hai dòng trở lên cho lệch tối đa 20%.
            $min = min(count($x), count($y));
            if ($min === 0 || self::overlap($x, $y, $same) < ($min === 1 ? 1.0 : 0.8)) {
                return false;
            }
        }
        if ($compared) {
            return true;
        }
        $plain = fn (array $d) => self::text(($d['title'] ?? '').' '.($d['department'] ?? '').' '.implode(' ', $d['findings'] ?? []));

        return $plain($a) !== '' && $plain($a) === $plain($b);
    }

    /** @return array{0: list<string>, 1: list<string>} từ riêng (đã bỏ từ chung) và các con số (hàm lượng) */
    private static function drugTokens(string $key): array
    {
        $tokens = explode(' ', $key);
        $numbers = array_values(array_unique(array_map(fn ($t) => preg_replace('/\D+.*$/', '', $t), array_filter($tokens, fn ($t) => preg_match('/^\d/', $t)))));
        sort($numbers);
        $words = array_values(array_unique(array_filter($tokens, fn ($t) => ! preg_match('/^\d/', $t) && mb_strlen($t) >= 3 && ! in_array($t, self::DRUG_STOPWORDS, true))));

        return [$words, $numbers];
    }

    private static function wordsSubset(string $a, string $b): bool
    {
        $wa = array_filter(explode(' ', $a));
        $wb = array_filter(explode(' ', $b));
        if ($wa === [] || $wb === []) {
            return false;
        }
        [$short, $long] = count($wa) <= count($wb) ? [$wa, $wb] : [$wb, $wa];

        return array_diff($short, $long) === [];
    }
}
