<?php

namespace Database\Seeders;

use App\Models\Assignment;
use App\Models\CustomField;
use App\Models\Lead;
use App\Models\OrgUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Dữ liệu mẫu: nhân sự 2 phòng, khách kho chung, và quy tắc trường (mã phân loại
 * + phân loại nguồn) cho phòng Kinh doanh / Marketing. Idempotent qua updateOrCreate.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $sales = OrgUnit::firstWhere('code', 'sales');
        $marketing = OrgUnit::firstWhere('code', 'marketing');
        $saleRole = Role::firstWhere('name', 'Sale');

        // Role Sale cần quyền cơ bản để thao tác lead (chỉ gán nếu chưa có quyền nào)
        if ($saleRole && $saleRole->permissions()->count() === 0) {
            $saleRole->permissions()->sync(
                Permission::whereIn('key', ['lead.view', 'lead.view_phone', 'lead.create', 'lead.update', 'payment.record'])->pluck('id')
            );
        }

        // ── Nhân sự ──────────────────────────────────────────────
        $this->staff('nvkd@sweetsica.com', 'NV Kinh Doanh', $saleRole, $sales);
        $this->staff('nvmkt@sweetsica.com', 'NV Marketing', $saleRole, $marketing);

        // ── Khách hàng vào kho chung (chưa chia) ─────────────────
        $receiver = User::firstWhere('email', 'admin@sweetsica.com');
        for ($i = 1; $i <= 5; $i++) {
            $phone = Lead::normalizePhone('091558800' . $i) ?? '091558800' . $i;
            Lead::firstOrCreate(
                ['phone' => $phone],
                [
                    'name' => 'Khách test' . $i,
                    'received_date' => now()->toDateString(),
                    'classification' => 'new',
                    'pool_level' => Lead::POOL_COMMON,
                    'receiver_id' => $receiver?->id,
                    'org_unit_id' => null,
                ]
            )->generateCode();
        }

        // ── Quy tắc trường ───────────────────────────────────────
        // Cấp công ty: "Phân loại" (loại data) nối thẳng vào mã KH → KH-{id}-{mã}
        $this->selectField(null, 'phan_loai', 'Phân loại', [
            'MKT' => 'Data Marketing',
            'C' => 'Data lạnh',
            'BDM' => 'Data BDM',
            'SI' => 'Data tự giới thiệu',
            'N' => 'Data tự đến',
        ], 1, affectsCode: true);

        // Dọn field mã cố định theo phòng (KD/MKT) — đã thay bằng "Phân loại" cấp công ty,
        // giữ lại thì mã KH bị nối lặp (VD KH-1-MKT-MKT). Xóa cả giá trị lead đính kèm.
        CustomField::whereIn('org_unit_id', array_filter([$sales?->id, $marketing?->id]))
            ->where('key', 'ma_phan_loai')
            ->get()->each->delete();
        // "Phân loại" cũ ở phòng KD (C/BDM… nối mã) cũng bỏ — đã gộp lên cấp công ty
        if ($sales) {
            CustomField::where('org_unit_id', $sales->id)->where('key', 'phan_loai')->get()->each->delete();
        }

        // Cấp phòng Marketing: các trường theo MẪU PHÒNG MARKETING (không nối mã)
        if ($marketing) {
            $this->selectField($marketing->id, 'nguon_quang_cao', 'Nguồn quảng cáo',
                $this->flat(['MKT', 'Quét Inbox']), 1);
            $this->selectField($marketing->id, 'khu_vuc', 'Khu vực',
                $this->flat(['TP.HCM', 'TỈNH', 'Hà Nội']), 2);
            $this->selectField($marketing->id, 'camp', 'Camp', $this->flat([
                'Khoa', 'TBG Nhật', 'tiểu đường', 'tự ib', 'miền Nam', 'TBG Sing', 'XK Nhật',
                'XK 0 tuổi', 'Viên uống', 'website', 'trẻ hóa 0 tuổi', 'tự inbox', 'depoxy',
                'gói khám đột quỵ', 'gói khám tiểu đường', 'gói khám gan', 'gói khám cổ vai gáy',
                'quà tặng', 'TBG nhật-sing',
            ]), 3);
            // PHÂN LOẠI (chăm sóc) + KẾT QUẢ là 2 dropdown riêng trong mẫu
            $this->selectField($marketing->id, 'phan_loai_cs', 'Phân loại (chăm sóc)', $this->flat([
                'Nét', 'Quan tâm', 'Tài chính yếu', 'Không nhu cầu', 'KLLD', 'Tìm hiểu', 'Gọi lại sau',
            ]), 4);
            $this->selectField($marketing->id, 'ket_qua', 'Kết quả', $this->flat([
                'Close', 'Show', 'Follow', 'Booking', 'Missed',
            ]), 5);
            // Bỏ field phan_loai funnel seed cũ (nếu có) — đã đổi key thành phan_loai_cs
            CustomField::where('org_unit_id', $marketing->id)->where('key', 'phan_loai')->get()->each->delete();
        }
    }

    /** Danh sách giá trị mà Giải thích = chính Giá trị (map value => value). */
    private function flat(array $values): array
    {
        return array_combine($values, $values);
    }

    private function staff(string $email, string $name, ?Role $role, ?OrgUnit $org): void
    {
        $user = User::updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => '123456', 'status' => User::STATUS_ACTIVE]
        );

        if ($role && $org && ! Assignment::where('user_id', $user->id)->exists()) {
            Assignment::create([
                'user_id' => $user->id,
                'role_id' => $role->id,
                'org_unit_id' => $org->id,
                'data_scope' => Assignment::SCOPE_SELF,
            ]);
        }
    }

    /**
     * Trường select: Giá trị (options) + Giải thích (option_labels). $affectsCode = true
     * thì Giá trị được nối vào sau mã KH khi lead dùng trường này.
     */
    private function selectField(?int $orgId, string $key, string $label, array $valueLabels, int $position, bool $affectsCode = false): void
    {
        CustomField::updateOrCreate(
            ['org_unit_id' => $orgId, 'key' => $key],
            [
                'label' => $label,
                'field_type' => 'select',
                'options' => array_keys($valueLabels),
                'rules' => ['option_labels' => $valueLabels],
                'affects_code' => $affectsCode,
                'required' => false,
                'position' => $position,
                'status' => CustomField::STATUS_ACTIVE,
                'active' => true,
            ]
        );
    }
}
