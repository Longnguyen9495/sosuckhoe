# Prompt triển khai

Dùng với Claude Code (hoặc AI lập trình tương tự), mở trong thư mục `C:\xampp\htdocs\suckhoe`.

Có 2 cách dùng:
- **Một lượt:** dán duy nhất prompt ngay dưới đây, AI làm liền giai đoạn 1 → 5.
- **Từng giai đoạn:** dùng Prompt chính + prompt từng giai đoạn ở phần sau, duyệt sau mỗi giai đoạn.

---

## Prompt một lượt (làm hết giai đoạn 1 → 5)

```
Bạn là lập trình viên chính của dự án "Sổ theo dõi sức khỏe" trong thư mục này. Nhiệm vụ: xây TOÀN BỘ phần mềm theo PLAN.md, làm liền giai đoạn 1 → 5 trong một lượt, không dừng chờ tôi duyệt giữa các giai đoạn.

=== ĐỌC TRƯỚC ===
Đọc kỹ toàn bộ PLAN.md. Đó là nguồn sự thật duy nhất:
- Phần A (mục 1–4): ca bệnh mẫu bà D. — dữ liệu seed và bài kiểm thử chuẩn.
- Phần B (mục 5–11): đặc tả sản phẩm nhiều khách hàng. Checklist từng giai đoạn ở mục 9.1–9.5, quy ước ở mục 9.6.
Đọc thêm index.html và api.php (bản mẫu) để lấy giao diện và dữ liệu mẫu. Không phát triển tiếp bản mẫu.

=== BƯỚC 0: KIỂM TRA MÔI TRƯỜNG ===
Máy là Windows + XAMPP. Kiểm tra: PHP ≥ 8.2 (C:\xampp\php\php.exe), Composer, Node.js, MariaDB/MySQL của XAMPP, git.
- Thiếu công cụ nào mà tự cài được (ví dụ Composer) thì cài.
- Không cài được thì dừng và báo tôi chính xác cần cài gì. Đây là một trong số ít lý do được phép dừng.
- Chưa có git thì chạy git init. Tạo .gitignore chuẩn Laravel, loại trừ .env, vendor, node_modules, data/, storage/.

=== CÔNG NGHỆ ===
- Backend: Laravel 11 trong thư mục backend/, dùng MariaDB của XAMPP (database tên suckhoe).
- Hàng đợi: driver database (Windows không có sẵn Redis). Viết code sao cho sau này chỉ đổi cấu hình sang Redis.
- Frontend: PWA bằng JS thuần trong frontend/ (được dùng Vue 3 nếu màn hình quá nhiều), gọi API /api/v1.
- Bản mẫu chuyển vào prototype/ (index.html, api.php, assets/, data/), sửa đường dẫn để vẫn chạy được.

=== 6 QUY TẮC BẮT BUỘC ===
1. An toàn y khoa: KHÔNG viết code tính, đổi hay gợi ý liều thuốc. Bộ sinh lịch chỉ quyết định GIỜ; liều và số lượng lấy nguyên từ đơn. Không hiểu cách dùng thì bắt người dùng chọn giờ, không tự đoán.
2. Tách dữ liệu khách hàng: mọi bảng dữ liệu khách có tenant_id + global scope; mọi route có {patient} qua PatientPolicy; id công khai dạng ULID; mọi route bệnh nhân nằm trong TenantIsolationTest.
3. Không hardcode dữ liệu bệnh nhân trong code; dữ liệu bà D. chỉ nằm trong seeder.
4. Không lưu số CCCD, số thẻ BHYT, mật khẩu tra cứu in trên phiếu; không ghi dữ liệu sức khỏe ra file log.
5. Ngưỡng cảnh báo luôn lấy từ patient_thresholds; ngưỡng chép từ mẫu hiện nhãn "chưa được bác sĩ xác nhận".
6. Giao diện theo mục 6 PLAN.md: phong cách Snapask, token màu mục 6.3, font Be Vietnam Pro, vùng bấm ≥ 44 px, có chế độ tối, không cuộn ngang trên điện thoại 360 px. Chữ hiển thị tiếng Việt có dấu, đặt trong file ngôn ngữ.

=== DỊCH VỤ BÊN NGOÀI: LÀM DẠNG GIẢ LẬP ===
Không có tài khoản thật, nên mỗi dịch vụ viết sau một interface, có bản giả lập chạy được ngay, bản thật bật bằng biến .env:
- OTP: bản giả lập ghi mã ra log (chỉ ở môi trường local) và hiện trên màn hình dev; bản thật là SMS/email.
- Web Push: tự sinh khoá VAPID cho local.
- AI đọc đơn thuốc: bản giả lập trả về kết quả cố định cho 3 ảnh đơn của bà D.; bản thật gọi API mô hình AI qua biến AI_API_KEY.
- Zalo ZNS / SMS: bản giả lập ghi tin ra bảng notifications; bản thật cần thông tin Zalo OA.
- Lưu ảnh: disk local có mã hoá; cấu hình sẵn để đổi sang S3.
Ghi rõ biến .env nào cần điền trong .env.example và README.md.

=== TRÌNH TỰ ===
Làm lần lượt giai đoạn 1 → 5, mỗi giai đoạn làm hết checklist tương ứng (mục 9.1 → 9.5).
Trong mỗi giai đoạn:
- Làm từng mục checklist; mỗi API có test tính năng; chạy test sau mỗi mục; test đỏ thì sửa ngay.
- Xong mục nào đánh dấu [x] mục đó trong PLAN.md.

CỔNG CHUYỂN GIAI ĐOẠN — chỉ sang giai đoạn sau khi đạt đủ:
- Toàn bộ test xanh (php artisan test).
- Tiêu chí hoàn thành của giai đoạn trong bảng mục 9 đạt được bằng test tự động. Tiêu chí cần người thật (3 gia đình, phòng khám thử nghiệm) thì thay bằng test mô phỏng và ghi vào TODO-NGUOI-THAT.md.
- Giai đoạn 1: TenantIsolationTest xanh 100%; seeder bà D. chạy được; lệnh chuyển data/log.json chạy được.
- Giai đoạn 2: ScheduleGeneratorBaDTest xanh — viết test này TRƯỚC khi viết bộ sinh lịch. Nhập 3 đơn bà D. với giờ sinh hoạt 07:00 / 12:00 / 18:30 / 22:00 phải ra đúng từng dòng bảng mục 4.1.
- Giai đoạn 3: test mô phỏng toàn bộ luồng đăng ký 7 bước từ tài khoản trống tới có lịch.
- Giai đoạn 4: test bác sĩ không sửa được nhật ký gia đình; bác sĩ không thấy bệnh nhân không được giao.
- Giai đoạn 5: script đo độ chính xác đọc đơn chạy được với bản giả lập.
- Commit cục bộ với thông điệp "Giai đoạn N: ..." (không push).
- Ghi CHANGELOG.md.

=== KHI GẶP ĐIỀU CHƯA RÕ ===
Vì làm một lượt, KHÔNG dừng để hỏi về chi tiết kỹ thuật. Tự chọn phương án an toàn nhất, đúng tinh thần PLAN.md, và ghi vào DECISIONS.md: vấn đề, phương án đã chọn, lý do, cách đổi nếu tôi không đồng ý.
Việc liên quan y khoa, pháp lý hoặc nội dung thật (ngưỡng khác mục 4.3, câu chữ điều khoản, chính sách dữ liệu): viết bản nháp đánh dấu rõ "[NHÁP — CẦN DUYỆT]" và ghi vào DECISIONS.md. Không tự khẳng định là đúng.
Chỉ được dừng hẳn khi: thiếu công cụ không tự cài được, hoặc một thao tác có thể làm mất dữ liệu có sẵn (data/log.json, ảnh trong assets/img/). Trước khi di chuyển file, sao lưu vào backup/.

=== KẾT THÚC ===
Khi xong cả 5 giai đoạn:
1. Chạy toàn bộ test lần cuối.
2. Chạy lại bài rà soát: có truy vấn nào bỏ qua tenant_id, route {patient} nào thiếu Policy, chỗ nào đụng tới liều thuốc, dữ liệu bệnh nhân hardcode, hay log dữ liệu nhạy cảm không. Sửa hết những gì tìm thấy.
3. Viết README.md: cách cài và chạy trên XAMPP từng bước, tài khoản demo (gia đình bà D., 1 bác sĩ, 1 tài khoản trống để thử đăng ký), danh sách biến .env cần điền để bật dịch vụ thật.
4. Báo cáo cho tôi: đã xong những gì theo từng giai đoạn; số test và kết quả; mục checklist nào chưa xong và vì sao; danh sách trong DECISIONS.md và TODO-NGUOI-THAT.md cần tôi xem.
```

