# Deploy — Cron (shared hosting)

Cho môi trường không có Supervisor (shared hosting / cPanel).

## Setup 1 lần

Sau `git pull`, cấp quyền exec:
```bash
chmod +x deploy/cron/queue-drain.sh
```

## cPanel Cron Jobs

Vào **cPanel → Advanced → Cron Jobs**, thêm entry:

| Field   | Value  |
|---------|--------|
| Minute  | `*/15` |
| Hour    | `*`    |
| Day     | `*`    |
| Month   | `*`    |
| Weekday | `*`    |
| Command | `/home/sweetsic/public_html/data.sweetsica.com/deploy/cron/queue-drain.sh` |

**Không dùng `* * * * *`** — shared host (cPanel) hay auto-throttle cron minute,
rewrite thành `*/15` hoặc random interval để chống flood. Không phải bug script.

Script tự loop bên trong (worker sống 14 phút, sleep 3s giữa lần pick job) →
job đẩy trong 3-5s, không phụ thuộc cron interval.

## Kiểm tra

Sau 1-2 phút:
```bash
tail -f /home/sweetsic/public_html/data.sweetsica.com/storage/logs/queue.log
```

Thấy `START queue:work` mỗi phút → OK. Có `SKIP` = batch trước chưa xong (flock chống chạy chồng, an toàn).

## Đặc điểm

- **Self-loop worker** sống ~14 phút (`--max-time=840`), tự thoát trước cron kế tiếp.
- **`--sleep=3`**: khi hết job worker ngủ 3s rồi check lại → job mới đẩy trong 3-5s.
- **flock** chống 2 instance chạy song song (nếu cron trigger sớm hơn) → an toàn.
- **Log rotate tay** giữ 5 file × 2MB (không cần logrotate).
- **PHP binary** default = `php`. Đổi qua env: `PHP_BIN=/opt/cpanel/ea-php85/root/usr/bin/php`.
- **Worker lifetime** đổi qua env: `WORKER_MAX_TIME=300` (5 phút) cho debug.

## Delay

- Job đẩy trong **~3-5 giây** khi worker đang sống (đa số thời gian).
- Trường hợp worker vừa exit (max-time hết) + cron chưa gọi lại: delay tối đa =
  cron interval (khuyến nghị 15 phút → worst-case 60s giữa 2 lần cron gọi vì
  worker đã chạy 14/15 phút xong).

## Reload code sau deploy

Sau `git pull` có sửa Job/Service, worker đang sống vẫn giữ code cũ trong RAM. Chạy:

```bash
php artisan queue:restart
```

Worker sẽ tự thoát ở lần pick job kế tiếp, cron sau spawn worker mới với code mới.
