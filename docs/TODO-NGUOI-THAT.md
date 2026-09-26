# Việc cần người thật thực hiện

## Chặn triển khai thật

- [x] Đã kiểm chứng MySQL/MariaDB XAMPP trên database `suckhoe`: `php artisan migrate:fresh --seed`, `php artisan db:seed`, `php artisan test` đều thành công (91 tests, 385 assertions).
- [ ] Xác nhận Apache chỉ phục vụ thư mục `backend/public`, bật `mod_rewrite` và không truy cập được `.env`.
- [ ] Đổi `APP_ENV`, `APP_DEBUG`, khóa ứng dụng và thông tin database cho môi trường thật.
- [ ] Thay OTP fake bằng nhà cung cấp OTP thật trước khi mở cho người ngoài nhóm phát triển.
- [ ] Cấu hình `services.push.driver` khác `fake` và thêm khoá VAPID thật trước khi bật Web Push.
- [ ] Cấu hình `services.ai_ocr.driver` khác `fake` và thêm API key thật trước khi bật AI đọc đơn.
- [ ] Cấu hình `services.zalo.driver` và `services.sms.driver` khác `fake` trước khi gửi thông báo thật.

## Cần chuyên môn/pháp lý duyệt

- [ ] Bác sĩ/pháp chế duyệt toàn bộ nội dung có nhãn `[NHÁP — CẦN DUYỆT]` trước khi phát hành.
- [ ] Bác sĩ đối chiếu 12 dòng thuốc/vật tư, nguyên văn `dose_text`, số lượng đã kê/đã mua và ngày kết thúc với hồ sơ gốc.
- [ ] Bác sĩ đối chiếu 10 tình trạng, 33 kết quả xét nghiệm, 14 mốc và 16 câu hỏi với 28 ảnh nguồn.
- [ ] Xác nhận quyền sử dụng/lưu trữ ảnh hồ sơ; thay đường dẫn demo bằng kho mã hóa khi triển khai.
- [ ] Hoàn thiện chính sách quyền riêng tư, điều khoản, quy trình đồng ý, yêu cầu xuất/xóa dữ liệu và xử lý sự cố theo pháp luật Việt Nam.
- [ ] Bác sĩ xác nhận ngưỡng `patient_thresholds` mặc định cho từng mẫu bệnh (template) phù hợp với hướng dẫn lâm sàng.

## Trước khi mở cho khách ngoài

- [ ] Kiểm thử thủ công trên điện thoại 360 px, máy tính, dark mode và luồng OTP → tenant → patient → day → ghi chỉ số.
- [ ] Kiểm tra luồng đăng ký 7 bước (onboarding) trên điện thoại thật, đo thời gian hoàn tất.
- [ ] Kiểm tra PWA trên HTTPS; service worker không hoạt động đầy đủ trên HTTP ngoài localhost.
- [ ] Kiểm thử offline write queue: tắt mạng, ghi chỉ số, bật lại mạng, xác nhận đồng bộ thành công.
- [ ] Kiểm tra cổng bác sĩ (`#/doctor`) trên máy tính: 2 cột, danh sách sắp theo cờ đỏ, hồ sơ, báo cáo PDF.
- [ ] Kiểm tra AI đọc đơn (`#/rx/scan`): chụp ảnh, xác nhận từng dòng, lưu thành đơn thuốc.
- [ ] Cấu hình backup, giám sát, TLS và hạ tầng lưu trữ tại Việt Nam theo PLAN.
- [ ] Xử lý cảnh báo deprecation `PDO::MYSQL_ATTR_SSL_CA` khi nâng framework/PHP; cảnh báo hiện đến từ cấu hình Laravel trên PHP 8.5.

## Thử nghiệm với gia đình bà D. (Giai đoạn 6)

- [ ] Hướng dẫn người nhà cài PWA và đăng nhập bằng số điện thoại thật.
- [ ] Quan sát người nhà dùng màn “Hôm nay”: có hiểu lịch uống thuốc, lịch đo, mốc không?
- [ ] Thử luồng đăng ký mới bằng số điện thoại khác (tài khoản trống), kiểm tra 7 bước có bị kẹt không.
- [ ] Ghi nhận thời gian để ghi một chỉ số đường huyết từ lúc mở app đến lúc lưu.
- [ ] Đối chiếu 1 tuần số liệu nhập vào app với bản mẫu (prototype) để phát hiện lệch.
- [ ] Kiểm tra alert đỏ có hiện rõ, có gây hoảng không, người nhà biết phải làm gì không.
- [ ] Ghi lại chỗ bị kẹt hoặc khó hiểu vào file ghi chú thực tế (không giả vờ).

## Không còn trong phạm vi Giai đoạn 6

Giai đoạn 6 đã hoàn tất toàn bộ tính năng từ Giai đoạn 1 đến Giai đoạn 5. Các tính năng còn lại nằm ngoài phạm vi:
- Tích hợp Zalo OA doanh nghiệp thật (hiện chỉ có fake driver).
- Gửi SMS thật qua Twilio (hiện chỉ có fake driver).
- AI OCR thật (OpenAI/Google Vision) thay vì fake driver.
- Nhắc thuốc qua Web Push thật thay vì fake driver.
- Tích hợp hệ thống HIS/bệnh viện.