Cách dùng:
- Đầu mỗi phiên làm việc, dán **Prompt chính**.
- Sau đó dán **prompt của giai đoạn** đang làm.
- Làm xong một giai đoạn thì kiểm tra kết quả rồi mới dán giai đoạn tiếp theo.

---

## Prompt chính

```
Bạn là lập trình viên chính của dự án "Sổ theo dõi sức khỏe" trong thư mục này.

Đọc kỹ PLAN.md trước khi làm bất cứ việc gì. Đó là nguồn sự thật duy nhất của dự án:
- Phần A (mục 1–4) là ca bệnh mẫu bà D.: dữ liệu seed và bài kiểm thử chuẩn.
- Phần B (mục 5–11) là đặc tả sản phẩm nhiều khách hàng: vai trò, luồng đăng ký, bộ sinh lịch, giao diện, schema, API, pháp lý, lộ trình và checklist từng giai đoạn.

Bản mẫu hiện có (index.html, api.php, assets/) chỉ dùng để tham chiếu giao diện và dữ liệu. Không phát triển tiếp bản mẫu.

Công nghệ: Laravel 11 (PHP 8.3), MySQL 8, Redis cho hàng đợi, frontend PWA bằng JS thuần (có thể dùng Vue 3 nếu PLAN.md cho phép). Môi trường phát triển là XAMPP trên Windows.

Quy tắc bắt buộc, không có ngoại lệ:
1. An toàn y khoa: không viết bất kỳ code nào tính, đổi hay gợi ý liều thuốc. Bộ sinh lịch chỉ quyết định GIỜ; liều và số lượng lấy nguyên từ đơn. Không hiểu cách dùng thì bắt người dùng chọn giờ, không tự đoán.
2. Tách dữ liệu khách hàng: mọi bảng dữ liệu khách có tenant_id; dùng global scope; mọi route có {patient} phải qua PatientPolicy; id công khai dạng ULID. Mỗi route bệnh nhân mới phải được thêm vào TenantIsolationTest.
3. Không hardcode dữ liệu bệnh nhân trong code. Dữ liệu bà D. chỉ nằm trong seeder.
4. Không lưu số CCCD, số thẻ BHYT, mật khẩu tra cứu in trên phiếu.
5. Ngưỡng cảnh báo luôn lấy từ patient_thresholds; ngưỡng từ mẫu hiển thị nhãn "chưa được bác sĩ xác nhận".
6. Giao diện theo mục 6 của PLAN.md (phong cách Snapask, token màu mục 6.3, font Be Vietnam Pro, vùng bấm ≥ 44 px, có chế độ tối, không cuộn ngang trên điện thoại). Toàn bộ chữ hiển thị bằng tiếng Việt có dấu, đặt trong file ngôn ngữ.

Cách làm việc:
- Chỉ làm giai đoạn tôi giao. Làm lần lượt từng mục trong checklist của giai đoạn đó ở PLAN.md.
- Trước khi code một mục lớn, nêu ngắn gọn cách làm và các file sẽ tạo/sửa.
- Mỗi API phải có test tính năng. Chạy test sau mỗi mục; test đỏ thì sửa trước khi làm tiếp.
- Làm xong mục nào thì đánh dấu [x] mục đó trong PLAN.md.
- Gặp điều PLAN.md chưa nói rõ hoặc mâu thuẫn: dừng lại và hỏi tôi, không tự quyết những việc liên quan tới y khoa, pháp lý hay dữ liệu người dùng.
- Không commit hay push khi tôi chưa yêu cầu.
- Cuối giai đoạn: chạy toàn bộ test, cập nhật CHANGELOG.md, báo cáo cho tôi: đã xong gì, test nào chạy, còn gì dở dang, cần tôi quyết định gì.
```

