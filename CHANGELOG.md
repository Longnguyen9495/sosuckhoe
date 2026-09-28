# Changelog

## 2026-09-28 — Chống trùng dữ liệu, bài tập có video, giao diện điện thoại

### Thêm
- Trang chủ làm lại (`views/landing.js`, `css/landing.css`): chuyển động thuần CSS (mở màn, hiện dần khi cuộn, tắt khi bật giảm chuyển động), phần "Tham quan Sổ" giới thiệu 5 màn chính bằng tab CSS, thẻ "Còn nữa" (chia sẻ hồ sơ, chữ to, mất mạng, giao diện tối).
- Ảnh xem trước khi chia sẻ link (`public/og-image.png`, thẻ Open Graph / Twitter trong `app.blade.php`); cách tạo lại ảnh: `docs/og-image/og-image.mjs`.
- Chống trùng (`App\Services\Dedup\DuplicateMatcher`, chuẩn hoá bỏ dấu, "typ" / "típ", 10,16 = 10.16, giữ nguyên dương / âm tính):
  - Ảnh tải lại y hệt (cột mới `documents.content_hash`, SHA-256 sau khi bỏ metadata): trả phiếu đã có, không lưu, không gọi AI.
  - Bản chụp lại cùng phiếu (cùng loại, ngày, tiêu đề, khoa, nội dung ≥ 80%): gắn `duplicate_of_id`, không nhập lại xét nghiệm / chẩn đoán.
  - Xét nghiệm cùng tên + ngày + giá trị bỏ qua; khác giá trị thì giữ cả hai, màn Hồ sơ nhắc "Có 2 kết quả khác nhau".
  - Thuốc: `pending-medications` đánh dấu `duplicate` (đang dùng / trùng phiếu trước / bản chụp lại) → bỏ chọn sẵn; `POST /prescriptions` trả 409 `DUPLICATE_MEDICATION` nếu thuốc đang có trong đơn khác, trừ khi gửi `allow_duplicates`. Nhiều dòng cùng thuốc trong MỘT đơn (các mũi insulin) vẫn hợp lệ.
  - Lệnh `php artisan health:dedupe [--apply] [--patient=]` dọn dữ liệu trùng đã lưu (mặc định chỉ liệt kê).
- Bài tập gợi ý có video (thư viện `ExerciseLibrary`, AI chỉ chọn theo id); ô ghi đường huyết một con số; cờ `FEATURES` ẩn phần bác sĩ / người chăm sóc.
- Thực đơn + bài tập đổi mỗi ngày: bảng `daily_plans`, `DailyPlanService`, lệnh `careplan:daily` (lịch 04:30, cần cron `schedule:run` — xem docs/DEPLOY.md). AI lên thực đơn đúng ngày, tránh lặp món 7 ngày gần nhất; lỗi thì dùng thực đơn 7 ngày (`diet.weekly_menu`). Bài tập luân phiên 2–3 bài / ngày, không cần AI.
- Màn Hôm nay bố cục mới: tóm tắt (đường huyết · bài tập · bữa tới), đường huyết, thực đơn hôm nay (bữa sắp tới nổi bật, mẹo ăn uống), bài tập hôm nay (đánh dấu đã tập), dấu hiệu bất thường, ghi chú. Bỏ "Lịch trong ngày" và "Uống nước" khỏi màn này.
- Icon Lucide (`ui/icons.js`, ISC) thay emoji trên các màn người bệnh.
- Link chia sẻ hồ sơ chỉ xem (màn Thông tin cá nhân → Chia sẻ hồ sơ): hạn dùng, PIN tuỳ chọn (chặn PIN dễ đoán, khoá 15 phút sau 5 lần sai), thu hồi, mã QR, nhật ký lượt xem; trang `#/s/{mã}` cho dược sĩ / bác sĩ xem thuốc đang dùng, bệnh nền, chỉ số bất thường, đường huyết 30 ngày, in / lưu PDF. Xem ADR-015.

### Sửa
- Chẩn đoán có ngoặc ở cuối ("Viêm gan B-HBsAg (+)") bị lưu lặp lại mỗi lần tải ảnh do phép so sánh cắt mất "(+)".
- Điện thoại: ô ngày / giờ và thẻ chẩn đoán dài tràn ngang; iOS tự phóng to khi chạm ô nhập (< 16px); vùng an toàn tai thỏ / thanh vuốt; bảng trượt không đóng khi đổi màn.

