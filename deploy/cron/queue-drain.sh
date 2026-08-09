#!/bin/bash
# ------------------------------------------------------------------
# Cron entry: gọi file này mỗi 1 phút trên cPanel Cron Jobs.
#   * * * * * /home/sweetsic/public_html/data.sweetsica.com/deploy/cron/queue-drain.sh
#
# Chạy queue:work --stop-when-empty (max 55s để cron tiếp không đụng)
# → drain hết ProcessRawLead + notification broadcast trong batch hiện tại,
# thoát; cron minute tiếp lại chạy nếu có job mới.
#
# Log rotate tay: giữ 5 file × 2MB.
# Lock file chống chạy chồng (nếu batch trước chưa xong, batch mới skip).
# ------------------------------------------------------------------

APP_DIR="/home/sweetsic/public_html/data.sweetsica.com"
LOG_FILE="$APP_DIR/storage/logs/queue.log"
LOCK_FILE="$APP_DIR/storage/framework/queue.lock"
PHP_BIN="${PHP_BIN:-php}"

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
        echo "[$(date '+%F %T')] SKIP — instance trước chưa xong." >> "$LOG_FILE"
        exit 0
    fi
    echo "[$(date '+%F %T')] START queue:work" >> "$LOG_FILE"
    "$PHP_BIN" artisan queue:work --stop-when-empty --max-time=55 --tries=3 --backoff=30 >> "$LOG_FILE" 2>&1
    echo "[$(date '+%F %T')] END exit=$?" >> "$LOG_FILE"
) 9>"$LOCK_FILE"
