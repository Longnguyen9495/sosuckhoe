<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Điều khoản đồng ý xử lý dữ liệu và bài hướng dẫn dùng chung.
 * Mọi nội dung là NHÁP, phải được bác sĩ / pháp chế duyệt trước khi phát hành.
 */
class ContentSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $this->upsert('consent_versions', ['version' => '2026-09-26-draft', 'type' => 'health_data'], [
                'content' => implode("\n\n", [
                    '[NHÁP — CẦN DUYỆT] Điều khoản sử dụng và đồng ý xử lý dữ liệu sức khỏe',
                    '1. Sổ Sức Khỏe là sổ theo dõi. Ứng dụng sắp xếp lịch theo đúng đơn bác sĩ kê, không chẩn đoán, không tính hay đổi liều thuốc, không thay thế bác sĩ.',
                    '2. Dữ liệu sức khỏe là dữ liệu cá nhân nhạy cảm (Nghị định 13/2023/NĐ-CP). Chúng tôi chỉ xử lý để hiển thị lịch, nhắc giờ và cho người được bạn mời cùng xem.',
                    '3. Bạn có quyền xem, xuất, sửa và yêu cầu xoá dữ liệu bất cứ lúc nào trong mục Cài đặt, và rút lại sự đồng ý này.',
                    '4. Không tải lên số CCCD, số thẻ BHYT hay mật khẩu. Hãy che các số này trước khi chụp phiếu khám.',
                    '5. Khi có dấu hiệu nguy hiểm, gọi 115 hoặc đến cơ sở y tế gần nhất. Cảnh báo trong ứng dụng không thay cho cấp cứu.',
                ]),
                'effective_date' => '2026-09-26',
                'is_draft' => true,
            ]);

            $templates = DB::table('condition_templates')->pluck('id', 'code');
            $articles = [
                ['guide_hypoglycemia', 'Xử trí hạ đường huyết — quy tắc 15-15', 'diabetes_insulin', [
                    'Dấu hiệu: run tay, vã mồ hôi, đói cồn cào, hoa mắt, tim đập nhanh, lú lẫn.',
                    'Đo đường huyết. Dưới 3,9 mmol/L (hoặc có triệu chứng mà không đo được) thì xử trí ngay.',
                    'Ăn / uống 15 g đường nhanh: 3–4 viên đường, hoặc 150 ml nước cam / nước ngọt, hoặc 1 thìa canh mật ong.',
                    'Nghỉ, đo lại sau 15 phút. Vẫn dưới 3,9 thì lặp lại.',
                    'Khi đã trên 3,9 mà còn hơn 1 giờ mới tới bữa: ăn nhẹ (1 lát bánh mì hoặc 1 hộp sữa).',
                    'Lơ mơ, không tỉnh: gọi 115, không đổ nước vào miệng. Ghi lại và báo bác sĩ nội tiết.',
                ]],
                ['guide_insulin', 'Kỹ thuật tiêm bút insulin', 'diabetes_insulin', [
                    'Rửa tay. Lăn bút giữa 2 lòng bàn tay và lật lên xuống 10–20 lần tới khi thuốc đục đều (insulin trộn sẵn).',
                    'Lắp kim mới, thử 2 UI hướng kim lên cho ra giọt thuốc, rồi vặn đúng liều bác sĩ kê.',
                    'Tiêm dưới da bụng cách rốn ít nhất 5 cm (hoặc đùi), xoay vòng vị trí, không tiêm chỗ bầm / cứng.',
                    'Ấn hết pít-tông, giữ kim 6 giây rồi rút. Ăn trong vòng 5–10 phút với insulin tiêm trước ăn.',
                    'Bảo quản: bút chưa dùng để ngăn mát 2–8 °C (không để ngăn đá); bút đang dùng dưới 30 °C, tối đa 4 tuần.',
                ]],
                ['guide_danger', 'Khi nào cần đi khám ngay', null, [
                    'Lơ mơ, lú lẫn, co giật, không tỉnh — gọi 115.',
                    'Đau đầu dữ dội, đau ngực, khó thở, yếu liệt tay chân — gọi 115.',
                    'Khát nhiều, tiểu nhiều, nôn, thở nhanh, đường huyết trên 16,7 mmol/L — gọi hotline / đi khám ngay.',
                    'Vàng da, vàng mắt, nước tiểu sẫm, mệt lả, đau hạ sườn phải — đi khám trong ngày.',
                    'Phân đen, nôn ra máu, đau thượng vị nhiều — ngừng thuốc giảm đau, đi khám ngay.',
                    'Sốt, tiểu buốt, tiểu rắt, đau hông lưng — đi khám.',
                    'Mẩn ngứa, mày đay, phù môi mắt sau khi uống thuốc — ngừng thuốc mới, đi khám (khó thở: gọi 115).',
                ]],
                ['guide_diet', 'Ăn uống — tổng hợp tờ hướng dẫn của bệnh viện', 'diabetes_oral', [
                    'Nên: 3 bữa chính cố định giờ + 2 bữa phụ nhỏ (10h, 15h); mỗi bữa khoảng 2 miệng bát cơm nhỏ; nhiều rau xanh; cá, thịt gà bỏ da, đậu phụ; dầu thực vật; trái cây ít ngọt ăn nguyên quả; nước lọc.',
                    'Hạn chế: đường, mật ong, bánh kẹo, nước ngọt, nước ép, quả sấy; quả rất ngọt (mít, xoài, chuối chín, na, nho, sầu riêng); bánh mì trắng, miến dong, khoai tây.',
                    'Acid uric: hạn chế phủ tạng, lòng, hải sản, thịt đỏ; măng, nấm, giá; đồ chiên, mì tôm.',
                    'Không rượu bia.',
                ]],
            ];
            foreach ($articles as [$type, $title, $templateCode, $lines]) {
                $this->upsert('content_articles', ['type' => $type], [
                    'condition_template_id' => $templateCode === null ? null : ($templates[$templateCode] ?? null),
                    'title' => '[NHÁP — CẦN DUYỆT] '.$title,
                    'content' => implode("\n", $lines),
                    'is_draft' => true,
                ]);
            }
        });
    }

    private function upsert(string $table, array $identity, array $values): void
    {
        $id = DB::table($table)->where($identity)->value('id');
        if ($id !== null) {
            DB::table($table)->where('id', $id)->update([...$values, 'updated_at' => now()]);

            return;
        }
        DB::table($table)->insert([...$identity, ...$values, 'id' => (string) Str::ulid(), 'created_at' => now(), 'updated_at' => now()]);
    }
}