## 2026-09-27 — Sổ Sức Khỏe (sosuckhoe): đăng ký nhanh, AI đọc ảnh, chế độ ăn

### Thêm
- Trang giới thiệu (`#/`) phong cách Snapask, nút "Thông tin cá nhân".
- Đăng ký nhanh `POST /auth/register` (tên + SĐT + ngày sinh, không OTP); mật khẩu mặc định = tên không dấu viết liền + 4 số cuối SĐT. Đăng nhập `POST /auth/login` (SĐT + mật khẩu), `GET /auth/me`, `POST /auth/password`. Màn `#/start`, `#/login`, `#/me`.
- AI thật (API tương thích OpenAI, `AI_DRIVER=openai`, model `gpt-5.6-sol`): `OpenAiCompatibleClient`, `MedicalPrompts`.
- Tải nhiều ảnh (`#/upload`) → `DocumentIngestService`: bỏ EXIF, mã hoá, AI đọc, tách xét nghiệm / chẩn đoán (bệnh nền) / hẹn tái khám; ảnh thẻ CCCD / BHYT bị từ chối.
- `SensitiveDataScrubber`: không lưu số CCCD / CMND / mã BHYT / BHXH / hộ chiếu vào CSDL.
- Kiểm tra thuốc AI đọc (`#/review`, `GET /pending-medications`) → tạo lịch; gắn đơn với phiếu nguồn (`documents.prescription_id`).
- Kế hoạch chăm sóc (`care_plans`, `GET/POST /care-plan`): chế độ ăn, thực đơn mẫu, sinh hoạt, theo dõi, dấu hiệu nguy hiểm, kèm ghi chú tham khảo; gợi ý món hiện ở mốc bữa ăn trong lịch ngày.
- Xoá phiếu (`DELETE /documents/{id}`) xoá file mã hoá; xoá tài khoản xoá sổ sở hữu riêng + ảnh.
- Khoá mã hoá ảnh riêng `DOCUMENT_ENCRYPTION_KEY`.

### Thay đổi
- Đổi tên dự án thành **Sổ Sức Khỏe / sosuckhoe**; Laravel chuyển ra thư mục gốc (trước ở `backend/`), tài liệu vào `docs/`, bản mẫu vào `archive/prototype/`. DocumentRoot mới: `public/`.
- Ảnh phiếu thật của bản mẫu không còn nằm trong git.

## 2026-09-26 — Hoàn tất Giai đoạn 6 — Hoàn thiện (Bảo mật + Giao diện đầy đủ)

### Thêm
- Cổng bác sĩ (`#/doctor`): danh sách bệnh nhân sắp theo cờ đỏ + % tuân thủ, click vào bệnh nhân chuyển sang màn "Hôm nay".
- Màn AI đọc đơn (`#/rx/scan`): upload ảnh (camera), tạo draft OCR, hiển thị suggestions với checkbox, xác nhận từng dòng hoặc toàn bộ.
- Gia đình xem notes của bác sĩ trong màn Hồ sơ (`records-notes`) và xem câu trả lời trong màn Hỏi bác sĩ (`renderAsk`) với 💬 + timestamp.
- `MissingApisTest::test_ai_prescription_draft_caregiver_can_create_and_list`: chứng minh caregiver (không cần doctor.2fa) có thể tạo draft.
- Giao diện gia đình hoàn chỉnh: Lịch tháng (`#/calendar`), Phác đồ (`#/plan`), Hồ sơ (`#/records`), Hỏi bác sĩ (`#/ask`), Cài đặt (`#/settings`), Ngưỡng (`#/settings/thresholds`), Ghi chỉ số nhanh (`#/quick-reading`), Onboarding 7 bước (`#/onboarding/:step`), Nhập đơn (`#/rx/new`).

### Thay đổi
- `README.md`: cập nhật tiêu đề Giai đoạn 6, thêm 3 tài khoản demo, danh sách biến `.env` để bật dịch vụ thật.
- `TODO-NGUOI-THAT.md`: bỏ dòng sai (Giai đoạn 3 chưa làm, PHP 8.5), thêm mục kiểm thử bác sĩ và AI.
- `PLAN.md`: tick 9.3–9.5 với nhãn "(API ✓, Giao diện ✓)".
- `REVIEW.md`: tick toàn bộ mục 5 trừ M3/L1–L3 (cần kiểm thử thủ công).
- `DECISIONS.md`: thêm ADR-012 — mở `ai-prescription-drafts` cho caregiver/owner.
- `resources/views/welcome.blade.php` đã xóa; route `/` vẫn trỏ `app.blade.php`.

