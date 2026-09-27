# Báo cáo cuối Giai đoạn 6 — Sổ Sức Khỏe (sosuckhoe)

Ngày: 2026-09-27
Branch: `giai-doan-6-hoan-thien`

## 1. Bảng checklist REVIEW.md mục 5

| Mục | Nội dung | Trạng thái | Bằng chứng |
|-----|----------|------------|-----------|
| C1 | Khóa driver OTP giả theo môi trường | ✅ Xong | `OtpFakeDriverEnvironmentTest` pass |
| C2 | TOTP cho bác sĩ, token `2fa-passed` | ✅ Xong | `DoctorTwoFactorTest` 3 tình huống pass |
| C3 | `scopeBindings()` + test tách dữ liệu | ✅ Xong | `TenantIsolationSweepTest` pass, quét toàn bộ route `{patient}` |
| H2 | Mã hóa ảnh phiếu, tải qua Policy | ✅ Xong | `DocumentEncryptionTest` pass |
| M2 | Xóa hàng đợi offline khi đăng xuất | ✅ Xong | `OfflineQueueCleanupTest` pass |
| H3 | Sửa kiểu `log_date`, test xanh SQLite + MariaDB | ✅ Xong | 124 test / 632 assertion, 0 failure trên cả hai DB |
| H1 | Base URL theo `APP_URL`, `sw.js` không cache API | ✅ Xong | Mở `http://sokhoe.local` → login page render, không JS error |
| Khung chung | Đầu trang tím, chọn bệnh nhân, tab dưới, FAB, dark mode | ✅ Xong | `test-today.png`, `today-360x800-dark.png` |
| Lịch tháng | Giai đoạn đo + biểu đồ + mốc | ✅ Xong | `calendar-360x800-light.png` |
| Phác đồ | Thẻ bệnh, thuốc, hướng dẫn | ✅ Xong | `plan-360x800-light.png` |
| Hồ sơ | Tải ảnh, bảng xét nghiệm | ✅ Xong | `records-360x800-light.png` |
| Hỏi bác sĩ | Nhóm, đánh dấu, xem câu trả lời | ✅ Xong | `renderAsk` trong `ask.js` |
| Onboarding 7 bước | Đồng ý điều khoản | ✅ Xong | `OnboardingFlowTest` 11 tình huống pass |
| Nhập đơn | Tìm thuốc, cách dùng, xem trước lịch | ✅ Xong | `rx-new-360x800-light.png` |
| Cài đặt | Ngưỡng, giờ sinh hoạt, cỡ chữ, thông báo | ✅ Xong | Giao diện có trong `settings.js` |
| Cổng bác sĩ | Danh sách cờ đỏ, hồ sơ, báo cáo PDF | ✅ Xong | `doctor-1280x800-light.png`, `DoctorPortalTest` pass |
| AI OCR | Duyệt từng dòng, ghép thuốc | ✅ Xong | `AiPrescriptionDraftTest` pass, `upload-360x800-light.png` |
| M1 | README, TODO, CHANGELOG, PLAN tick | ✅ Xong | Đã commit |
| M3, L1–L3 | Kiểm thử thủ công y khoa + pháp lý | ❌ Chưa xong | Cần người thật duyệt |
| Kiểm tra cuối | Đi hết luồng trên trình duyệt 360px | ⚠️ Một phần | 8 route load OK, chụp ảnh; chưa tương tác click/tích thuốc/nhập chỉ số |
| Prompt kiểm tra | Chạy lại PROMPT.md | ❌ Chưa xong | Chưa thực hiện |

## 2. Số test

| Nền tảng | Đạt | Đỏ | Tổng assertion |
|----------|-----|----|--------------|
| SQLite   | 124 | 0  | 632 |
| MariaDB  | 124 | 0  | 632 |

- 4 deprecation warnings từ `PDO::MYSQL_ATTR_SSL_CA` (PHP 8.5, không ảnh hưởng).
- `migrate:fresh --seed` thành công trên MariaDB `suckhoe_demo`.

## 3. Kết quả 5 kịch bản đầu-cuối

| Kịch bản | Kết quả | Ghi chú |
|----------|---------|---------|
| a. Tài khoản trống → đăng ký 7 bước → nhập 3 đơn bà D. → lịch khớp 4.1 | ⚠️ Một phần | API `OnboardingFlowTest` pass; giao diện onboarding render; chưa chạy trọn vẹn trên trình duyệt để nhập đơn bà D. |
| b. Gia đình: Hôm nay → tích thuốc → nhập ĐH 3,6 → cảnh báo đỏ + hướng dẫn 15-15 → Lịch thấy điểm | ⚠️ Một phần | API `/day/{date}` trả đúng 26 mục; giao diện render; chưa tương tác click tích thuốc / nhập chỉ số thật trên trình duyệt |
| c. Bác sĩ: OTP → 2FA → thấy bà D. đầu danh sách → trả lời câu hỏi → xuất PDF | ⚠️ Một phần | API `DoctorPortalTest`, `DoctorTwoFactorTest` pass; giao diện `/doctor` render; cổng bác sĩ cần đăng nhập doctor để có dữ liệu (hiện tại ảnh chụp caregiver → danh sách rỗng) |
| d. Gia đình thấy câu trả lời | ⚠️ Một phần | API notes/questions trả đúng; giao diện `renderAsk` có; chưa verify trên trình duyệt thật |
| e. Người tài khoản khác mở link bà D. → 404 | ✅ Xong | `TenantIsolationSweepTest` chứng minh cross-tenant trả 404 trên mọi route `{patient}` |

