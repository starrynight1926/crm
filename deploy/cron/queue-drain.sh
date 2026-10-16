#!/bin/bash
# ------------------------------------------------------------------
# Cron entry: gọi file này bất kỳ interval nào cPanel cho phép
# (khuyến nghị mỗi 15 phút — nhiều shared host auto-throttle cron
# minute về interval lớn hơn, nên đừng đấu tranh với */1).
#
#   */15 * * * * /home/sweetsic/public_html/data.sweetsica.com/deploy/cron/queue-drain.sh
#
# Self-loop worker: mỗi lần cron gọi → spawn 1 worker sống ~14 phút,
# bên trong queue:work tự loop pick job mỗi vài giây → job push sbooking
# đẩy sang chỉ sau ~3-5 giây, không phải đợi 15 phút.
# Lần cron kế tiếp: worker cũ đã tự exit (max-time=840), lần mới bắt tay.
# Nếu cron chạy chồng vì host chạy sớm hơn: flock skip an toàn.
#
# Log rotate tay: giữ 5 file × 2MB.
# ------------------------------------------------------------------

APP_DIR="/home/sweetsic/public_html/data.sweetsica.com"
LOG_FILE="$APP_DIR/storage/logs/queue.log"
LOCK_FILE="$APP_DIR/storage/framework/queue.lock"
PHP_BIN="${PHP_BIN:-php}"

# Worker sống 14 phút = 840s (dưới cron interval 15 phút để lần sau bắt tay sạch).
# Đổi biến này qua env nếu cần: WORKER_MAX_TIME=300 (5 phút) cho debug.
MAX_TIME="${WORKER_MAX_TIME:-840}"

cd "$APP_DIR" || exit 1

# Rotate log nếu > 2MB (giữ 5 file cũ).
if [ -f "$LOG_FILE" ] && [ "$(stat -c%s "$LOG_FILE" 2>/dev/null || stat -f%z "$LOG_FILE")" -gt 2097152 ]; then
    for i in 4 3 2 1; do
        [ -f "$LOG_FILE.$i" ] && mv "$LOG_FILE.$i" "$LOG_FILE.$((i+1))"
    done
    mv "$LOG_FILE" "$LOG_FILE.1"
fi

# flock: chỉ 1 instance chạy cùng lúc. -n = non-blocking (skip nếu đang chạy).
(
    if ! flock -n 9; then
        echo "[$(date '+%F %T')] SKIP — worker trước còn sống (max-time chưa hết)." >> "$LOG_FILE"
        exit 0
    fi
    echo "[$(date '+%F %T')] START queue:work (self-loop ${MAX_TIME}s, sleep 3s)" >> "$LOG_FILE"
    # KHÔNG dùng --stop-when-empty: worker giữ sống, tự sleep 3s giữa các lần pick job trống.
    # --max-time=$MAX_TIME: worker tự exit sau khoảng thời gian đó → cron kế tiếp bắt tay.
    "$PHP_BIN" artisan queue:work --sleep=3 --max-time="$MAX_TIME" --tries=3 --backoff=30 >> "$LOG_FILE" 2>&1
    echo "[$(date '+%F %T')] END exit=$?" >> "$LOG_FILE"
) 9>"$LOCK_FILE"
