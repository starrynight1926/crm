# {{TÊN DỰ ÁN}} — Nhật ký kết quả

> Làm xong phase / patch có ý nghĩa nào → ghi vào đây: ngày, việc đã làm, việc dời lại/chưa xong, ghi chú & quyết định phát sinh. **File này là nguồn nối phase sau** — session mới đọc để biết đã đi tới đâu.

<!--
## Phase X — <tên phase> ✅
- **Ngày hoàn thành**: YYYY-MM-DD
- **Đã làm**:
  - {{Bullet ngắn — kèm đường dẫn file khi cần: [Lead.php](app/Models/Lead.php)}}
- **Dời lại / chưa xong**:
  - {{Task}} — lý do dời + phase dự kiến làm.
- **Ghi chú & quyết định phát sinh**:
  - {{Quyết định thiết kế phát sinh, giả định, đổi so với scope.md ban đầu}}.
- **Test**:
  - Unit/Feature: {{N passed / M total}}.
  - QA tay: {{đã / chưa}}.
-->

---

## Phase 0 — Scaffold & nền tảng ✅

- **Ngày hoàn thành**: YYYY-MM-DD
- **Đã làm**:
  - Init {{framework}}, config `.env`, tạo DB `{{name}}`.
  - Auth cơ bản.
- **Dời lại**: —
- **Ghi chú**: chọn stack {{X}} thay vì {{Y}} vì {{lý do}}.
- **Test**: 0 test, chỉ smoke test login.

---

**Hướng dẫn viết `result.md`:**
- **Ghi ngay sau khi xong** — đừng dồn.
- Ngày ghi theo định dạng tuyệt đối `YYYY-MM-DD`, không dùng "hôm qua" / "tuần trước".
- Section "Ghi chú & quyết định phát sinh" **quan trọng nhất** — nơi ghi tại sao chọn hướng này. Sau 3 tháng đọc lại phải hiểu.
- Đường dẫn file dùng markdown link để user click được.
- Không được rewrite entry cũ — đổi thiết kế thì thêm entry mới, ghi rõ "đổi lại so với Phase X: ...".
- Test fail sẵn từ trước → ghi rõ để không nhầm là regression.
