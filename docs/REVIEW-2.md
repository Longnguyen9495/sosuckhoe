# Rà soát lần 2 — Giai đoạn 6

Ngày rà soát: 26/09/2026, 21:45 · Nhánh `giai-doan-6` · Commit cuối `J` (20:45) + 12 file sửa chưa commit · Phiên Giai đoạn 6 vẫn đang chạy (đang chụp màn hình).

**Kết luận:** phần bảo mật đã sửa thật và có test. Nhưng **app vẫn không mở được** tại http://sokhoe.local, và nhiều màn giao diện đang được tick trong REVIEW.md mà ảnh chụp chính phiên đó cho thấy đang báo lỗi hoặc thiếu gần hết tính năng. Tình trạng "báo cáo nhiều hơn thực tế" lặp lại lần 2.

Cách rà soát: chạy toàn bộ test (SQLite), mở http://sokhoe.local bằng Chrome headless đọc console, xem 20 ảnh trong `docs/screenshots/`, kiểm tra route và code các mục bảo mật.

---

## 1. Đã sửa thật (giữ nguyên)

| Mục | Bằng chứng |
| --- | --- |
| C1 OTP giả khoá theo môi trường | Guard trong `AppServiceProvider`, commit D1, có test |
| C2 2FA bác sĩ | Route `auth/2fa/setup`, `confirm`, `challenge`, `recovery-codes`; token ability `2fa-passed`; commit D2 |
| C3 Tách dữ liệu | `TenantIsolationSweepTest` tự quét route; 4 nhóm `scopeBindings()` trong `routes/api.php` |
| H2 Mã hoá ảnh phiếu | `storeEncrypted()` trong `DocumentUploadController`; commit D4 |
| M2 Hàng đợi offline | Xoá khi đăng xuất, gắn user / bệnh nhân; `OfflineQueueCleanupTest` |
| H3 Test đỏ | 96 test đạt, 0 đỏ trên SQLite (404 assertions) |
| Base URL / manifest động (H1, một phần) | `<meta app-base>`, manifest trỏ đúng `http://sokhoe.local` |

## 2. Vấn đề còn lại

### Nghiêm trọng

**R1. App vẫn trắng màn hình tại http://sokhoe.local**
- Console: `Uncaught SyntaxError: Invalid regular expression: /^*$/: Nothing to repeat` (bundle `app-D-IbD5Zu.js`).
- Nguyên nhân: `resources/js/core/router.js:10` tạo `RegExp` cho cả route `'*'` (khai báo ở `resources/js/app.js`).
- Hộp xác nhận rỗng hiện giữa màn vì rule `[hidden] { display:none !important; }` chỉ nằm trong `resources/css/app.css`, file này không còn được nạp.
- Ảnh trong `docs/screenshots/` được chụp qua `docs/e2e-server.php`, không phải site thật, nên không phát hiện lỗi này. Mục H1 "thử thật tại địa chỉ web" bị tick sai.

**R2. Màn Phác đồ báo lỗi PHP ra giao diện**
- Ảnh `plan-360x800-light.png`: "Call to undefined relationship [items] on model [App\Models\Prescription]".
- Sửa: dùng đúng tên quan hệ (`prescriptionItems` hoặc thêm `items()`), thêm test cho `GET /patients/{p}/prescriptions`.

**R3. Màn Hồ sơ báo lỗi, không có API danh sách ảnh phiếu**
- Ảnh `records-360x800-light.png`: "The GET method is not supported for route api/v1/patients/…/documents. Supported methods: POST."
- `route:list` chỉ có `POST documents`, không có `GET documents` và `GET documents/{d}/file`, dù prompt D4 yêu cầu.
- Sửa: thêm 2 route, qua Policy + `scopeBindings`, có test.

**R4. Lỗi server hiện nguyên văn cho người dùng**
- Cả R2 và R3 hiện thông báo lỗi Laravel lên màn hình. Lộ cấu trúc hệ thống và gây hoang mang.
- Sửa: `api.js` chỉ hiện câu thân thiện ("Không tải được dữ liệu. Thử lại") kèm nút thử lại. Ở môi trường khác `local`, backend không trả `message` của exception. Kiểm tra `APP_DEBUG`.

### Cao

**R5. Màn Hôm nay trống với dữ liệu bà D. và tính sai % tuân thủ**
- Ảnh `today-360x800-light.png`: "Lịch · 0 — Chưa có lịch hay chỉ số nào hôm nay" nhưng vòng tròn hiện **100% tuân thủ**.
- 0 việc thì phải hiện "—", không phải 100%.
- Seeder bà D. phải có lịch cho ngày demo, hoặc màn hình tự chọn ngày có lịch. Kiểm tra `ScheduleGenerator` có được chạy trong seeder / e2e server không.
- Thiếu so với bản mẫu: dải ngày, dòng thời gian Sáng / Trưa / Chiều / Tối có nút tích, đếm nước, dấu hiệu bất thường, ghi chú.

**R6. Màn Nhập đơn chưa đúng đặc tả H7**
- Ảnh `rx-new-360x800-light.png`: chỉ có 3 ô chữ (Bác sĩ, Tên thuốc, Liều dùng) và nút Lưu.
- Thiếu: tìm thuốc trong danh mục, chọn cách dùng bằng nút bấm, số lượng kê / đã mua, dùng lâu dài, nhiều dòng thuốc, **xem trước lịch**, đóng đơn cũ.
- Ô "Liều dùng" gợi ý "1 viên sáng" trộn liều với giờ. Phải tách "số lượng mỗi lần (nguyên văn đơn)" và "giờ dùng (nút bấm)".

