# Quyết định kỹ thuật

## ADR-001 — Identity ổn định cho seeder

- **Quyết định:** Seeder tìm bản ghi bằng identity nghiệp vụ trước khi insert; nếu tồn tại chỉ update dữ liệu thay đổi.
- **Lý do:** `drugs` không có unique index trên tên/hàm lượng, nên `insertOrIgnore` không bảo đảm idempotency. Cách này giữ nguyên ULID qua lần seed tiếp theo.
- **Kiểm chứng:** Seeder test chụp toàn bộ IDs và số lượng trước/sau lần chạy thứ hai.

## ADR-002 — Ánh xạ dữ liệu prototype vào schema Giai đoạn 1

- `MEDS` → `prescriptions` và `prescription_items`.
- `EVENTS` → `events`.
- `CONDS` → `patient_conditions`; liên kết template khi có mẫu phù hợp, phần còn lại lưu mô tả nguồn.
- `LABS` → `lab_results`.
- `RECORDS` → `documents`; tên ảnh dùng đường dẫn demo, phát hiện lưu trong JSON `analysis`, ảnh trùng dùng `duplicate_of_id`.
- `QA` → `questions`; chuyên khoa, bác sĩ và lịch hẹn được giữ trong tiền tố câu hỏi vì schema hiện chưa có cột nhóm.

Không thêm bảng mới chỉ để sao chép cấu trúc JavaScript của prototype.

## ADR-003 — Giảm dữ liệu nhận dạng trong demo

- Dùng tên “Bà D.” thay vì tên đầy đủ trong prototype.
- Không chuyển mã bệnh nhân nguồn, CCCD, số BHYT hoặc mật khẩu.
- Chỉ giữ năm sinh và dữ liệu cần thiết để kiểm thử luồng sản phẩm.

## ADR-004 — Không biến dữ liệu nguồn thành khuyến nghị điều trị

- `dose_text` được lưu nguyên văn từ đơn/prototype, không parse hoặc tính lại.
- Các câu có nội dung hỏi về điều chỉnh liều chỉ nằm trong `questions` với vai trò câu hỏi cho bác sĩ.
- Mô tả sự kiện chỉ nhắc tổng hợp dữ liệu và trao đổi với bác sĩ; không tự chỉnh liều.
- Cảnh báo thuốc mang nhãn `[NHÁP — CẦN DUYỆT]`.

## ADR-005 — Frontend Giai đoạn 1 và 2

- Dùng JavaScript thuần và Laravel Vite; không thêm framework UI.
- Token Sanctum lưu trong `sessionStorage` để giảm thời gian tồn tại so với `localStorage`.
- Tenant được chọn từ `/api/v1/tenants`, gửi bằng `X-Tenant-ID`; bệnh nhân luôn tải từ `/api/v1/patients`, không hardcode.
- PWA Giai đoạn 2 đã thêm IndexedDB offline write queue: ghi request POST/PUT/PATCH vào hàng đợi khi mất mạng; drain queue khi có mạng.
- Giao diện “Hôm nay” gọi API `/api/v1/patients/{p}/day/{date}`; ghi chỉ số gọi API `/readings` và `/logs`.

## ADR-006 — Phạm vi kiểm thử

- Tất cả route có `{patient}` hiện tại gồm xem bệnh nhân, tạo lời mời, day, logs, readings, events, chart, csv, alerts đều có tình huống cross-tenant trả 404.
- Full suite được kiểm chứng cả bằng SQLite độc lập và MySQL/MariaDB XAMPP 10.4.32.
- Gate MySQL gồm `migrate:fresh --seed`, seed lần hai và full tests; cả ba bước đã thành công trong phiên hoàn tất Giai đoạn 2 (38 tests, 176 assertions).

## ADR-007 — Web Push và Reminders

- `PushNotifier` là interface; `FakePushNotifier` ghi log `[FAKE_PUSH]` để kiểm thử mà không cần cấu hình thật.
- Reminder commands (Schedule, Event, Purchase) chạy qua scheduler hoặc cron; không gửi thật trong môi trường dev.
- Tính ngày hết thuốc từ `total_units` chia cho liều hàng ngày (nếu có usage_rule JSON); nếu thiếu thì không suy luận liều.