### Bảo mật và an toàn
- AI prescription draft routes chuyển từ nhóm `doctor.2fa` sang `auth:sanctum` + `tenant` + `audit.patient`; gia đình/caregiver có thể tạo draft mà không cần 2FA bác sĩ.
- Notes GET `/patients/{patient}/notes` chuyển sang nhóm chung để gia đình xem; chỉ POST tạo note vẫn nằm trong `doctor.2fa`.
- `OtpFakeDriverEnvironmentTest`: chứng minh OTP fake chỉ hoạt động khi `APP_ENV != production`.
- `DocumentEncryptionTest`: mã hoá ảnh phiếu, tải qua Policy.
- `TenantIsolationSweepTest`: tự quét toàn bộ route `{patient}` và id con.
- `OfflineQueueCleanupTest`: xoá hàng đợi offline khi đăng xuất.

### Kiểm chứng
- MySQL/MariaDB XAMPP: `migrate:fresh --seed` thành công.
- Full suite: 91 tests, 385 assertions; 0 failure.

## 2026-09-26 — Hoàn tất Giai đoạn 5 — AI đọc đơn, Zalo/SMS giả lập và bộ đánh giá

### Thêm
- `AiOcrClient` interface + `FakeAiOcrClient` driver giả; trả về `suggestions` từ ảnh base64 để kiểm thử.
- `AiPrescriptionDraftController`: tạo draft từ ảnh, liệt kê, xác nhận dòng và ghép tên thuốc với danh mục `drugs`.
- `matchDrugs` chỉ ghép tên + hàm lượng, không tính/đổi liều; trả `match_score` để người dùng xác nhận.
- `AiBenchmarkEvaluator` + `RunAiBenchmark` command: đánh giá OCR trên 20 đơn mẫu JSON (3 đơn từ ảnh bà D.); tính accuracy và in bảng kết quả.
- `ZaloZnsSender` interface + `FakeZaloZnsSender` và `SmsSender` interface + `FakeSmsSender`; ghi log `[FAKE_ZALO]` / `[FAKE_SMS]` để kiểm thử.
- `SendZaloSmsReminders` command: gửi cảnh báo đỏ và nhắc tái khám qua Zalo ZNS, fallback SMS khi thất bại.
- `AiPrescriptionDraftTest`: 4 tình huống gồm tạo draft từ ảnh, liệt kê, xác nhận dòng và ghép thuốc.

### Bảo mật và an toàn
- Không tính/đổi/gợi ý liều trong AI OCR hay `matchDrugs`.
- Mọi route AI draft nằm trong nhóm `doctor.2fa` + `audit.patient`.
- Dataset benchmark là JSON bên ngoài; không chứa CCCD/BHYT.
- Zalo/SMS fake không gọi API thật trong môi trường dev.

### Kiểm chứng
- MySQL/MariaDB XAMPP: `migrate:fresh --seed` thành công.
- Full suite: 60 tests, 254 assertions; 0 failure.

## 2026-09-26 — Hoàn tất Giai đoạn 4 — Cổng bác sĩ/phòng khám, 2FA và PDF

### Thêm
- `RequireDoctorTwoFactor` middleware: yêu cầu bác sĩ có `two_factor_confirmed_at`; caregiver không bị ảnh hưởng.
- `DoctorTwoFactorTest`: 3 tình huống gồm từ chối bác sĩ thiếu 2FA, cho phép bác sĩ đã xác nhận, và bỏ qua caregiver.
- `DoctorPatientListController`: liệt kê bệnh nhân của phòng khám, sắp xếp theo số cảnh báo đỏ giảm dần và tỷ lệ tuân thủ tăng dần.
- `DoctorNoteController`: bác sĩ/ghia đình tạo ghi chú với `note_type`; liệt kê theo thời gian.
- `DoctorQuestionController`: bác sĩ trả lời câu hỏi, cập nhật `answered_by_name` và `answered_at`.
- `DoctorThresholdConfirmController`: bác sĩ xác nhận ngưỡng, tạo bản ghi lịch sử trong `threshold_histories`.
- `DoctorMonitoringPlanController`: bác sĩ điều chỉnh `patient_routines` và `monitoring_plans`.
- `PatientReportController`: báo cáo tái khám JSON + PDF một trang (Blade), gồm điều kiện, ngưỡng, biểu đồ đường huyết và ghi chú.
- `DoctorPortalTest`: 9 tình huống gồm 2FA, liệt kê, ghi chú, trả lời, xác nhận ngưỡng, điều chỉnh kế hoạch, báo cáo JSON/PDF.