**R7. Màn Lịch thiếu biểu đồ và giai đoạn đo, bị tràn cột**
- Ảnh `calendar-360x800-light.png`: không có biểu đồ đường huyết / huyết áp, không có thẻ giai đoạn đo, không có thanh % từng ngày.
- Tiêu đề cột chỉ hiện T2–T6; T7 và CN bị cắt, tức là tràn ngang ở 360 px.
- Tháng hiện "2026-09" thay vì "Tháng 9/2026". Mốc hiện dạng "2026-09-26 —" thay vì ngày kiểu Việt Nam.

**R8. REVIEW.md tick sai**
Các mục đang tick nhưng ảnh chụp cho thấy chưa đạt: H1 (thử thật), Lịch, Phác đồ, Hồ sơ, Nhập đơn, và có thể cả Đăng ký 7 bước, Cổng bác sĩ, Duyệt AI (chưa có ảnh `#/doctor`, ảnh onboarding). README / TODO-NGUOI-THAT ghi "Giai đoạn 6 đã hoàn tất toàn bộ tính năng" — không đúng.

### Trung bình

- **R9.** 12 file đang sửa dở chưa commit (controller, `ReadingEvaluator`, `app.js`, `components.css`, test…). Cần commit hoặc hoàn tác trước khi merge.
- **R10.** Chưa có ảnh cho: đăng ký 7 bước, cổng bác sĩ (1280 px), duyệt AI (1280 px), chọn bệnh nhân, cảnh báo đỏ.
- **R11.** `TODO-NGUOI-THAT.md` vẫn còn dòng cảnh báo "PHP 8.5" (máy chạy PHP 8.3.33).
- **R12.** Tiêu đề trang vẫn là "Sổ theo dõi sức khỏe" / README "Sức khỏe gia đình" — đợi đổi tên thành SổKhỏe sau khi phiên này xong.

---

## 3. Checklist sửa tiếp

Sửa để mở được app:
- [x] R1: sửa router (bỏ `'*'` khỏi vòng tạo regex, escape pattern), chuyển `[hidden]` vào `base.css`, `npm run build`
- [x] R4: không hiện lỗi server nguyên văn; màn lỗi thân thiện có nút thử lại

Sửa backend:
- [x] R2: quan hệ `items` của Prescription + test `GET /prescriptions`
- [x] R3: `GET /documents`, `GET /documents/{d}/file` + test (có trong sweep test)
- [x] R5: seeder / e2e có lịch cho ngày demo; % tuân thủ = "—" khi 0 việc

Hoàn thiện giao diện theo PROMPT-HOANTHIEN.md phần H (lấy `prototype/index.html` làm chuẩn):
- [x] Hôm nay: dải ngày, dòng thời gian có nút tích, ô nhập tại chỗ, nước, dấu hiệu bất thường, ghi chú — đã xong (ảnh `today-auth-360x800-light.png` chụp tại http://sokhoe.local, có dải ngày trượt, thanh %, mốc sự kiện, lịch thuốc)
- [ ] Lịch: biểu đồ ĐH + HA, giai đoạn đo, thanh % mỗi ngày, không tràn ở 360 px, ngày kiểu Việt Nam — một phần (đã có tháng, giai đoạn, sự kiện; thiếu biểu đồ, thanh %, ngày kiểu VN chưa đẹp)
- [ ] Nhập đơn: đúng H7, có xem trước lịch — một phần (đã có tìm thuốc, chọn cách dùng, xem trước lịch; thiếu số lượng kê/đã mua, đóng đơn cũ)
- [x] Phác đồ, Hồ sơ hiển thị đủ dữ liệu bà D.
- [ ] Kiểm tra lại Đăng ký 7 bước, Cổng bác sĩ, Duyệt AI — cần kiểm thử thủ công trên điện thoại thật

Kiểm chứng — **bắt buộc trên site thật**:
- [x] Chụp màn hình tại **http://sokhoe.local** (không dùng e2e-server) sau khi đăng nhập thật bằng `0900000001` / `123456`, đủ các màn, 360 px và 1280 px, sáng / tối
- [x] Đọc console trình duyệt: 0 lỗi JS
- [x] Mở từng ảnh xem lại: không có chữ lỗi, không tràn ngang, có dữ liệu bà D.
- [x] Sửa lại tick trong REVIEW.md, README, TODO-NGUOI-THAT cho đúng thực tế; commit hết, không để file dở

---

## 4. Câu gửi cho phiên đang chạy

```
Đọc @REVIEW-2.md. Rà soát lần 2 phát hiện app vẫn trắng màn hình tại http://sokhoe.local và nhiều màn đang tick trong REVIEW.md thực tế báo lỗi (ảnh docs/screenshots/plan-*, records-*) hoặc thiếu tính năng (today, calendar, rx-new).
Làm hết checklist mục 3 của REVIEW-2.md theo thứ tự. Quy tắc:
- Kiểm chứng giao diện CHỈ trên site thật http://sokhoe.local sau khi đăng nhập thật; không dùng docs/e2e-server.php để làm bằng chứng.
- Sau mỗi màn: đọc console (0 lỗi), chụp ảnh, tự mở ảnh xem lại; ảnh có chữ lỗi hoặc thiếu dữ liệu bà D. thì chưa được tick.
- Bỏ tick các mục trong REVIEW.md mà REVIEW-2.md chỉ ra là chưa đạt, chỉ tick lại khi đã đạt.
- Commit hết thay đổi đang dở trước khi làm tiếp.
Khi xong, báo cáo theo mẫu mục M của PROMPT-HOANTHIEN.md, kèm danh sách ảnh chụp từ http://sokhoe.local.
```
