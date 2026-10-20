# Deploy — Queue worker (Supervisor / Cron)

Lara-SCRM chạy pipeline raw→clean qua queue database (`QUEUE_CONNECTION=database`).
Không có worker → import Excel treo mãi ở "Đang chờ", auto-recall SLA không chạy.

## Option 1: Supervisor (production, khuyên dùng)

```bash
# Copy config vào supervisor
sudo cp deploy/supervisor/lara-scrm-queue.conf /etc/supervisor/conf.d/lara-scrm-queue.conf

# Sửa path + user cho khớp server (mặc định giả định /home/sweetsic/public_html/data.sweetsica.com)
sudo nano /etc/supervisor/conf.d/lara-scrm-queue.conf

# Nạp + start
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start lara-scrm-queue:*

# Verify
sudo supervisorctl status lara-scrm-queue:*
tail -f storage/logs/queue.log
```

**Deploy mới code:** sau `git pull` chạy `sudo supervisorctl restart lara-scrm-queue:*` để worker load code mới.

## Option 2: Cron (shared hosting không có Supervisor)

Thêm vào crontab (`crontab -e`):

```
* * * * * cd /home/sweetsic/public_html/data.sweetsica.com && php artisan queue:work --stop-when-empty --max-time=55 >> storage/logs/queue.log 2>&1
```

Chạy mỗi phút, xử lý hết job trong ≤55s rồi tự thoát. Nhược điểm: job phải chờ tới ≤1 phút.

## Kiểm tra queue có work không

```bash
# Xem số job pending
php artisan tinker --execute="echo \DB::table('jobs')->count();"

# Drain 1 lần bằng tay (không cần worker)
php artisan queue:work --stop-when-empty
```

## Job types chạy trong queue này

- `App\Jobs\ProcessRawLead` — pipeline raw → clean (mỗi lead import 1 job).
- `Illuminate\Notifications\Events\BroadcastNotificationCreated` — Reverb notify realtime cho sale khi được chia lead.
- SLA recall commands (chạy schedule) cũng dispatch qua queue.
