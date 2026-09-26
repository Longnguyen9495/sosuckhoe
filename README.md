# Sổ Sức Khỏe (sosuckhoe)

Sổ theo dõi điều trị tại nhà cho người bệnh và người nhà. Người dùng đăng ký nhanh bằng **tên + số điện thoại + ngày sinh**, rồi chụp/tải lên mọi giấy tờ khám bệnh (đơn thuốc, phiếu xét nghiệm, siêu âm, giấy ra viện, vỏ hộp thuốc). AI đọc ảnh, **loại bỏ số CCCD và mã thẻ BHYT**, lưu phần thông tin y khoa, rồi lập **lịch uống thuốc** theo bữa ăn và **chế độ ăn uống, sinh hoạt, theo dõi** bám theo kết quả xét nghiệm và thuốc đang dùng.

> Ứng dụng không tính, đổi hoặc gợi ý liều thuốc và không thay thế tư vấn, chẩn đoán hay quyết định điều trị của bác sĩ. Kế hoạch chăm sóc do AI lập luôn kèm ghi chú "để tham khảo".

## Luồng người dùng

1. **Trang giới thiệu** (`#/`) — nút **Thông tin cá nhân**.
2. Chưa có hồ sơ → **đăng ký nhanh** (`#/start`): họ tên, số điện thoại, ngày sinh. Không cần OTP.
   Tài khoản được tạo kèm **mật khẩu mặc định = tên viết liền không dấu, chữ thường + 4 số cuối SĐT**
   (VD *Nguyễn Văn An*, 0912 34**5678** → `nguyenvanan5678`). Mật khẩu hiện một lần sau khi đăng ký; đổi được ở `#/me`.
3. Lần sau **đăng nhập bằng số điện thoại + mật khẩu** (`#/login`).
4. **Tải ảnh khám bệnh** (`#/upload`), chọn nhiều ảnh một lần. Mỗi ảnh được vẽ lại trên trình duyệt (đúng chiều, bỏ metadata GPS), gửi lên máy chủ, mã hoá khi lưu, rồi AI đọc.
5. **Kiểm tra thuốc** (`#/review`): đối chiếu thuốc AI đọc được, bỏ chọn hoặc sửa, rồi bấm lưu để tạo lịch uống thuốc.
6. AI lập **kế hoạch chăm sóc** (`#/plan`): vấn đề chính kèm căn cứ, thực đơn mẫu một ngày, nên ăn / hạn chế / tránh, tương tác thuốc với thức ăn, chỉ số cần tự theo dõi, dấu hiệu cần đi khám ngay, câu nên hỏi bác sĩ. Gợi ý món cũng hiện ở các mốc bữa ăn trong lịch hằng ngày.

OTP vẫn giữ trong mã nguồn (`/api/v1/auth/otp/*`) để bật lại sau; giao diện hiện không dùng.

## Bảo mật dữ liệu

| Lớp | Cách làm |
|-----|----------|
| CCCD / BHYT | AI được dặn không ghi số định danh. **Mọi** kết quả AI còn đi qua `SensitiveDataScrubber`: xoá khoá `cccd/cmnd/bhyt/bhxh/passport…`, che số CCCD 12 chữ số, mã thẻ BHYT (2 chữ + 13 số), số đứng sau từ khoá CMND / BHXH / mã thẻ… trước khi lưu CSDL. Ảnh chỉ là thẻ CCCD / BHYT → **từ chối, không lưu gì**. |
| Ảnh gốc | Lưu ở `storage/app/private` (không public, `serve=false`), tên ngẫu nhiên, **mã hoá AES-256** bằng khoá riêng `DOCUMENT_ENCRYPTION_KEY` (tách khỏi `APP_KEY`). Bỏ EXIF / XMP / IPTC (vị trí GPS) cả ở trình duyệt và máy chủ. Chỉ tải qua API có kiểm quyền, `Cache-Control: private, no-store`. |
| Quyền truy cập | Tách theo tenant (sổ), `scopeBindings`, policy theo từng người bệnh; truy cập hồ sơ được ghi audit log (không ghi nội dung sức khỏe). |
| Gửi AI | Ảnh gửi qua HTTPS. Khi lập kế hoạch chăm sóc chỉ gửi dữ liệu đã ẩn danh: tuổi, giới, dị ứng, chẩn đoán, thuốc, xét nghiệm. Không gửi tên hay SĐT. |
| Đăng nhập | Mật khẩu băm bcrypt, giới hạn số lần thử, không để lộ SĐT nào đã đăng ký qua thời gian phản hồi. Nhắc đổi mật khẩu mặc định. Đổi mật khẩu thì đăng xuất các thiết bị khác. |
| Xoá | Xoá phiếu thì xoá luôn file mã hoá và các xét nghiệm tách từ phiếu. Xoá tài khoản thì xoá sổ sức khỏe do người đó sở hữu một mình, kèm toàn bộ ảnh. |

