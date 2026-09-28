# Triển khai production

Cùng VPS và cách làm với Snapask: Nginx + PHP 8.5-FPM, SQLite, SSL Let's Encrypt (certbot), deploy tay qua SSH.

| Mục | Giá trị |
|-----|---------|
| Địa chỉ | https://sosuckhoe.221-121-1-68.sslip.io |
| VPS | `221.121.1.68` (Ubuntu). SSH: `ssh -i "$env:USERPROFILE\.ssh\pageseed_bizfly" root@221.121.1.68` |
| Mã nguồn | `/var/www/sosuckhoe` (git clone từ `github.com/Longnguyen9495/sosuckhoe`, nhánh `main`), chủ sở hữu `rexllm:www-data` |
| Web root | `/var/www/sosuckhoe/public` |
| Nginx | `/etc/nginx/sites-available/sosuckhoe`. Đặt `client_max_body_size 20m`, `fastcgi_read_timeout 300`; `PHP_VALUE` nâng `upload_max_filesize` và `max_execution_time` riêng cho site này |
| CSDL | SQLite `/var/www/sosuckhoe/database/database.sqlite` |
| Ảnh phiếu (mã hoá) | `/var/www/sosuckhoe/storage/app/private/documents/` |
| `.env` | `640 rexllm:www-data`; chứa `APP_KEY`, `AI_API_KEY`, `DOCUMENT_ENCRYPTION_KEY`. **Sao lưu `DOCUMENT_ENCRYPTION_KEY`**, mất khoá này là không mở được ảnh |
| Hàng đợi / lịch | Hàng đợi không cần (`QUEUE_CONNECTION=sync`). Lịch: systemd timer `sosuckhoe-schedule.timer` chạy `schedule:run` mỗi phút (VPS không có `crontab`) — xem mục "Lịch chạy tự động" |

> Luôn chạy git / composer / npm / artisan bằng `sudo -u rexllm`, **không chạy bằng root**. Nếu chạy bằng root, file sẽ thuộc root và PHP-FPM không ghi được.

## Cập nhật phiên bản mới

Làm sau khi đã chạy `php artisan test` ở máy và push lên `main`:

```bash
cd /var/www/sosuckhoe
mkdir -p /var/backups/sosuckhoe && cp -p database/database.sqlite /var/backups/sosuckhoe/database-$(date +%Y%m%d-%H%M%S).sqlite
sudo -u rexllm git pull --ff-only origin main
sudo -u rexllm composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
sudo -u rexllm npm ci --no-audit --no-fund && sudo -u rexllm npm run build
sudo -u rexllm php artisan migrate --force
sudo -u rexllm php artisan optimize:clear && sudo -u rexllm php artisan config:cache && sudo -u rexllm php artisan route:cache && sudo -u rexllm php artisan view:cache
chown -R rexllm:www-data storage bootstrap/cache database && chmod 2770 database && chmod 660 database/database.sqlite
chmod -R u+rwX,g+rwX,o-rwx storage/app/private   # ảnh phiếu: chỉ rexllm + nhóm www-data (PHP-FPM) đọc/ghi
```

Biến `.env` mới phải thêm bằng tay, rồi chạy lại `config:cache`.

## Kiểm tra sau khi deploy

```bash
for p in / /api/v1/consents/version; do curl -s -o /dev/null -w "$p %{http_code}\n" https://sosuckhoe.221-121-1-68.sslip.io$p; done   # 200
curl -s -o /dev/null -w "%{http_code}\n" https://sosuckhoe.221-121-1-68.sslip.io/.env            # 403
curl -s -o /dev/null -w "%{http_code}\n" -H "Accept: application/json" https://sosuckhoe.221-121-1-68.sslip.io/api/v1/auth/me   # 401
for s in snapask.221-121-1-68.sslip.io caitiemneo.221-121-1-68.sslip.io 221-121-1-68.sslip.io; do curl -s -o /dev/null -w "$s %{http_code}\n" https://$s/; done   # các site khác vẫn 200
systemctl is-active nginx php8.5-fpm
```

## Dữ liệu ban đầu

Production chỉ seed danh mục: `DrugSeeder`, `TemplateSeeder`, `ContentSeeder`. **Không** seed dữ liệu demo `DemoPatientBaDSeeder`.

Điều khoản đồng ý hiện là bản **nháp**. Ở production chỉ bản đã duyệt mới hiện ra và mới được ghi nhận, nên cần thêm một bản `consent_versions` có `is_draft = 0` sau khi pháp chế duyệt nội dung.

## Lịch chạy tự động

Thực đơn và bài tập **mỗi ngày một khác**: lệnh `careplan:daily` chạy lúc 04:30 (giờ Việt Nam), khai báo trong `routes/console.php`. Laravel chỉ chạy lịch khi có tiến trình gọi `schedule:run` mỗi phút. VPS là Ubuntu bản rút gọn (không có `crontab`) nên dùng systemd timer, đã cài ngày 28/09/2026:

```ini
# /etc/systemd/system/sosuckhoe-schedule.service
[Service]
Type=oneshot
User=rexllm
Group=www-data
WorkingDirectory=/var/www/sosuckhoe
ExecStart=/usr/bin/php artisan schedule:run

# /etc/systemd/system/sosuckhoe-schedule.timer
[Timer]
OnCalendar=*-*-* *:*:00
AccuracySec=1s
[Install]
WantedBy=timers.target
```

```bash
systemctl daemon-reload && systemctl enable --now sosuckhoe-schedule.timer
systemctl list-timers sosuckhoe-schedule.timer          # lần chạy kế tiếp
journalctl -u sosuckhoe-schedule.service --since today  # nhật ký
```

Kiểm tra:

```bash
php artisan schedule:list          # thấy "30 4 * * *  php artisan careplan:daily"
php artisan careplan:daily         # chạy tay cho hôm nay (mỗi người bệnh ~10–20 giây, có gọi AI)
php artisan careplan:daily --no-ai # chỉ luân phiên bài tập + thực đơn 7 ngày, không gọi AI
```

Chưa có cron (hoặc AI lỗi) thì màn Hôm nay vẫn hiện thực đơn 7 ngày của kế hoạch chăm sóc và bài tập luân phiên theo ngày — không trống.

Sau khi deploy bản có thực đơn 7 ngày: kế hoạch chăm sóc cũ chưa có `weekly_menu` — bấm "Lập lại" ở màn Phác đồ (hoặc chạy lại lập kế hoạch) một lần để có thực đơn 7 ngày làm nền.
