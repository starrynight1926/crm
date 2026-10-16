<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\CustomField;
use App\Models\Lead;
use App\Models\OrgUnit;
use App\Models\Role;
use App\Models\Service;
use App\Models\User;
use App\Support\SpreadsheetReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * 2026-08-04 (Task 3): Import trực tiếp cho trang Danh mục hệ thống.
 *
 * Guard bên ngoài: chỉ Admin hệ thống gọi (permission user.manage đã check ở
 * SystemCatalogController). Import ghi thẳng vào bảng đích — không qua raw
 * pipeline vì đây là công cụ admin, người dùng chịu trách nhiệm data.
 *
 * Format cột phải khớp file mẫu do SystemCatalogController::template() sinh ra.
 * Trả về array {created, updated, skipped, errors[]} để hiển thị report.
 */
class SystemCatalogImporter
{
    /** @return array{created:int, updated:int, skipped:int, errors:array<int,string>} */
    public function import(string $tab, string $filePath, string $extension): array
    {
        $parsed = SpreadsheetReader::read($filePath, $extension);
        if ($parsed['headers'] === []) {
            return ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => ['File rỗng hoặc không đọc được.']];
        }

        return match ($tab) {
            'org' => $this->importOrg($parsed),
            'staff' => $this->importStaff($parsed),
            'service' => $this->importService($parsed),
            'lead' => $this->importLead($parsed),
            'field' => $this->importField($parsed),
            default => ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => ["Tab '{$tab}' không hợp lệ."]],
        };
    }

    /** Ánh xạ header → chỉ số cột (case-insensitive, trim). */
    private function mapCols(array $headers): array
    {
        $out = [];
        foreach ($headers as $i => $h) {
            $out[strtolower(trim((string) $h))] = $i;
        }
        return $out;
    }

    private function val(array $row, array $cols, string $key): ?string
    {
        if (! isset($cols[$key])) return null;
        $v = $row[$cols[$key]] ?? null;
        if ($v === null) return null;
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }

    // ------------------------------------------------------------
    // ORG
    // ------------------------------------------------------------
    private function importOrg(array $parsed): array
    {
        $cols = $this->mapCols($parsed['headers']);
        $created = 0; $updated = 0; $skipped = 0; $errors = [];

        foreach ($parsed['rows'] as $i => $row) {
            $line = $i + 2;
            $code = $this->val($row, $cols, 'code');
            $name = $this->val($row, $cols, 'name');
            if (! $code || ! $name) {
                $errors[] = "Dòng {$line}: thiếu code hoặc name.";
                $skipped++;
                continue;
            }

            $parentCode = $this->val($row, $cols, 'parent_code');
            $parent = $parentCode ? OrgUnit::firstWhere('code', $parentCode) : null;
            if ($parentCode && ! $parent) {
                $errors[] = "Dòng {$line}: parent_code '{$parentCode}' không tồn tại.";
                $skipped++;
                continue;
            }

            $exists = OrgUnit::firstWhere('code', $code);
            $data = [
                'name' => $name,
                'position' => (int) ($this->val($row, $cols, 'position') ?? 0),
                'active' => (bool) ($this->val($row, $cols, 'active') ?? '1'),
            ];

            if ($exists) {
                $exists->update($data);
                $updated++;
            } else {
                OrgUnit::createNode($data + ['code' => $code], $parent);
                $created++;
            }
        }

        return compact('created', 'updated', 'skipped', 'errors');
    }

    // ------------------------------------------------------------
    // STAFF (user + 1 assignment)
    // ------------------------------------------------------------
    private function importStaff(array $parsed): array
    {
        $cols = $this->mapCols($parsed['headers']);
        $created = 0; $updated = 0; $skipped = 0; $errors = [];

        foreach ($parsed['rows'] as $i => $row) {
            $line = $i + 2;
            $email = $this->val($row, $cols, 'email');
            $name = $this->val($row, $cols, 'name');
            if (! $email || ! $name) {
                $errors[] = "Dòng {$line}: thiếu email hoặc name.";
                $skipped++;
                continue;
            }

            $roleName = $this->val($row, $cols, 'role_name');
            $orgCode = $this->val($row, $cols, 'org_unit_code');
            $role = $roleName ? Role::firstWhere('name', $roleName) : null;
            $org = $orgCode ? OrgUnit::firstWhere('code', $orgCode) : null;
            if ($roleName && ! $role) {
                $errors[] = "Dòng {$line}: role '{$roleName}' không tồn tại.";
                $skipped++;
                continue;
            }
            if ($orgCode && ! $org) {
                $errors[] = "Dòng {$line}: org_unit '{$orgCode}' không tồn tại.";
                $skipped++;
                continue;
            }

            $passPlain = $this->val($row, $cols, 'password');
            $userData = [
                'username' => $this->val($row, $cols, 'username'),
                'name' => $name,
                'job_title' => $this->val($row, $cols, 'job_title'),
                'status' => $this->val($row, $cols, 'status') ?: User::STATUS_ACTIVE,
            ];
            if ($passPlain && $passPlain !== '(đã hash, không xuất)') {
                $userData['password'] = Hash::make($passPlain);
            }

            $existing = User::firstWhere('email', $email);
            if ($existing) {
                $existing->update($userData);
                $updated++;
                $user = $existing;
            } else {
                if (empty($userData['password'])) {
                    $userData['password'] = Hash::make('changeme');
                }
                $user = User::create($userData + ['email' => $email]);
                $created++;
            }

            if ($role && $org) {
                Assignment::updateOrCreate(
                    ['user_id' => $user->id, 'role_id' => $role->id, 'org_unit_id' => $org->id],
                    ['data_scope' => $this->val($row, $cols, 'data_scope') ?: Assignment::SCOPE_TEAM, 'active' => true]
                );
            }
        }

        return compact('created', 'updated', 'skipped', 'errors');
    }

    // ------------------------------------------------------------
    // SERVICE
    // ------------------------------------------------------------
    private function importService(array $parsed): array
    {
        $cols = $this->mapCols($parsed['headers']);
        $created = 0; $updated = 0; $skipped = 0; $errors = [];

        foreach ($parsed['rows'] as $i => $row) {
            $line = $i + 2;
            $code = $this->val($row, $cols, 'code');
            $name = $this->val($row, $cols, 'name');
            if (! $code || ! $name) {
                $errors[] = "Dòng {$line}: thiếu code hoặc name.";
                $skipped++;
                continue;
            }

            $data = [
                'name' => $name,
                'service_type' => $this->val($row, $cols, 'service_type'),
                'pricing_type' => $this->val($row, $cols, 'pricing_type') ?: 'package',
                'package_price' => (float) ($this->val($row, $cols, 'package_price') ?? 0),
                'active' => (bool) ($this->val($row, $cols, 'active') ?? '1'),
            ];

            $existing = Service::firstWhere('code', $code);
            if ($existing) {
                $existing->update($data);
                $updated++;
            } else {
                Service::create($data + ['code' => $code]);
                $created++;
            }
        }

        return compact('created', 'updated', 'skipped', 'errors');
    }

    // ------------------------------------------------------------
    // LEAD (direct insert, không qua raw pipeline)
    // ------------------------------------------------------------
    private function importLead(array $parsed): array
    {
        $cols = $this->mapCols($parsed['headers']);
        $created = 0; $updated = 0; $skipped = 0; $errors = [];

        foreach ($parsed['rows'] as $i => $row) {
            $line = $i + 2;
            $name = $this->val($row, $cols, 'name');
            $phone = $this->val($row, $cols, 'phone');
            if (! $name || ! $phone) {
                $errors[] = "Dòng {$line}: thiếu name hoặc phone.";
                $skipped++;
                continue;
            }

            $data = [
                'name' => $name,
                'received_date' => $this->val($row, $cols, 'received_date') ?: now()->toDateString(),
                'source_group' => $this->val($row, $cols, 'source_group'),
                'classification' => $this->val($row, $cols, 'classification') ?: 'new',
                'region' => $this->val($row, $cols, 'region'),
                'note' => $this->val($row, $cols, 'note'),
                'phase' => (int) ($this->val($row, $cols, 'phase') ?? 1),
            ];

            try {
                $existing = Lead::firstWhere('phone', $phone);
                if ($existing) {
                    $existing->update($data);
                    $updated++;
                } else {
                    Lead::create($data + ['phone' => $phone]);
                    $created++;
                }
            } catch (\Throwable $e) {
                $errors[] = "Dòng {$line}: {$e->getMessage()}";
                $skipped++;
            }
        }

        return compact('created', 'updated', 'skipped', 'errors');
    }

    // ------------------------------------------------------------
    // CUSTOM FIELDS (bypass approve — Admin nhập)
    // ------------------------------------------------------------
    private function importField(array $parsed): array
    {
        $cols = $this->mapCols($parsed['headers']);
        $created = 0; $updated = 0; $skipped = 0; $errors = [];

        foreach ($parsed['rows'] as $i => $row) {
            $line = $i + 2;
            $key = $this->val($row, $cols, 'key');
            $label = $this->val($row, $cols, 'label');
            $type = $this->val($row, $cols, 'field_type');
            if (! $key || ! $label || ! $type) {
                $errors[] = "Dòng {$line}: thiếu key/label/field_type.";
                $skipped++;
                continue;
            }
            if (! array_key_exists($type, CustomField::TYPES)) {
                $errors[] = "Dòng {$line}: field_type '{$type}' không hợp lệ (chọn: " . implode(', ', array_keys(CustomField::TYPES)) . ').';
                $skipped++;
                continue;
            }

            $orgCode = $this->val($row, $cols, 'org_unit_code');
            $org = $orgCode ? OrgUnit::firstWhere('code', $orgCode) : null;
            if ($orgCode && ! $org) {
                $errors[] = "Dòng {$line}: org_unit '{$orgCode}' không tồn tại.";
                $skipped++;
                continue;
            }

            $optionsRaw = $this->val($row, $cols, 'options');
            $options = $optionsRaw ? array_values(array_filter(array_map('trim', explode('|', $optionsRaw)))) : null;

            $data = [
                'label' => $label,
                'field_type' => $type,
                'required' => (bool) ($this->val($row, $cols, 'required') ?? '0'),
                'options' => $options,
                'position' => (int) ($this->val($row, $cols, 'position') ?? 0),
                'active' => (bool) ($this->val($row, $cols, 'active') ?? '1'),
            ];

            $existing = CustomField::where('key', $key)->where('org_unit_id', $org?->id)->first();
            if ($existing) {
                $existing->update($data);
                $updated++;
            } else {
                CustomField::create($data + ['key' => $key, 'org_unit_id' => $org?->id]);
                $created++;
            }
        }

        return compact('created', 'updated', 'skipped', 'errors');
    }
}