---

## Prompt từng giai đoạn

### Giai đoạn 1 — Nền tảng nhiều khách hàng

```
Làm giai đoạn 1 trong PLAN.md (mục 9.1).

Mục tiêu hoàn thành:
- Chuyển bản mẫu vào thư mục prototype/ và sửa đường dẫn để vẫn chạy được.
- Tạo dự án Laravel trong backend/ với toàn bộ migration ở mục 7.3.
- Đăng nhập OTP, tài khoản, mời thành viên, vai trò theo bệnh nhân, nhật ký truy cập.
- Seeder: danh mục thuốc, 5 mẫu bệnh, dữ liệu bà D. lấy từ các hằng số MEDS, EVENTS, CONDS, LABS, RECORDS, QA trong prototype/index.html.
- Lệnh chuyển data/log.json sang bảng logs và readings.
- TenantIsolationTest: 2 tài khoản, mọi API chéo phải trả về 404.
- Màn đăng nhập và màn chọn bệnh nhân theo mục 6.

Bắt đầu bằng việc đề xuất cấu trúc thư mục backend/ và danh sách migration, chờ tôi đồng ý rồi mới code.
```

### Giai đoạn 2 — Lịch và theo dõi

```
Làm giai đoạn 2 trong PLAN.md (mục 9.2).

Trọng tâm là bộ sinh lịch (mục 5.4). Viết ScheduleGeneratorBaDTest TRƯỚC: nhập 3 đơn của bà D. (mục 3) với giờ sinh hoạt 07:00 / 12:00 / 18:30 / 22:00, kết quả phải khớp từng dòng với bảng khung giờ ở mục 4.1. Sau đó mới viết UsageRuleParser và ScheduleGenerator cho tới khi test xanh.

Tiếp theo: endpoint lịch trong ngày, đánh giá chỉ số theo patient_thresholds, cảnh báo, chuyển các màn Hôm nay / Lịch / Ghi chỉ số của bản mẫu sang gọi API (giữ nguyên giao diện), PWA và thông báo đẩy.
```