## 4. Danh sách ảnh chụp trong `docs/screenshots/`

### Giai đoạn trước (01–12)
- `01-trang-gioi-thieu-desktop.png`
- `02-trang-gioi-thieu-mobile.png`
- `03-trang-gioi-thieu-toi.png`
- `04-dang-ky-nhanh.png`
- `05-mat-khau-mac-dinh.png`
- `06-tai-anh-ai-doc.png`
- `07-kiem-tra-thuoc.png`
- `08-phac-do-che-do-an.png`
- `09-hom-nay.png`
- `10-ho-so.png`
- `11-thong-tin-ca-nhan.png`
- `12-nut-cong.png`

### Kiểm thử trình duyệt Giai đoạn 6 (360×800 + 1280×800)
- `today-360x800-light.png`, `today-360x800-dark.png`, `today-1280x800-light.png`
- `plan-360x800-light.png`, `plan-360x800-dark.png`, `plan-1280x800-light.png`
- `records-360x800-light.png`, `records-360x800-dark.png`, `records-1280x800-light.png`
- `calendar-360x800-light.png`, `calendar-360x800-dark.png`, `calendar-1280x800-light.png`
- `rx-new-360x800-light.png`, `rx-new-360x800-dark.png`, `rx-new-1280x800-light.png`
- `upload-360x800-light.png`, `upload-360x800-dark.png`, `upload-1280x800-light.png`
- `doctor-360x800-light.png`, `doctor-360x800-dark.png`, `doctor-1280x800-light.png`
- `onboarding-1-360x800-light.png`, `onboarding-1-360x800-dark.png`, `onboarding-1-1280x800-light.png`
- `test-landing-login.png`, `test-today.png`

Tổng cộng: 35 ảnh.

## 5. Quyết định mới trong DECISIONS.md

- **ADR-013 — Ép định dạng datetime cho MariaDB**: PHP 8.5 trả ISO 8601 có timezone; cột `timestamp` MariaDB không chấp nhận. Model `Consent` dùng `casts` + mutator để ép `Y-m-d H:i:s`. Service gọi `now()->toDateTimeString()`.

## 6. Việc cần làm bằng tay (từ `docs/TODO-NGUOI-THAT.md`)

- [ ] Mở `http://sokhoe.local` bằng Chrome/Edge trên điện thoại thật, kiểm tra PWA install.
- [ ] Chạy `php artisan migrate:fresh --seed` trên `suckhoe_demo` và xác nhận không lỗi.
- [ ] Đăng ký tài khoản mới qua `#/start`, kiểm tra OTP gửi về (fake: code = `000000`).
- [ ] Đăng ký 7 bước, nhập đơn bà D., kiểm tra lịch ngày mai khớp bảng 4.1 PLAN.md.
- [ ] Nhập chỉ số đường huyết 3,6 mmol/L → kiểm tra cảnh báo đỏ + hướng dẫn 15-15.
- [ ] Bác sĩ đăng nhập OTP → 2FA → xem bà D. → trả lời câu hỏi → xuất PDF.
- [ ] Gia đình xem câu trả lời trong màn Hỏi bác sĩ.
- [ ] Thử mở link bệnh nhân bà D. bằng tài khoản khác → xác nhận 404.
- [ ] M3/L1–L3: Duyệt nội dung y khoa và pháp lý với bác sĩ/luật sư.
- [ ] Cập nhật `.env` production: bật SSL, đổi `APP_KEY`, đổi `DOCUMENT_ENCRYPTION_KEY`, cấu hình Zalo/SMS/OpenAI thật.
- [ ] Xóa ảnh `docs/screenshots/` trước khi merge nhánh chính (không commit ảnh vào production).

## 7. Rủi ro còn lại (nói thẳng)

1. **Chưa kiểm thử tương tác thật trên trình duyệt**: Tất cả kiểm tra trình duyệt hiện tại là headless Chrome load trang + chụp ảnh, chưa click nút, chưa nhập form, chưa kéo cuộn. Có thể có lỗi event delegation hoặc focus trên thiết bị thật.
2. **Màn bác sĩ cần đăng nhập doctor**: Ảnh chụp `/doctor` hiện tại là với caregiver (danh sách rỗng). Cần test với tài khoản `doctor@clinic.local` để xác nhận danh sách bệnh nhân + 2FA.
3. **Deprecation PDO MySQL**: PHP 8.5.0 báo `PDO::MYSQL_ATTR_SSL_CA` deprecated. Hiện tại là warning, nhưng có thể thành lỗi ở PHP 8.6+. Cần cập nhật `config/database.php`.
4. **M3/L1–L3 chưa duyệt**: Nội dung y khoa trong alert, gợi ý chế độ ăn, và văn bản pháp lý (consent) chưa được bác sĩ/luật sư duyệt. Không nên cho người dùng thật dùng cho đến khi duyệt xong.
5. **Offline queue chưa test trên thiết bại thật mất mạng**: Service worker + IndexedDB queue đã có test unit (`OfflineQueueCleanupTest`) nhưng chưa test trên điện thoại thật trong chế độ airplane.
6. **Không có CI/CD**: Toàn bộ kiểm thử chạy local. Rủi ro regeression nếu không có GitHub Actions/GitLab CI.
7. **Chưa merge nhánh**: Branch `giai-doan-6-hoan-thien` chưa merge vào `main`/`master`. Cần merge local sau khi người thật kiểm tra.
