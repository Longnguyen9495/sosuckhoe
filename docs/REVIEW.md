# Rà soát tiến độ — sau lượt chạy "Prompt một lượt"

Ngày rà soát: 26/09/2026 · Commit cuối: `618a987`

**Kết luận:** backend đã làm phần lớn 5 giai đoạn và đi đúng hướng. Nhưng frontend mới có 4 màn hình, 4 lỗi mức nghiêm trọng chặn việc cho người thật dùng, và tài liệu báo cáo nhiều hơn những gì thực sự có. Chưa nên cho gia đình bà D. chuyển sang bản mới. Cần một giai đoạn sửa (mục 5) trước.

Cách rà soát: đọc code, `route:list`, chạy toàn bộ test trên SQLite. **Chưa** chạy trên MariaDB, chưa mở bằng trình duyệt, chưa kiểm tra từng route con (xem C3).

---

## 1. Tiến độ thực tế từng giai đoạn

| Giai đoạn | Backend (API, service) | Giao diện | Test | PLAN.md đang tick | Đánh giá |
| --- | --- | --- | --- | --- | --- |
| 1. Nền tảng nhiều khách | Có đủ: migration, tenant scope, ULID, OTP, lời mời, audit, seeder | Đăng nhập, chọn tài khoản / bệnh nhân | Có | 13/13 | Xong, trừ lỗi C1 |
| 2. Lịch và theo dõi | Có đủ: bộ sinh lịch, day API, đánh giá chỉ số, alert, chart, CSV, nhắc | Chỉ có "Hôm nay" + ghi chỉ số. **Không có** màn Lịch tháng, biểu đồ | Có, 1 test đỏ | 13/14 | Backend xong, giao diện thiếu |
| 3. Khách mới tự đăng ký | Có API: onboarding, consent, drugs, prescriptions + preview, templates, thresholds, documents, labs, questions, settings | **Không có màn nào** | Có OnboardingFlowTest | 0/13 | Chỉ có backend |
| 4. Bác sĩ và phòng khám | Có API: danh sách, notes, trả lời câu hỏi, xác nhận ngưỡng, sửa lịch đo, báo cáo PDF | **Không có màn nào** | Có | 0/8 | Chỉ có backend; 2FA không bật được (C2) |
| 5. AI đọc đơn + Zalo | Có: interface + bản giả lập, draft, match, confirm, benchmark 20 đơn, Zalo/SMS giả lập | **Không có màn duyệt** | Có | 0/6 | Chỉ có backend |

Số liệu: 56 route API, 61 test (60 đạt, 1 đỏ), `app.js` 212 dòng và chỉ gọi 7 API.

## 2. Điểm làm tốt (giữ nguyên)

- Tách dữ liệu theo `tenant_id` bằng global scope, id dạng ULID, chọn tài khoản qua `X-Tenant-ID` và có kiểm tra quyền thành viên.
- OTP lưu dạng băm, có giới hạn số lần thử, có `throttle` ở route (`routes/api.php:31-32`).
- Nhật ký truy cập không chứa dữ liệu sức khỏe.
- Bộ sinh lịch có `ScheduleGeneratorBaDTest` đối chiếu với mục 4.1.
- Không tìm thấy code tính, đổi hay gợi ý liều. `dose_text` lưu nguyên văn (ADR-004).
- Dịch vụ ngoài (OTP, push, AI, Zalo, SMS) đều viết sau interface, có bản giả lập.
- Seeder chạy lại nhiều lần không tạo trùng. Dữ liệu mẫu dùng tên "Bà D.", không có CCCD / BHYT.
- Có ghi quyết định vào `DECISIONS.md` như yêu cầu.

---

## 3. Vấn đề cần sửa

### Nghiêm trọng — chặn cho người thật dùng

**C1. OTP giả được bật mặc định, mã cố định `123456`, không chặn theo môi trường**
- Vị trí: `backend/config/services.php:18-19`, `backend/app/Http/Controllers/Api/V1/OtpAuthController.php:23-25`
- Hậu quả: nếu triển khai mà quên sửa `.env`, bất kỳ ai cũng đăng nhập được mọi số điện thoại bằng `123456`, kể cả tài khoản bác sĩ.
- Sửa: bỏ giá trị mặc định `fake`. Chỉ cho phép driver `fake` khi `app()->environment('local', 'testing')`; môi trường khác thì báo lỗi ngay lúc khởi động. Thêm test cho trường hợp này.