## ADR-008 — Ngưỡng đánh giá chỉ số

- `ReadingEvaluator` chỉ dùng `patient_thresholds`; không còn ngưỡng viết cứng trong code.
- Kết quả đánh giá trả về cấp độ good / yellow / red / unknown; chỉ tạo alert trong database khi cấp độ là red.
- Nội dung alert tự động có nhãn `[NHÁP — CẦN DUYỆT]`.

## ADR-009 — Cổng bác sĩ và 2FA

- `RequireDoctorTwoFactor` middleware kiểm tra `two_factor_confirmed_at` trên user; chỉ áp dụng khi user có role `doctor` trong tenant hiện tại.
- Caregiver/owner không bị ảnh hưởng; họ truy cập route theo dõi hàng ngày bình thường.
- Mọi route quản lý lâm sàng (ghi chú, trả lời câu hỏi, xác nhận ngưỡng, điều chỉnh kế hoạch, báo cáo, AI đọc đơn) nằm trong nhóm `doctor.2fa`.
- `AuditPatientAccess` ghi lại mọi lần truy cập bệnh nhân kể cả bác sĩ.

## ADR-010 — AI đọc đơn và ghép thuốc

- `AiOcrClient` là interface; `FakeAiOcrClient` trả `suggestions` giả lập từ ảnh base64 để kiểm thử.
- `AiPrescriptionDraftController::store()` tạo `Document` (ảnh gốc) và `AiPrescriptionDraft` (kết quả OCR); `dose_text` từ ảnh được lưu nguyên văn.
- `matchDrugs()` chỉ so khớp `drug_name` và `strength` với danh mục `drugs`; trả `matched_drug_id` + `match_score` (100/0). Không tính/đổi liều.
- Người dùng phải xác nhận từng dòng (`confirm`) trước khi lưu thành đơn thuốc thật.
- Dataset benchmark là JSON ngoài (`storage/app/ai_benchmark/dataset.json`) với 20 đơn mẫu; không chứa CCCD/BHYT.

## ADR-011 — Zalo ZNS và SMS fallback

- `ZaloZnsSender` và `SmsSender` là interface; `FakeZaloZnsSender` / `FakeSmsSender` ghi log `[FAKE_ZALO]` / `[FAKE_SMS]` trong dev.
- `SendZaloSmsReminders` command gửi cảnh báo đỏ và nhắc tái khám qua Zalo ZNS; nếu thất bại thì fallback SMS.
- Không gửi thật trong môi trường dev do driver mặc định là `fake`.
- Template ZNS và SMS chỉ chứa nội dung đã được phê duyệt; không tự sinh nội dung y khoa.

## ADR-012 — Mở AI đọc đơn cho caregiver/owner

- **Quyết định:** Route `ai-prescription-drafts` (tạo draft, liệt kê, xác nhận, ghép thuốc) được mở cho caregiver/owner thông qua middleware `auth:sanctum` + `tenant` + `audit.patient`, không yêu cầu `doctor.2fa`.
- **Lý do:** Gia đình thường là người chụp ảnh đơn thuốc và muốn kiểm tra trước khi đưa cho bác sĩ. Không hợp lý khi ép họ phải đăng nhập 2FA bác sĩ để sử dụng tính năng này.
- **Kiểm chứng:** `MissingApisTest::test_ai_prescription_draft_caregiver_can_create_and_list` pass khi caregiver tạo draft thành công.
- **Giới hạn:** `matchDrugs` chỉ ghép tên + hàm lượng, không tính/đổi liều. Người dùng phải xác nhận từng dòng (`confirm`) trước khi lưu thành đơn thuốc thật. Tạo đơn thuốc từ draft vẫn nằm trong nhóm `doctor.2fa` nếu cần.

## ADR-014 — Chống trùng dữ liệu y khoa