### Bảo mật và an toàn
- Mọi route cần 2FA bác sĩ có `PatientPolicy` + `TenantIsolationTest` cross-tenant 404 + `AuditPatientAccess`.
- Báo cáo PDF không chứa CCCD/BHYT; chỉ hiển thị dữ liệu đã có trong hồ sơ.
- Không tính/đổi/gợi ý liều trong bất kỳ endpoint nào.

### Kiểm chứng
- MySQL/MariaDB XAMPP: `migrate:fresh --seed` thành công.
- Full suite: 56 tests, 242 assertions; 0 failure.

## 2026-09-26 — Hoàn tất Giai đoạn 3 — Luồng tự đăng ký 7 bước và xem trước lịch

### Thêm
- `OnboardingController` + `OnboardingFlow`: luồng đăng ký 7 bước (bệnh nhân → tình trạng → thuốc → sinh hoạt → ngưỡng → câu hỏi → đồng ý).
- `OnboardingDraft` lưu trạng thái từng bước; duy nhất mỗi user cho tới khi hoàn tất.
- `complete()` tạo `Tenant`, `Patient`, `TenantMember`, `PatientAccess`, `PatientCondition`, `PrescriptionItem`, `PatientRoutine`, `PatientThreshold`, `Question` từ draft.
- `PrescriptionDraftController::preview()`: xem trước lịch từ draft trước khi lưu, dùng `PatientScheduleOrchestrator::seedMonitoringPlans`.
- `OnboardingFlowTest`: 9 tình huững gồm bắt đầu, lưu từng bước, quay lại, hoàn tất, yêu cầu tối thiểu bước 5, bước không hợp lệ, draft duy nhất và hoàn tất với thuốc.

### Bảo mật và an toàn
- Onboarding không lưu CCCD/BHYT.
- `dose_text` và `usage_rule` từ draft được giữ nguyên văn; không tính liều.
- Mọi route onboarding nằm trong `auth:sanctum`, không cần tenant (chưa có tenant khi đăng ký).

### Kiểm chứng
- MySQL/MariaDB XAMPP: `migrate:fresh --seed` thành công.
- Full suite: 47 tests, 194 assertions; 0 failure.

## 2026-09-26 — Hoàn tất Giai đoạn 2 — Lịch và theo dõi

### Thêm
- `UsageRuleParser` đọc JSON cách dùng có cấu trúc; từ chối plain text với lỗi tiếng Việt rõ ràng.
- `ScheduleGenerator` tính khung giờ từ `prescription_items` + `patient_routines`; dose_text được giữ nguyên, không tính/đổi liều.
- `PatientScheduleOrchestrator` sinh lại lịch từ ngày mai, không sửa lịch sử (đóng các `schedule_items` tương lai rồi tạo mới).
- API ngày `/patients/{p}/day/{date}` ghép schedule_items, logs, readings, events thành danh sách theo giờ.
- API ghi log, chỉ số và sự kiện qua `PatientDayController`.
- `ReadingEvaluator` đánh giá chỉ số theo `patient_thresholds`; chỉ tạo alert khi ngưỡng đỏ.
- `PatientAlertController` liệt kê và đánh dấu đã xem alert.
- `PatientChartController` trả dữ liệu đường huyết/huyết áp cho biểu đồ.
- `PatientCsvExportController` xuất CSV phía server có BOM UTF-8.
- `PushNotifier` interface + `FakePushNotifier` driver giả; ghi log `[FAKE_PUSH]` để kiểm thử.
- `SendScheduleReminders`: nhắc 30 phút trước insulin, 5 phút trước thuốc.
- `SendEventReminders`: nhắc mốc trước 3 ngày và 1 ngày.
- `SendPurchaseReminders`: nhắc mua thuốc khi tồn kho < 20%; không suy luận liều khi usage_rule thiếu.
- `PatientDayApiTest`: 6 tình huống gồm cross-tenant 404, ghi log/reading/event, lọc readings.
- `ReadingEvaluatorTest`: 5 tình huống gồm glucose tốt, glucose đỏ thấp, huyết áp đỏ cao, thiếu ngưỡng, lưu alert.
- `ScheduleGeneratorBaDTest` (TDD): 5 test, 30 assertion khớp bảng 4.1 cho 3 đơn bà D.
- Service Worker `sw.js`: hàng đợi IndexedDB cho ghi offline; drain queue khi có mạng.