**C2. Không có cách bật xác thực 2 lớp cho bác sĩ**
- Vị trí: `backend/app/Http/Middleware/RequireDoctorTwoFactor.php:21`. Không có route thiết lập / xác minh 2FA; `two_factor_secret` khai báo nhưng không được dùng.
- Hậu quả: bác sĩ thật không vào được cổng bác sĩ (test đang tự gán `two_factor_confirmed_at`). Kể cả khi đã bật, middleware chỉ kiểm tra "đã từng bật", không đòi mã ở mỗi phiên đăng nhập.
- Sửa: thêm TOTP: tạo mã QR, xác nhận, và bước nhập mã sau OTP. Token Sanctum chỉ có quyền `2fa-passed` sau khi nhập đúng mã. Middleware kiểm tra quyền của token, không chỉ kiểm tra cột trong bảng user.

**C3. Test tách dữ liệu không phủ các route của giai đoạn 3–5**
- Hiện trạng: cả dự án chỉ có 5 kiểm tra trả về 404 (4 ở `TenantIsolationTest`, 1 ở `PatientDayApiTest`), đều thuộc route giai đoạn 1–2. `DoctorPortalTest`, `AiPrescriptionDraftTest`, `OnboardingFlowTest`: 0.
- Khoảng 25 route mới có `{patient}` chưa có test chéo: documents, lab-results, questions, thresholds, notes, report / pdf, prescriptions, ai-prescription-drafts, conditions, settings, monitoring-plans, alerts / seen.
- Route con như `{threshold}`, `{question}`, `{draft}`, `{plan}`, `{prescription}`, `{alert}` chưa được kiểm tra xem có bắt buộc thuộc đúng `{patient}` không. Ví dụ: lấy `{patient}` của mình ghép với `{question}` của tài khoản khác.
- `CHANGELOG.md` (mục Giai đoạn 4) ghi "Mọi route … TenantIsolationTest cross-tenant 404". **Điều này không đúng.**
- Sửa: dùng `scopeBindings()` cho mọi nhóm route bệnh nhân. Viết test dạng data provider chạy qua **mọi** route có `{patient}` (lấy từ `Route::getRoutes()`, để route mới tự được kiểm tra), cả 2 kiểu: bệnh nhân khác tài khoản, và id con thuộc bệnh nhân khác.

**C4. Giao diện mới làm được khoảng 1/5**
- Vị trí: `backend/resources/js/app.js` (212 dòng). Chỉ có: đăng nhập, chọn tài khoản / bệnh nhân, "Hôm nay", ghi chỉ số.
- Thiếu so với bản mẫu và mục 6 của PLAN: Lịch tháng + biểu đồ, Phác đồ, Hồ sơ, Hỏi bác sĩ, nút + ghi chỉ số dạng bảng trượt, thanh tab dưới đáy kiểu Snapask.
- Thiếu hoàn toàn: luồng đăng ký 7 bước, nhập đơn + xem trước lịch, xác nhận ngưỡng, cài đặt, cổng bác sĩ (mục 6.2), màn duyệt AI.
- Mục 9.2 "Màn Hôm nay, Lịch, Ghi chỉ số" đang được tick dù chưa có màn Lịch.
- Hậu quả: người dùng không tự đăng ký hay nhập đơn được. Mọi tính năng giai đoạn 3–5 chỉ gọi được bằng API.

### Cao

**H1. Đường dẫn tuyệt đối làm app hỏng khi chạy theo README**
- Vị trí: `resources/js/app.js:37` (`fetch('/api/v1…')`), `app.js:210` (`register('/sw.js')`), `public/sw.js:61` (`url.pathname.startsWith('/api/')`).
- Hậu quả: README bảo mở `http://localhost/suckhoe/backend/public`, nhưng app gọi `http://localhost/api/v1` nên bị 404, và service worker không đăng ký được. Nếu chỉ sửa đường dẫn API mà quên `sw.js` thì dữ liệu sức khỏe từ các GET API sẽ bị lưu vào Cache Storage của trình duyệt.
- Sửa: lấy base URL từ thẻ `<meta>` do Blade sinh theo `APP_URL`, dùng đường dẫn tương đối. `sw.js` loại mọi URL có đoạn `/api/`. Hoặc hướng dẫn tạo virtual host `suckhoe.test` trỏ vào `backend/public`.