> **Không bao giờ commit `.env`** (chứa `AI_API_KEY`, `DOCUMENT_ENCRYPTION_KEY`). Ảnh phiếu thật, bản sao lưu CSDL (`backup/`, `*.sql`) đã nằm trong `.gitignore`.

## Cấu trúc thư mục

```
sosuckhoe/
├── app/                      Laravel: Controllers (Api/V1), Models, Services, Policies
│   ├── Services/Ai/          OpenAiCompatibleClient (gọi AI), MedicalPrompts, FakeMedicalAiClient (test)
│   ├── Services/Privacy/     SensitiveDataScrubber (lọc CCCD/BHYT), ImageMetadataStripper (bỏ EXIF)
│   ├── Services/Document/    DocumentIngestService (ảnh → AI → dữ liệu), DocumentEncryptionService
│   ├── Services/CarePlan/    CarePlanService (chế độ ăn, sinh hoạt, theo dõi)
│   └── Services/Schedule/    Sinh lịch thuốc, MedicationScheduleMapper (AI → giờ uống)
├── resources/js/views/       Giao diện JS thuần: landing, auth, upload (tải ảnh + kiểm tra thuốc), plan, me…
├── resources/css/            tokens / base / components / pages (phong cách Snapask)
├── database/                 migrations, seeders (dữ liệu demo "Bà D.")
├── tests/                    Feature + Unit (PHPUnit)
├── public/                   Điểm vào web (DocumentRoot)
├── docs/                     PLAN, DECISIONS, REVIEW, TODO-NGUOI-THAT, ảnh chụp màn hình, script chụp
└── archive/prototype/        Bản mẫu HTML/PHP cũ (chỉ để đối chiếu; ảnh thật không có trong git)
```

## Cài đặt trên Windows / XAMPP

Yêu cầu: XAMPP (Apache, MySQL/MariaDB, PHP 8.2+ có `fileinfo`, `mbstring`, `openssl`, `pdo_mysql`, `curl`), Composer 2, Node.js.

```bat
copy .env.example .env
composer install
php artisan key:generate
npm install
npm run build
```

Sửa `.env`:

```dotenv
APP_URL=http://sokhoe.local
DB_DATABASE=sosuckhoe
AI_DRIVER=openai
AI_BASE_URL=https://rexllm.xyz/v1
AI_API_KEY=...            # khoá của bạn
AI_MODEL=gpt-5.6-sol
DOCUMENT_ENCRYPTION_KEY=  # tạo bằng: php -r "echo 'base64:'.base64_encode(random_bytes(32)), PHP_EOL;"
```

Tạo database `sosuckhoe` (utf8mb4), rồi chạy:

```bat
php artisan migrate --seed
```

Virtual host Apache phải trỏ **DocumentRoot vào thư mục `public/`** (không trỏ vào thư mục gốc, vì sẽ lộ `.env`):

```apache
<VirtualHost *:80>
    ServerName sokhoe.local
    DocumentRoot "C:/xampp/htdocs/suckhoe/public"
    <Directory "C:/xampp/htdocs/suckhoe/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Không có khoá AI thì đặt `AI_DRIVER=fake` để chạy thử (AI giả, không gọi mạng).

## Tài khoản demo (chỉ môi trường local)

| Tài khoản | SĐT | Mật khẩu | Ghi chú |
|-----------|-----|----------|---------|
| Người chăm sóc bà D. | `0900000001` | `nguoichamsocdemo0001` | Có sẵn đơn thuốc, lịch, xét nghiệm. |
| Bác sĩ (2FA) | `0900000002` | `bacsidemo0002` | TOTP secret `GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ`. |
| Tài khoản trống | `0900000003` | `taikhoandangkydemo0003` | Chưa có sổ. |

## Kiểm thử

```bat
php artisan test
```

Test dùng SQLite trong bộ nhớ và `AI_DRIVER=fake`, nên không gọi AI thật. Phạm vi gồm: đăng ký nhanh và mật khẩu mặc định, đăng nhập, đổi mật khẩu; AI đọc ảnh không bao giờ lưu CCCD / BHYT; từ chối ảnh thẻ CCCD; file ảnh được mã hoá; xoá phiếu / tài khoản xoá cả file; tách tenant; thuốc → lịch → kế hoạch chăm sóc; bộ lọc và bỏ EXIF; cùng toàn bộ test cũ (lịch, ngưỡng, bác sĩ 2FA, offline queue…).