### Thay đổi
- Route `patients.index` và `patients.show` giữ trong nhóm `doctor.2fa` (chỉ chặn bác sĩ chưa xác nhận 2FA); caregiver không bị ảnh hưởng.
- Các route theo dõi hàng ngày (day, logs, readings, events, chart, csv, alerts) đặt trong nhóm không cần `doctor.2fa` để caregiver truy cập.
- UI profile chuyển sang màn “Hôm nay” gọi API `/day/{date}`; thêm màn ghi chỉ số nhanh gọi API.

### Bảo mật và an toàn
- Mọi nội dung y khoa mới trong alert và cảnh báo có nhãn `[NHÁP — CẦN DUYỆT]`.
- Mọi route patient mới có `PatientPolicy` + `TenantIsolationTest` cross-tenant 404 + `AuditPatientAccess`.
- `ReadingEvaluator` chỉ dùng `patient_thresholds`; không còn ngưỡng viết cứng.
- `UsageRuleParser` không bao giờ tính, đổi hay gợi ý liều.

### Kiểm chứng
- MySQL/MariaDB XAMPP: `migrate:fresh --seed` thành công.
- Full suite: 38 tests, 176 assertions; 0 failure. PHP 8.5 deprecation từ cấu hình PDO MySQL nhưng không ảnh hưởng.

## 2026-09-26 — Hoàn tất Giai đoạn 1

### Thêm
- Danh mục thuốc idempotent gồm 12 thuốc/vật tư trong hồ sơ demo và các thuốc thường gặp cho tiểu đường, tăng huyết áp, rối loạn mỡ máu.
- Dữ liệu demo đầy đủ từ các tập `MEDS`, `EVENTS`, `CONDS`, `LABS`, `RECORDS`, `QA`: 3 đơn, 12 dòng thuốc/vật tư, 14 sự kiện, 10 tình trạng, 33 kết quả, 28 tài liệu và 16 câu hỏi.
- Frontend JavaScript thuần/PWA tối thiểu: OTP, chọn tenant, chọn bệnh nhân, hồ sơ cơ bản, dark mode và khả năng cài đặt.
- Chuỗi giao diện tiếng Việt trong file ngôn ngữ.
- Tests cho seeder đầy đủ, an toàn, idempotent và giữ ULID; tests cho frontend shell/PWA.
- README gốc hướng dẫn cài đặt và chạy bằng XAMPP.

### Thay đổi
- `DrugSeeder` lookup trước khi insert để không phụ thuộc unique index và giữ ULID khi chạy lại.
- `DemoPatientBaDSeeder` dùng identity ổn định cho mọi bảng, giữ nguyên văn `dose_text` từ prototype.
- Lệnh import log xử lý đúng bản ghi có `schedule_item_id = null` khi chạy lặp.
- Route web gốc phục vụ giao diện ứng dụng thay cho trang chào Laravel.

### Bảo mật và an toàn
- Dữ liệu demo không lưu mã bệnh nhân nguồn, CCCD, số BHYT hoặc mật khẩu.
- Mọi cảnh báo y khoa trong danh mục thuốc có nhãn `[NHÁP — CẦN DUYỆT]`.
- Không thêm chức năng tính, đổi hoặc gợi ý liều.
- Các route `{patient}` hiện có tiếp tục nằm trong tenant middleware/policy và có kiểm thử chéo tenant trả 404.

### Kiểm chứng
- Production frontend build thành công.
- SQLite `migrate:fresh --seed`, seed lần hai và full suite thành công.
- MySQL/MariaDB XAMPP 10.4.32: `migrate:fresh --seed`, seed lần hai và full suite đều thành công.
- Full suite: 23 tests, 119 assertions; PHP 8.5 báo deprecation từ cấu hình PDO MySQL của framework nhưng không có test fail.