### Giai đoạn 3 — Khách mới tự đăng ký

```
Làm giai đoạn 3 trong PLAN.md (mục 9.3).

Tiêu chí: một gia đình chưa từng dùng app tự đăng ký, nhập đơn và có lịch trong ≤ 15 phút. Luồng 7 bước theo mục 5.3. Bước nhập đơn phải có màn xem trước lịch trước khi lưu. Ngưỡng chép từ mẫu phải hiện nhãn "chưa được bác sĩ xác nhận".

Khi xong, viết một kịch bản kiểm thử tay (từng bước, dữ liệu mẫu) để tôi đưa cho 3 gia đình thử nghiệm.
```

### Giai đoạn 4 — Bác sĩ và phòng khám

```
Làm giai đoạn 4 trong PLAN.md (mục 9.4).

Màn bác sĩ theo mục 6.2: bố cục 2 cột trên máy tính, danh sách sắp theo cờ đỏ. Bác sĩ được xác nhận ngưỡng, sửa lịch đo, ghi nhận xét, trả lời câu hỏi, nhưng không được sửa nhật ký của gia đình. Bắt buộc xác thực 2 lớp cho vai trò bác sĩ. Báo cáo PDF một trang.
```

### Giai đoạn 5 — AI đọc đơn và Zalo

```
Làm giai đoạn 5 trong PLAN.md (mục 9.5).

AI chỉ trả về đề xuất theo JSON cố định; người dùng phải xác nhận từng dòng mới được lưu, có ảnh gốc bên cạnh. Dựng bộ 20 đơn mẫu có đáp án và script đo độ chính xác; mục tiêu ≥ 95% dòng thuốc đúng. Dùng 3 đơn của bà D. (ảnh trong prototype/assets/img/) làm 3 đơn đầu tiên của bộ mẫu.

Phần Zalo ZNS: viết sẵn code và mẫu tin, nhưng hỏi tôi thông tin tài khoản Zalo OA trước khi cấu hình.
```

---

## Prompt kiểm tra khi cần

```
Rà soát code hiện tại theo PLAN.md:
1. Có truy vấn nào bỏ qua global scope tenant_id hoặc route {patient} nào thiếu PatientPolicy không?
2. Có chỗ nào tính, đổi hoặc gợi ý liều thuốc không?
3. Có dữ liệu bệnh nhân nào hardcode ngoài seeder không?
4. Có chỗ nào lưu số CCCD, BHYT hoặc log dữ liệu sức khỏe ra file log không?
Liệt kê từng vấn đề kèm file:dòng, mức độ nghiêm trọng và cách sửa. Chưa sửa gì cho tới khi tôi đồng ý.
```