**H2. Ảnh phiếu khám lưu không mã hoá**
- Vị trí: `DocumentUploadController.php:31-41`. Cột tên là `encrypted_path` nhưng file lưu thường trên disk `local`, tên file giữ nguyên tên gốc (có thể chứa họ tên bệnh nhân).
- Sửa: mã hoá nội dung (`Crypt` hoặc disk có mã hoá), đặt tên file ngẫu nhiên, lưu tên gốc đã mã hoá trong database. Tải ảnh về phải qua controller có Policy.

**H3. Một test đang đỏ, báo cáo ghi "0 failure"**
- Vị trí: `tests/Feature/PatientDayApiTest.php:159`. `log_date` được lưu kèm giờ (`2026-09-26 00:00:00`), test mong `2026-09-26`.
- Sửa: kiểu cột `date` + cast `date:Y-m-d` trong model. Chạy lại toàn bộ test trên cả SQLite và MariaDB.

### Trung bình

**M1. Tài liệu không khớp với code**
- `README.md` vẫn ghi tiêu đề "Giai đoạn 2", không có tài khoản bác sĩ và tài khoản trống như prompt yêu cầu.
- `TODO-NGUOI-THAT.md` dòng cuối ghi "không triển khai Giai đoạn 3", ghi cảnh báo "PHP 8.5" trong khi máy chạy PHP 8.3.33.
- `PLAN.md` chưa tick mục nào của 9.3–9.5 dù đã có API, nên không biết chính xác phần nào xong.
- `CHANGELOG.md` khẳng định sai về test tách dữ liệu (C3) và số test đỏ (H3).
- Sửa: cập nhật cả 4 file theo đúng hiện trạng. Trong PLAN.md tách mỗi mục 9.3–9.5 thành "API" và "Giao diện" để tick riêng.

**M2. Hàng đợi ghi khi mất mạng không bị xoá lúc đăng xuất**
- Vị trí: `resources/js/app.js:154-157` chỉ xoá token trong `sessionStorage`. IndexedDB queue (ADR-005) vẫn còn.
- Hậu quả: trên máy dùng chung, dữ liệu sức khỏe chưa gửi vẫn nằm lại, và có thể được gửi khi người khác đăng nhập.
- Sửa: xoá queue khi đăng xuất. Mỗi mục trong queue gắn user và bệnh nhân; khi gửi thì kiểm tra khớp phiên hiện tại.

**M3. Câu hỏi cho bác sĩ lưu chuyên khoa trong nội dung câu hỏi**
- ADR-002 ghi chuyên khoa và tên bác sĩ được ghép vào đầu câu hỏi vì thiếu cột.
- Sửa: thêm cột `specialty`, `doctor_name`, `due_event_id` vào `questions` để giao diện nhóm câu hỏi theo bác sĩ như bản mẫu.

### Thấp

- **L1.** `resources/views/welcome.blade.php` là trang mặc định của Laravel, còn sót lại → xoá.
- **L2.** Chưa kiểm tra giao diện hiện có với quy ước mục 6.3: vùng bấm 44 px, chế độ tối, màn 360 px.
- **L3.** `DoctorPatientListController` lọc theo vai trò `doctor`. Cần xác nhận bác sĩ chỉ thấy bệnh nhân được giao trong `patient_access`, không thấy mọi bệnh nhân của phòng khám (có trong C3).

---

## 4. Cập nhật đề xuất cho PLAN.md

Thêm **Giai đoạn 6 — Hoàn thiện và sửa lỗi** trước khi cho gia đình bà D. dùng thật. Thứ tự ưu tiên: bảo mật → giao diện cho gia đình → giao diện bác sĩ và AI.

## 5. Checklist sửa (Giai đoạn 6)

Bảo mật — làm trước:
- [x] C1: khoá driver OTP giả theo môi trường, thêm test
- [x] C2: thiết lập / xác nhận / nhập mã TOTP cho bác sĩ, token có quyền `2fa-passed`, thêm test
- [x] C3: `scopeBindings()` cho mọi nhóm route bệnh nhân; test tách dữ liệu tự quét mọi route `{patient}` và mọi id con
- [x] H2: mã hoá ảnh phiếu, tên file ngẫu nhiên, tải ảnh qua Policy
- [x] M2: xoá hàng đợi offline khi đăng xuất, gắn user / bệnh nhân cho từng mục
- [x] H3: sửa kiểu `log_date`; toàn bộ test xanh trên SQLite **và** MariaDB

