# {{TÊN DỰ ÁN}} — Kế hoạch phase

> Thứ tự làm bám file này. Muốn đảo/gộp phase → **hỏi user trước**. Xong phase → ghi `result.md` rồi mới chuyển phase kế.

## Nguyên tắc

- Mỗi phase có: **mục tiêu**, **checklist việc**, **định nghĩa "xong"**, **test/QA của phase đó**.
- Phase nhỏ đủ để làm trong 1-3 buổi. To hơn → tách.
- Phase cuối là **Test tổng thể & QA E2E**.

---

## Phase 0 — Scaffold & nền tảng

**Mục tiêu:** dựng khung dự án chạy được.

- [ ] Init framework + config môi trường (`.env`, DB connection).
- [ ] Auth cơ bản (đăng nhập, session, logout).
- [ ] Layout chính + routing skeleton.
- [ ] CI / test scaffold chạy được (`{{command}}`).

**Xong khi:** login được, home page render, test suite chạy pass (kể cả 0 test).

---

## Phase 1 — {{Tên phase}}

**Mục tiêu:** {{...}}.

- [ ] Migration + model + factory cho bảng {{a}}, {{b}}.
- [ ] {{Feature}} CRUD.
- [ ] Unit test cho logic quan trọng.
- [ ] QA tay: {{3-5 case chính}}.

**Xong khi:** {{định nghĩa cụ thể}}.

**Rủi ro:** {{nếu có}}.

---

## Phase 2 — {{...}}
...

---

## Phase N — Test tổng thể & QA E2E

- [ ] E2E theo luồng nghiệp vụ chính (từng luồng 1 case).
- [ ] Phân quyền: mỗi role login thấy đúng data + đúng menu.
- [ ] Data lớn: seed {{N}} record, kiểm tra query chậm không.
- [ ] Race condition: 2 tab thao tác đồng thời.
- [ ] Bug bash: 30-60 phút click random.

---

## Việc dời lại (không thuộc phase cụ thể)

- [ ] {{Task}} — lý do dời + phase dự kiến làm.

---

**Hướng dẫn viết `plan.md`:**
- Tick `[x]` khi làm xong (không xóa checkbox — để tra lịch sử).
- Thêm phase phát sinh → đặt số kế tiếp, ghi rõ **ngày thêm + lý do**.
- Việc dời không được vứt đi → move xuống section "Việc dời lại" với lý do.
- Mỗi phase phải có định nghĩa "Xong khi:" cụ thể (đo được), không phải "làm xong" chung chung.
