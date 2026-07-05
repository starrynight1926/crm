<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Quy tắc trường của khu demo (Postgres). Người dùng tự tạo: tên + bộ trường.
 */
class DemoFieldRule extends Model
{
    protected $connection = 'pgsql';

    protected $table = 'demo_field_rules';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'fields' => 'array',
    ];

    /**
     * Khớp trường file vào cột chuẩn của demo_raw_leads (dùng cho validate + lọc + báo cáo).
     * '' = không khớp cột chuẩn nào, chỉ lưu thô vào payload.
     */
    public const ROLES = [
        ''       => '— chỉ lưu thô —',
        'name'   => 'Họ tên',
        'phone'  => 'SĐT',
        'source' => 'Nguồn',
        'date'   => 'Ngày',
    ];

    /** Chuẩn hóa về dạng $source dùng chung với engine đọc/validate. */
    public function toSource(): array
    {
        return [
            'key'    => 'rule_' . $this->id,
            'name'   => $this->name,
            'fields' => array_map(fn ($f) => [
                'label'    => $f['label'],
                'role'     => $f['role'] ?? '',
                'required' => (bool) ($f['required'] ?? false),
            ], $this->fields ?? []),
        ];
    }
}