Chạy được theo README:
- [x] H1: base URL theo `APP_URL`, `sw.js` không cache API; thử thật tại `http://localhost/suckhoe/backend/public`

Giao diện cho gia đình (theo mục 6.1, lấy bản mẫu làm chuẩn):
- [x] Khung chung: đầu trang tím, nút chọn bệnh nhân, thanh tab dưới đáy + nút +, chế độ tối
- [x] Lịch tháng + giai đoạn đo + biểu đồ đường huyết / huyết áp + danh sách mốc
- [x] Phác đồ: thẻ theo bệnh, thẻ thuốc có tồn kho, hướng dẫn hạ đường huyết / tiêm insulin / ăn uống
- [x] Hồ sơ: tải ảnh, bảng xét nghiệm có cờ
- [x] Hỏi bác sĩ: nhóm theo bác sĩ, đánh dấu đã hỏi, xem câu trả lời (cần M3)
- [x] Luồng đăng ký 7 bước + màn đồng ý điều khoản
- [x] Nhập đơn: tìm thuốc, chọn cách dùng bằng nút bấm, xem trước lịch, đóng đơn cũ
- [x] Xác nhận ngưỡng + cài đặt (giờ sinh hoạt, cỡ chữ, thông báo, thành viên, xuất / xoá dữ liệu)

Giao diện bác sĩ và AI:
- [x] Cổng bác sĩ theo mục 6.2 (2 cột trên máy tính, danh sách theo cờ đỏ, hồ sơ, báo cáo PDF)
- [x] Màn duyệt AI: ảnh gốc bên cạnh, xác nhận từng dòng

Tài liệu:
- [x] M1: cập nhật README (đủ 3 tài khoản demo), TODO-NGUOI-THAT, CHANGELOG; tách tick "API" / "Giao diện" trong PLAN.md
- [ ] M3, L1–L3

Kiểm tra cuối:
- [ ] Mở bằng trình duyệt ở chế độ điện thoại 360 px, đi hết luồng: đăng ký → nhập đơn → xem lịch → ghi chỉ số → cảnh báo đỏ → bác sĩ xem báo cáo
- [ ] Chạy lại "Prompt kiểm tra" trong PROMPT.md

---

## 6. Prompt để chạy phần sửa

Dán vào Claude Code tại thư mục `C:\xampp\htdocs\suckhoe`:

```
Đọc @REVIEW.md, @PLAN.md và @PROMPT.md (mục "Prompt một lượt": 6 quy tắc bắt buộc vẫn áp dụng).

Làm hết checklist ở mục 5 của REVIEW.md theo đúng thứ tự: Bảo mật → Chạy được theo README → Giao diện gia đình → Giao diện bác sĩ và AI → Tài liệu → Kiểm tra cuối.

Yêu cầu:
- Mỗi lỗi C/H/M sửa kèm test chứng minh đã sửa; chạy toàn bộ test sau mỗi mục, đỏ thì sửa ngay.
- Test tách dữ liệu phải tự quét mọi route có {patient} từ Route::getRoutes() để route mới sau này tự được kiểm tra.
- Giao diện lấy prototype/index.html làm chuẩn về bố cục và cảm giác (phong cách Snapask, token mục 6.3 PLAN.md), nhưng mọi dữ liệu lấy từ API, không hardcode.
- Chạy test trên cả SQLite và MariaDB của XAMPP. Mở app bằng trình duyệt tại http://localhost/suckhoe/backend/public để tự kiểm tra từng màn; nếu có công cụ chụp màn hình thì chụp ở độ rộng 360 px và xem lại.
- Đánh dấu [x] trong REVIEW.md và PLAN.md CHỈ khi đã có cả code, test và (với giao diện) đã mở thử được. Không tick khi mới có API.
- Báo cáo trung thực: nếu mục nào chưa xong hoặc chưa kiểm chứng được thì ghi rõ, không ghi "đã xong".
- Commit cục bộ sau mỗi nhóm (không push). Cập nhật CHANGELOG.md và DECISIONS.md.

Khi xong: báo cáo từng mục của checklist (đã xong / chưa xong / lý do), số test đạt / đỏ trên SQLite và MariaDB, và những gì cần tôi kiểm tra bằng tay.
```