- **Bối cảnh:** Người dùng hay chụp một phiếu nhiều lần; AI đọc mỗi ảnh hơi khác nhau (ETHESO / ETIHESO, KCBTƯC / KCBTVC, chẩn đoán gộp hoặc tách dòng). Dữ liệu thật trước khi sửa: 7 xét nghiệm lưu 2 lần, "Viêm gan B-HBsAg (+)" 6 lần, 5 thuốc nằm trong 2 đơn cùng lúc (lịch nhắc uống 2 lần).
- **Quyết định:**
  - Ảnh y hệt nhận ra bằng SHA-256 → không lưu, không gọi AI. Bản chụp lại (cùng loại, ngày, tiêu đề, khoa; thuốc / xét nghiệm / chẩn đoán trùng ≥ 80%) → `duplicate_of_id`, không nhập lại dữ liệu. Không tự xoá ảnh.
  - Thuốc trùng: cảnh báo và bỏ chọn sẵn ở màn Kiểm tra thuốc; máy chủ chặn (409) trừ khi người dùng xác nhận. Không tự gộp vì bác sĩ có thể kê thêm cùng thuốc.
  - So tên thuốc: cùng hàm lượng, cho lệch 1–2 ký tự (từ ≥ 6 / ≥ 7 chữ) nhưng độ dài hai từ không chênh quá 1 — để Prednison / Prednisolon, Losartan / Valsartan, Metoprolol / Metformin luôn là thuốc khác.
  - Dương tính "(+)" và âm tính "(-)" không bao giờ coi là trùng.
- **Dọn dữ liệu cũ:** `php artisan health:dedupe` (chạy thử) rồi `--apply`. Giữ bản ghi đầu tiên; đơn không còn thuốc thì xoá, phiếu gắn với đơn đó chuyển sang đơn còn giữ.
- **Kiểm chứng:** `DuplicateDetectionTest` (7 tình huống); chạy trên bản sao dữ liệu thật khớp đúng các cặp ảnh trùng ghi trong PLAN.md mục ảnh gốc.

## ADR-015 — Link chia sẻ hồ sơ chỉ xem

- **Bối cảnh:** Người bệnh cần đưa hồ sơ cho dược sĩ, bác sĩ, người thân xem nhanh (thuốc đang dùng, bệnh nền, xét nghiệm, đường huyết) mà người xem không phải tạo tài khoản. Dữ liệu sức khỏe là dữ liệu cá nhân nhạy cảm (Nghị định 13/2023).
- **Quyết định:**
  - Người bệnh tự tạo link ở màn Thông tin cá nhân; phải đánh dấu đồng ý (`consented_at`). Hạn 24 giờ / 7 ngày / 30 ngày, thu hồi bất cứ lúc nào; tối đa 10 link đang mở.
  - Link dạng `/#/s/{mã 40 ký tự}` — phần sau `#` không gửi lên máy chủ nên mã không nằm trong log web. Máy chủ chỉ lưu SHA-256 của mã.
  - PIN 4–6 số do người bệnh đặt (app gợi ý số ngẫu nhiên), lưu bcrypt, chỉ hiện một lần. Chặn PIN dễ đoán (số lặp, dãy liên tiếp, cặp lặp, năm / ngày sinh, số cuối điện thoại). Sai 5 lần khoá 15 phút; người bệnh thấy cảnh báo. Giới hạn gọi API theo IP.
  - Trang chia sẻ không có SĐT, CCCD, BHYT; họ tên mặc định chỉ chữ cái đầu. Ảnh phiếu chỉ khi người bệnh bật, tải qua phiên xem 30 phút (header `X-Share-Session`).
  - Link sai / hết hạn / đã thu hồi trả cùng một thông báo 404. Phản hồi `no-store`, `noindex`, `no-referrer`. Mỗi lượt mở ghi thời điểm, IP đã che phần cuối, loại thiết bị.
  - Trang công khai gọi API bằng `fetch` riêng (không gửi token của người đang dùng máy; lỗi 401 do sai PIN không làm đăng xuất).
- **Kiểm chứng:** `ShareLinkTest` (6 tình huống: không lộ định danh, PIN yếu, khoá sau 5 lần sai, thu hồi / hết hạn, ảnh phiếu theo quyền, bắt buộc đồng ý); `TenantIsolationSweepTest` quét cả route `share-links`.
