# Deploy — Cron (shared hosting)

Cho môi trường không có Supervisor (shared hosting / cPanel).

## Setup 1 lần

Sau `git pull`, cấp quyền exec:
```bash
chmod +x deploy/cron/queue-drain.sh
```

## cPanel Cron Jobs

Vào **cPanel → Advanced → Cron Jobs**, thêm entry:

| Field   | Value |
|---------|-------|
| Minute  | `*`   |
| Hour    | `*`   |
| Day     | `*`   |
| Month   | `*`   |
| Weekday | `*`   |
| Command | `/home/sweetsic/public_html/data.sweetsica.com/deploy/cron/queue-drain.sh` |

Hoặc dùng preset **"Once per minute (*  *  *  *  *)"** rồi paste command.

## Kiểm tra

Sau 1-2 phút:
```bash
tail -f /home/sweetsic/public_html/data.sweetsica.com/storage/logs/queue.log
```

Thấy `START queue:work` mỗi phút → OK. Có `SKIP` = batch trước chưa xong (flock chống chạy chồng, an toàn).

## Đặc điểm

- **Max 55s/batch** để cron minute tiếp không đụng nhau.
- **flock** chống 2 instance chạy song song → an toàn khi batch kéo dài.
- **Log rotate tay** giữ 5 file × 2MB (không cần logrotate).
- **PHP binary** default = `php`. Nếu host dùng path khác (VD `/usr/local/bin/php74`): export `PHP_BIN=...` trong entry hoặc sửa command.

## Delay

Job phải chờ **≤ 1 phút** để cron minute tiếp pick up. Với import lead + notification thì ok (không cần realtime tuyệt đối).
