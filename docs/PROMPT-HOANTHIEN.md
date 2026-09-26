# Prompt hoàn thiện (Giai đoạn 6)

Cách gọi: mở Claude Code tại `C:\xampp\htdocs\suckhoe` và gõ:

```
Đọc @PROMPT-HOANTHIEN.md và thực hiện toàn bộ phần "PROMPT" trong file, bắt đầu từ Bước 0.
```

Nếu phiên bị ngắt, gõ:

```
Đọc @PROMPT-HOANTHIEN.md, @REVIEW.md và @CHANGELOG.md. Tiếp tục phần "PROMPT" từ mục đầu tiên chưa đánh dấu [x] trong checklist của REVIEW.md mục 5.
```

---

## PROMPT

```
Bạn là lập trình viên chính của dự án "Sổ theo dõi sức khỏe" trong thư mục này. Lượt trước đã làm backend giai đoạn 1–5 nhưng giao diện còn thiếu nhiều và có lỗi bảo mật. Nhiệm vụ lượt này: HOÀN THIỆN TOÀN BỘ để gia đình bà D. dùng thật được và bác sĩ xem được. Làm một lượt, không dừng chờ duyệt, trừ các trường hợp được phép dừng ở cuối prompt.

==================================================================
A. ĐỌC TRƯỚC
==================================================================
1. REVIEW.md — kết quả rà soát: tiến độ thật, lỗi C1–C4, H1–H3, M1–M3, L1–L3 kèm file:dòng, checklist mục 5. Đây là danh sách việc chính.
2. PLAN.md — đặc tả sản phẩm. Đặc biệt: mục 4 (lịch bà D.), 5.3 (luồng đăng ký 7 bước), 5.4 (bộ sinh lịch), 5.5 (mẫu bệnh + ngưỡng), 6 (giao diện Snapask, 6.1 màn gia đình, 6.2 màn bác sĩ, 6.3 token), 7.4 (API), 8 (pháp lý).
3. prototype/index.html — chuẩn về bố cục và cảm giác giao diện: đầu trang tím bo tròn, thẻ trắng bo 20 px, dải ngày, dòng thời gian có nút tích, bảng trượt ghi chỉ số, thanh tab dưới đáy + nút + nổi giữa, bong bóng chat hỏi bác sĩ, biểu đồ SVG có tooltip. Chép cách làm, KHÔNG chép dữ liệu cứng.
4. DECISIONS.md, CHANGELOG.md, TODO-NGUOI-THAT.md, README.md — để biết lượt trước đã quyết định gì. Lưu ý REVIEW.md đã chỉ ra một số chỗ trong CHANGELOG/README không đúng thực tế.
5. backend/routes/api.php, backend/app, backend/tests, backend/resources/js/app.js — code hiện tại.

==================================================================
B. QUY TẮC BẮT BUỘC (không ngoại lệ)
==================================================================
1. An toàn y khoa: không viết code tính, đổi hay gợi ý liều thuốc. Bộ sinh lịch chỉ quyết định GIỜ. Không hiểu cách dùng thì bắt người dùng chọn giờ.
2. Tách dữ liệu khách hàng: tenant_id + global scope; mọi route {patient} qua PatientPolicy và scopeBindings; id ULID; mọi route bệnh nhân (kể cả route mới thêm lượt này) phải tự nằm trong test tách dữ liệu.
3. Không hardcode dữ liệu bệnh nhân trong code; dữ liệu bà D. chỉ ở seeder.
4. Không lưu CCCD, số BHYT, mật khẩu tra cứu; không ghi dữ liệu sức khỏe ra log; không cache dữ liệu API trong service worker.
5. Ngưỡng luôn từ patient_thresholds; ngưỡng chưa xác nhận hiện nhãn "Mặc định — chưa được bác sĩ xác nhận".
6. Giao diện theo PLAN.md mục 6: phong cách Snapask, token mục 6.3, font Be Vietnam Pro, vùng bấm ≥ 44 px, chế độ tối, không cuộn ngang ở 360 px. Chữ tiếng Việt có dấu, đặt trong file ngôn ngữ (vi.json).
7. TRUNG THỰC: chỉ đánh dấu [x] khi đã có code + test + (với giao diện) đã mở bằng trình duyệt và chụp màn hình kiểm tra. Mục chưa xong hoặc chưa kiểm chứng được thì ghi rõ trong báo cáo. Không ghi "đã xong", "đã có test" khi chưa có. Số test trong báo cáo phải là số thật từ lần chạy cuối.

==================================================================
C. BƯỚC 0 — ĐO HIỆN TRẠNG
==================================================================
- Kiểm tra: PHP (C:\xampp\php\php.exe), Composer, Node/npm, MariaDB XAMPP đang chạy, Chrome (C:\Program Files\Google\Chrome\Application\chrome.exe) để chụp màn hình headless.
- Chạy toàn bộ test trên SQLite (DB_CONNECTION=sqlite DB_DATABASE=:memory:) và trên MariaDB (database suckhoe_test riêng, KHÔNG dùng database suckhoe đang có dữ liệu). Ghi kết quả vào CHANGELOG.md mục "Giai đoạn 6 — hiện trạng ban đầu".
- Sao lưu database suckhoe (mysqldump) và thư mục backend/storage vào backup/ trước khi sửa migration.
- Tạo nhánh git: giai-doan-6.

==================================================================
D. PHẦN 1 — BẢO MẬT (làm trước, mỗi mục kèm test chứng minh)
==================================================================
D1 (C1) OTP giả:
- Bỏ mặc định 'fake' trong config/services.php. Driver 'fake' chỉ hợp lệ khi app()->environment('local','testing'); môi trường khác mà cấu hình fake thì ném lỗi khi khởi động (service provider).
- Test: environment production + driver fake → ứng dụng từ chối; local → chạy.

D2 (C2) 2FA bác sĩ bằng TOTP:
- API: POST /api/v1/auth/2fa/setup (trả secret + otpauth URI để hiện QR), POST /auth/2fa/confirm (xác nhận mã đầu tiên, set two_factor_confirmed_at, trả mã khôi phục một lần), POST /auth/2fa/challenge (sau đăng nhập OTP, nhập mã 6 số), POST /auth/2fa/recovery.
- two_factor_secret lưu mã hoá (cast 'encrypted'). Mã khôi phục lưu băm.
- Token Sanctum sau OTP chỉ có ability 'basic'; sau challenge đúng mới có '2fa-passed'. RequireDoctorTwoFactor kiểm tra ability của token hiện tại, không chỉ cột trong users.
- Giới hạn thử sai (throttle) cho challenge.
- Test: bác sĩ chưa setup → 403 kèm mã lỗi 'two_factor_setup_required'; đã setup nhưng token chưa qua challenge → 403 'two_factor_challenge_required'; qua challenge → 200; caregiver không bị yêu cầu.

D3 (C3) Tách dữ liệu:
- Bọc mọi nhóm route bệnh nhân bằng ->scopeBindings(); model con (Threshold, Question, Draft, MonitoringPlan, Prescription, Alert, Document, Note, LabResult…) phải resolve qua quan hệ của Patient.
- Viết TenantIsolationSweepTest: lấy mọi route có tham số {patient} từ Route::getRoutes(); với mỗi route và mỗi method, gọi bằng người dùng tài khoản A tới (1) bệnh nhân tài khoản B, (2) bệnh nhân A nhưng id con thuộc bệnh nhân B, (3) bệnh nhân cùng tài khoản nhưng người dùng không có patient_access. Tất cả phải 404 (hoặc 403 cho trường hợp 3 — chọn một và ghi vào DECISIONS.md). Route mới sau này tự được quét.
- Thêm test: bác sĩ chỉ thấy bệnh nhân được giao trong patient_access ở /clinic/patients (L3).
- Sửa CHANGELOG mục Giai đoạn 4 cho đúng.

D4 (H2) Ảnh phiếu:
- Lưu nội dung đã mã hoá (Crypt::encrypt hoặc disk mã hoá), tên file ngẫu nhiên (ULID), tên gốc lưu mã hoá trong DB. Không bao giờ nằm trong public/.
- API GET /patients/{p}/documents (danh sách) và GET /patients/{p}/documents/{d}/file (giải mã, trả ảnh, qua Policy, header no-store).
- Seeder: ảnh demo bà D. (prototype/assets/img) được nhập qua cùng cơ chế mã hoá.
- Test: file trên disk không đọc được như ảnh; người khác tài khoản 404; tải đúng người 200.

D5 (M2) Hàng đợi offline:
- Mỗi mục queue gắn user_id + patient_id + tenant_id; khi gửi, bỏ mục không khớp phiên hiện tại.
- Đăng xuất: xoá toàn bộ IndexedDB queue và sessionStorage.
- Không lưu token trong queue; lấy token hiện tại lúc gửi.

D6 (H3) Test đỏ:
- log_date kiểu date + cast 'date:Y-m-d' (kiểm tra các cột ngày khác tương tự: event_date, due_date, document_date…).
- Toàn bộ test xanh trên SQLite VÀ MariaDB.

Commit: "Giai đoạn 6.1: sửa bảo mật C1 C2 C3 H2 M2 H3".

==================================================================
E. PHẦN 2 — CHẠY ĐƯỢC THEO README (H1)
==================================================================
- Blade sinh <meta name="app-base" content="{{ rtrim(config('app.url'),'/') }}">; JS dùng base này cho mọi fetch, cho đăng ký service worker (scope đúng thư mục), cho manifest start_url.
- sw.js: không bao giờ cache URL chứa '/api/'; chỉ cache file tĩnh của shell (danh sách cố định có phiên bản). Đổi phiên bản cache mỗi lần build.
- Kiểm tra thật: Apache XAMPP bật, mở http://localhost/suckhoe/backend/public → đăng nhập được, tải được bệnh nhân. Chụp màn hình.
- README thêm cách tuỳ chọn tạo virtual host suckhoe.test trỏ vào backend/public.

==================================================================
F. PHẦN 3 — BỔ SUNG BACKEND CÒN THIẾU CHO GIAO DIỆN
==================================================================
Trước khi làm giao diện, bảo đảm có đủ API (thêm nếu thiếu, mỗi API có test + nằm trong sweep test):
- (M3) questions: thêm cột specialty, doctor_name, due_event_id; seeder bà D. tách dữ liệu ra cột thay vì ghép vào nội dung. API lọc theo specialty.
- GET /patients/{p}/overview: thông tin hồ sơ + danh sách bệnh (patient_conditions) + chip tóm tắt, dùng cho màn Hồ sơ và đầu trang.
- GET /patients/{p}/prescriptions (danh sách đơn đang dùng/đã đóng, kèm dòng thuốc, số lượng kê/đã mua, ngày dự kiến hết, số ngày còn lại) — ngày hết tính từ số lượng đã mua và lịch đã sinh, KHÔNG suy luận liều.
- PATCH /patients/{p}/prescription-items/{item} để cập nhật số lượng đã mua (khi mua thêm).
- GET /patients/{p}/calendar?month=YYYY-MM: mỗi ngày trả % hoàn thành + danh sách mốc, cho màn Lịch.
- GET /patients/{p}/monitoring-plans: giai đoạn đo hiện tại và các giai đoạn.
- GET /content/articles?condition=…: bài hướng dẫn theo mẫu bệnh (hạ đường huyết 15-15, tiêm insulin, ăn uống, dấu hiệu nguy hiểm) — nội dung lấy từ PLAN.md mục 3 và prototype, gắn nhãn [NHÁP — CẦN DUYỆT].
- Members: GET /members, PATCH vai trò, DELETE; GET /invitations đang chờ, thu hồi lời mời.
- Onboarding: bảo đảm bước 2 (consent) là bắt buộc trước khi tạo bệnh nhân; lưu nháp từng bước; quay lại bước trước.
- Settings: cỡ chữ (lưu theo user), bật/tắt thông báo theo loại, POST /me/export trả file JSON đầy đủ dữ liệu của người dùng, POST /me/delete tạo yêu cầu xoá (không xoá ngay, ghi audit).
- Push: POST /push/subscribe, DELETE /push/subscribe.

==================================================================
G. PHẦN 4 — KIẾN TRÚC GIAO DIỆN
==================================================================
Tách backend/resources/js/app.js thành module (vẫn JS thuần + Vite; được dùng Vue 3 nếu thấy cần, ghi lý do vào DECISIONS.md):
- js/core/api.js (base URL, token, X-Tenant-ID, xử lý 401/403 2FA, lỗi mạng → queue), store.js (state: user, tenant, patient hiện tại, ngày chọn), router.js (hash router: #/today, #/calendar, #/plan, #/records, #/ask, #/settings, #/onboarding/:step, #/rx/new, #/doctor…), i18n.js + i18n/vi.json, format.js (ngày kiểu Việt Nam, số thập phân dấu phẩy).
- js/ui/: header.js (đầu trang tím + nút chọn bệnh nhân + nút chat + nút sáng/tối), tabbar.js (5 ô: Hôm nay, Lịch, [+], Phác đồ, Hồ sơ), sheet.js (bảng trượt), toast.js, chart.js (SVG line chart có vùng mục tiêu, tooltip khi di chuột/chạm, đường 2px, điểm ≥ 8px, legend khi ≥ 2 đường), ring.js, empty.js, confirm.js.
- js/views/: một file cho mỗi màn ở phần H và I.
- css/: tokens.css (biến mục 6.3, sáng + tối, :root[data-theme]), base.css, components.css. Tái sử dụng CSS của prototype/index.html làm điểm xuất phát.
- Mọi màn có trạng thái: đang tải (skeleton), rỗng, lỗi (có nút thử lại), mất mạng (banner "Đang ngoại tuyến — sẽ đồng bộ khi có mạng").
- Hỗ trợ cỡ chữ lớn (html font-size theo cài đặt), bàn phím số cho ô nhập chỉ số (inputmode decimal, chấp nhận dấu phẩy).

==================================================================
H. PHẦN 5 — MÀN HÌNH GIA ĐÌNH (PLAN.md 6.1) — mỗi màn: code + test API liên quan + chụp màn hình 360 px sáng/tối
==================================================================
H1. Đăng nhập: số điện thoại → OTP → (nếu bác sĩ) 2FA → chọn tài khoản nếu có nhiều → vào Hôm nay. Ở môi trường local hiện dòng nhỏ "Mã thử: 123456".
H2. Chọn bệnh nhân (chạm ảnh đại diện): danh sách thẻ bệnh nhân với % hôm nay + số cờ đỏ, nút "+ Thêm bệnh nhân" (mở onboarding từ bước 3).
H3. Hôm nay: như prototype — vòng %, 3 ô chỉ số (ĐH lúc đói, HA sáng, nước), dải ngày cuộn ngang có chấm mốc, thẻ cảnh báo đỏ + mốc hôm nay + mốc sắp tới 3 ngày, dòng thời gian Sáng/Trưa/Chiều/Tối với nút tích (thuốc, insulin, vận động, bôi), ô nhập tại chỗ cho lượt đo đường huyết/huyết áp có đánh giá màu + chữ, đếm cốc nước, danh sách dấu hiệu bất thường (tích → hiện hướng dẫn xử trí), ghi chú ngày.
H4. Nút + (bảng trượt): chọn ngày, thời điểm đo (gợi ý lượt cần đo có ★), đường huyết, huyết áp tâm thu/tâm trương, mạch. Kết quả đỏ → hiện ngay hướng dẫn 15-15 hoặc gọi 115.
H5. Lịch: lịch tháng (T2 đầu tuần) có chấm màu theo loại mốc + thanh % mỗi ngày, chạm ngày → mở Hôm nay của ngày đó; thẻ giai đoạn đo (giai đoạn hiện tại nổi bật); biểu đồ ĐH lúc đói 30 ngày (vùng mục tiêu theo patient_thresholds) và HA sáng (tâm thu, tâm trương, đường tham chiếu); danh sách mốc (đánh dấu đã làm); nút xuất CSV.
H6. Phác đồ: khung giờ một ngày (từ lịch đã sinh); thẻ theo từng bệnh có viền màu theo mức ưu tiên, chỉ số, mục tiêu; thẻ thuốc có thanh tồn kho, "còn N ngày", ngày hết, nút "Đã mua thêm"; nút "Nhập đơn mới" và "Kết thúc đơn"; bài hướng dẫn: hạ đường huyết 15-15, tiêm insulin, ăn uống.
H7. Nhập đơn (#/rx/new): chọn ngày kê + bác sĩ; thêm dòng thuốc: ô tìm thuốc (không dấu), chọn cách dùng bằng nút (Sáng/Trưa/Tối/Trước ngủ × Trước ăn 1h / Trước ăn 5 phút / Sau ăn / Cùng giờ cố định / Khi cần), số lượng mỗi lần + đơn vị (nhập nguyên văn từ đơn), số lượng kê, số lượng đã mua, dùng lâu dài; nếu không có trong danh mục → nhập tay. Nút "Xem trước lịch" gọi /prescriptions/preview, hiển thị lịch một ngày, cho sửa giờ từng dòng. Lưu → lịch cập nhật từ ngày bắt đầu. Kết thúc đơn cũ: hỏi thuốc nào chuyển sang đơn mới.
H8. Hồ sơ: chip chẩn đoán; bộ lọc loại phiếu; thẻ ảnh (thumbnail tải qua API có Policy, chạm để phóng to) + phân tích; tải ảnh mới (nhắc che CCCD/BHYT, chọn loại phiếu, ngày, khoa); bảng xét nghiệm có cờ Cao/Thấp/Lưu ý/Bình thường; nhập kết quả xét nghiệm bằng tay (tự gắn cờ theo khoảng tham chiếu).
H9. Hỏi bác sĩ (nút chat): nhóm theo specialty/bác sĩ, câu hỏi là bong bóng tím có ô "đã hỏi", câu trả lời của bác sĩ là bong bóng trắng, thêm câu hỏi mới; phần "Khi nào cần đi khám ngay"; danh bạ gọi nhanh (115, hotline) dạng tel:.
H10. Đăng ký 7 bước (#/onboarding/1..7) theo PLAN.md 5.3: thanh tiến độ, lưu nháp, quay lại; bước 2 đồng ý điều khoản (hiện nội dung consent_versions, nhãn NHÁP); bước 3 hồ sơ + chọn bệnh nền từ mẫu; bước 4 giờ sinh hoạt; bước 5 nhập đơn (tái sử dụng H7); bước 6 xem trước lịch + xác nhận; bước 7 mời thành viên (có "Làm sau"). Xong → Hôm nay.
H11. Ngưỡng (#/settings/thresholds): danh sách theo chỉ số/ngữ cảnh, nhãn đã/chưa xác nhận, sửa (người có quyền), lịch sử thay đổi.
H12. Cài đặt: giờ sinh hoạt (đổi → lịch sinh lại từ hôm sau, báo rõ), cỡ chữ, thông báo (bật push, chọn loại), thành viên & lời mời (vai trò theo bệnh nhân), bật 2FA (cho bác sĩ), xuất dữ liệu, yêu cầu xoá tài khoản, đăng xuất.

==================================================================
I. PHẦN 6 — MÀN BÁC SĨ VÀ AI
==================================================================
I1. Cổng bác sĩ (#/doctor), bố cục 2 cột ở ≥ 1024 px, 1 cột trên điện thoại: bảng bệnh nhân sắp theo cờ đỏ 7 ngày, % tuân thủ 7 ngày, chỉ số gần nhất, ngày tái khám; chọn bệnh nhân → hồ sơ: biểu đồ 30/90 ngày, nhật ký, đơn đang dùng, câu hỏi chờ trả lời (trả lời trong luồng chat), nút Xác nhận ngưỡng, Sửa lịch đo, Ghi nhận xét, Xuất báo cáo PDF. Bác sĩ không có nút sửa nhật ký.
I2. Duyệt AI đọc đơn (#/rx/scan): chụp/tải ảnh → gọi ai-prescription-drafts → màn 2 cột (ảnh gốc | danh sách dòng đề xuất), mỗi dòng có trạng thái khớp danh mục, cho sửa tên/cách dùng/số lượng, phải bấm "Xác nhận" từng dòng; chỉ khi mọi dòng đã xác nhận hoặc bỏ mới lưu thành đơn (đi tiếp màn xem trước lịch H7). Mở cho caregiver và owner, không chỉ bác sĩ — sửa route/middleware nếu đang khoá trong nhóm doctor.2fa, ghi vào DECISIONS.md.
I3. Gia đình thấy nhận xét của bác sĩ trong Hồ sơ và câu trả lời trong Hỏi bác sĩ.

==================================================================
J. PHẦN 7 — TÀI LIỆU (M1, L1)
==================================================================
- README.md: tiêu đề và nội dung theo hiện trạng thật; cài đặt từng bước trên XAMPP; 3 tài khoản demo: gia đình bà D. (0900000001), bác sĩ đã có 2FA (in sẵn secret TOTP demo trong seeder chỉ ở local), tài khoản trống để thử đăng ký; danh sách biến .env để bật dịch vụ thật.
- TODO-NGUOI-THAT.md: bỏ dòng sai (Giai đoạn 3 chưa làm, PHP 8.5), cập nhật theo hiện trạng.
- PLAN.md: mỗi mục 9.3–9.5 ghi rõ "(API ✓, Giao diện ✓)" và chỉ tick khi cả hai xong.
- REVIEW.md: tick mục 5 theo đúng quy tắc B7.
- CHANGELOG.md, DECISIONS.md: ghi đầy đủ lượt này.
- Xoá resources/views/welcome.blade.php và route trỏ tới nó.

==================================================================
K. PHẦN 8 — KIỂM TRA CUỐI
==================================================================
1. Toàn bộ test xanh trên SQLite và MariaDB (suckhoe_test). Ghi số test thật.
2. migrate:fresh --seed trên database suckhoe_demo, chạy seed lần 2 không trùng.
3. Kịch bản đầu-cuối: nếu cài được Playwright (npm) thì viết test e2e; nếu không, dùng Chrome headless chụp màn hình. Kịch bản:
   a. Tài khoản trống: đăng ký 7 bước → nhập 3 đơn của bà D. → lịch khớp PLAN.md mục 4.1.
   b. Gia đình bà D.: Hôm nay → tích thuốc → nhập ĐH 3,6 → thấy cảnh báo đỏ + hướng dẫn 15-15 → Lịch thấy điểm trên biểu đồ.
   c. Bác sĩ: đăng nhập OTP → 2FA → thấy bà D. đầu danh sách do có cờ đỏ → trả lời một câu hỏi → xuất PDF.
   d. Gia đình thấy câu trả lời.
   e. Người tài khoản khác mở link bệnh nhân bà D. → 404.
4. Chụp màn hình mọi màn ở 360×800 và 1280×800, cả sáng và tối, lưu vào docs/screenshots/, tự xem lại từng ảnh: chữ tràn, cuộn ngang, chồng lấp, tương phản, vùng bấm. Sửa rồi chụp lại.
5. Chạy lại bài rà soát: truy vấn bỏ qua tenant scope, route thiếu Policy/scopeBindings, code đụng tới liều, dữ liệu bệnh nhân hardcode, log dữ liệu nhạy cảm, service worker cache API. Sửa hết.
6. Merge nhánh giai-doan-6 vào nhánh chính cục bộ (không push).

==================================================================
L. KHI GẶP ĐIỀU CHƯA RÕ / ĐƯỢC PHÉP DỪNG
==================================================================
- Chi tiết kỹ thuật: tự chọn phương án an toàn nhất theo PLAN.md, ghi DECISIONS.md (vấn đề, lựa chọn, lý do, cách đổi).
- Nội dung y khoa/pháp lý: viết nháp gắn [NHÁP — CẦN DUYỆT], ghi DECISIONS.md, không khẳng định là đúng.
- CHỈ dừng hẳn khi: thiếu công cụ không tự cài được; MariaDB không chạy và không tự bật được; thao tác có thể mất dữ liệu có sẵn mà chưa sao lưu được.

==================================================================
M. BÁO CÁO CUỐI (trả lời tôi theo đúng mẫu)
==================================================================
1. Bảng checklist REVIEW.md mục 5: từng mục — Xong / Chưa xong / Một phần — kèm bằng chứng (tên test, ảnh chụp).
2. Số test: SQLite X đạt / Y đỏ; MariaDB X đạt / Y đỏ.
3. Kết quả 5 kịch bản đầu-cuối.
4. Danh sách ảnh chụp trong docs/screenshots/.
5. Quyết định mới trong DECISIONS.md.
6. Việc cần tôi làm bằng tay (TODO-NGUOI-THAT.md).
7. Rủi ro còn lại, nói thẳng.
```
