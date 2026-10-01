# {{TÊN DỰ ÁN}} — Thiết kế dữ liệu (ERD)

> Cập nhật: YYYY-MM-DD. Đồng bộ với `scope.md`. Đổi cấu trúc bảng phải update file này **trong cùng patch** với migration.

## A. Bối cảnh & connection

- **DB chính:** {{engine}} (`{{connection_name}}`) — {{mô tả tầng nào, cho gì}}.
- **DB phụ (nếu có):** {{engine}} (`{{connection_name}}`) — {{...}}.
- **Charset/collation:** {{...}}.
- **Convention:** timestamps mặc định, soft delete cho bảng nào, khóa ngoại có ON DELETE gì.

## B. Các bảng chính

### B1. {{Nhóm nghiệp vụ 1 — VD Tổ chức & phân quyền}}

**`{{table_name}}`**
| Cột | Kiểu / Ghi chú |
|---|---|
| id | PK |
| {{col}} | {{type}}, {{nullable/index/unique}}, {{nghĩa nghiệp vụ}} |
| ... | ... |
| timestamps | |

Index: `({{col1}}, {{col2}})`, `({{col3}})`.

Ghi chú:
- {{Giải thích cột phức tạp / soft rule / enum values}}.
- {{Rule cascade khi xóa}}.

**`{{table_name_2}}`**
...

### B2. {{Nhóm nghiệp vụ 2 — VD Lead}}
...

## C. Quan hệ chính (tóm tắt)

```
{{table_a}} ─< {{table_b}} >─ {{table_c}}
{{table_a}} ─< {{table_d}}
```

## D. Enum values (tập trung)

| Bảng.cột | Values | Nghĩa |
|---|---|---|
| `{{leads.status}}` | `new` / `active` / `done` | {{...}} |

## E. Migration đã chạy (tổng hợp)

Danh sách file migration + mục đích. Cập nhật khi thêm migration mới.

- `YYYY_MM_DD_HHMMSS_{{name}}.php` — {{mô tả 1 dòng}}.

---

**Hướng dẫn viết `ERD.md`:**
- Mỗi bảng: cột / kiểu / ghi chú nghiệp vụ (không chỉ kỹ thuật).
- Ghi rõ **index nào có** — quan trọng cho performance review sau.
- Enum values gom vào section D để dễ tra khi đổi tên.
- Thêm migration mới → update section E ngay.
- Đổi cấu trúc bảng đã có data → phải ghi **rủi ro & bước backfill** ở đây trước khi code.
