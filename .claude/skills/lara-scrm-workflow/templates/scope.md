# {{TÊN DỰ ÁN}} — Scope & Thiết kế tổng quan

> Cập nhật: YYYY-MM-DD — {{lý do bản cập nhật này}}. File này là **nguồn tham chiếu chính** của dự án — mọi thay đổi scope phải update ở đây trước khi code.

## 1. Mục tiêu & phạm vi

- **Mục tiêu:** {{1-2 câu giải bài toán nghiệp vụ chính}}.
- **Trong scope:** {{gạch đầu dòng những gì làm}}.
- **Ngoài scope (rõ ràng):** {{gạch đầu dòng những gì KHÔNG làm — quan trọng để chặn scope creep}}.

## 2. Người dùng & vai trò

| Vai trò | Ai | Việc chính | Ghi chú |
|---|---|---|---|
| {{Admin}} | {{ai}} | {{...}} | {{...}} |
| {{Operator}} | {{ai}} | {{...}} | {{...}} |
| {{End user}} | {{ai}} | {{...}} | {{...}} |

## 3. Luồng nghiệp vụ chính

Mô tả 3-7 luồng chính. Mỗi luồng: **trigger → các bước → output**.

### 3.1 Luồng {{tên}}
1. {{Bước 1}}
2. {{Bước 2}}
3. {{Output}}

## 4. Dữ liệu chính (tham chiếu ERD)

- Bảng chính: `{{table_a}}`, `{{table_b}}`... → chi tiết cột trong `ERD.md`.
- Các enum quan trọng: `{{field}}` = `{{a}}` / `{{b}}` / `{{c}}` — nghĩa nghiệp vụ ghi ở đây.

## 5. Nguồn dữ liệu (nếu nhiều nguồn)

Liệt kê từng nguồn: cách vào hệ thống, ai up, có duyệt không, đi đâu sau khi vào.

## 6. Rule nghiệp vụ

### 6.1 {{Rule name}}
Điều kiện + hành vi. Kèm ví dụ cụ thể nếu phức tạp.

## 7. Tổ chức & phân quyền

### 7.1 Quyền chức năng (RBAC)
- Danh sách permission key + nghĩa.
- Role mặc định seed sẵn + perm tương ứng.

### 7.2 Phạm vi dữ liệu (data scope)
- Các mức scope (self / team / custom).
- Cách phân định.

## 8. Lifecycle / State machine

Mô tả các state, transition, ai trigger, log gì.

## 9. Rủi ro & giả định

- **Giả định:** {{những thứ đang giả định đúng, cần verify}}.
- **Rủi ro đã biết:** {{}}.
- **Điều cần user chốt sau:** {{để phase sau xử lý}}.

---

**Hướng dẫn viết `scope.md`:**
- Cập nhật ngay khi thiết kế đổi — đừng để code chạy khác tài liệu.
- Đưa ví dụ cụ thể ở rule phức tạp (kèm số/tình huống thật).
- Bảng > text khi có nhiều tổ hợp.
- Section "Ngoài scope" quan trọng bằng section "Trong scope".
- Ghi **ngày + lý do** ở đầu file mỗi lần đổi lớn.
