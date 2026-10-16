<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\NotificationPref;
use App\Models\OrgUnit;
use App\Models\User;
use App\Notifications\GenericEvent;
use App\Support\NotificationEvents;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Gửi notification theo event_key, tôn trọng prefs (role × event × scope).
 *
 * scope quyết định filter recipient khi dùng sendToRoles:
 *   - all      → mọi user thuộc role
 *   - facility → user assignment ở org cấp cơ sở (depth=1) khớp cơ sở của lead
 *   - team     → user assignment ở đúng org_unit của lead
 *   - own      → user là owner_id của lead
 *   - off      → không nhận (không lưu row trong DB, mặc định)
 *
 * Với send() (recipient cụ thể): chỉ check scope != 'off'.
 */
class NotificationDispatcher
{
    /**
     * Gửi tới danh sách user cụ thể (ai là owner được assign, ai là actor cũ, v.v.).
     * Chỉ giữ user có ít nhất 1 role bật event này (scope != off).
     */
    public function send(string $eventKey, iterable $recipients, array $payload): void
    {
        $ids = collect($recipients)
            ->map(fn ($r) => $r instanceof User ? $r->id : (int) $r)
            ->filter()->unique()->values();
        if ($ids->isEmpty()) return;

        $enabledRoleIds = $this->enabledPrefs($eventKey)->keys(); // role_ids có scope != off
        if ($enabledRoleIds->isEmpty()) return;

        User::with('assignments')->whereIn('id', $ids)->get()->each(function (User $u) use ($enabledRoleIds, $eventKey, $payload) {
            $userRoleIds = $u->assignments->pluck('role_id')->unique();
            if ($userRoleIds->intersect($enabledRoleIds)->isEmpty()) return;
            $u->notify(new GenericEvent($eventKey, $payload));
        });
    }

    /**
     * Gửi tới toàn bộ user có role bật event này, filter theo scope + context.
     *
     * @param array $context ['owner_id' => ?int, 'org_unit_id' => ?int]
     */
    public function sendToRoles(string $eventKey, array $payload, array $context = []): void
    {
        $prefs = $this->enabledPrefs($eventKey); // [role_id => scope]
        if ($prefs->isEmpty()) return;

        $ownerId   = $context['owner_id']   ?? null;
        $orgUnitId = $context['org_unit_id'] ?? null;
        $facilityOrgId = $orgUnitId ? $this->facilityAncestorId($orgUnitId) : null;

        // Ánh xạ role_id → set userId thoả scope
        $userIdsToNotify = collect();

        foreach ($prefs as $roleId => $scope) {
            $q = Assignment::query()->where('role_id', $roleId);

            match ($scope) {
                NotificationPref::SCOPE_ALL      => null,
                NotificationPref::SCOPE_FACILITY => $facilityOrgId
                    ? $q->where('org_unit_id', $facilityOrgId)
                    : $q->whereRaw('1=0'),
                NotificationPref::SCOPE_TEAM     => $orgUnitId
                    ? $q->where('org_unit_id', $orgUnitId)
                    : $q->whereRaw('1=0'),
                NotificationPref::SCOPE_OWN      => $ownerId
                    ? $q->where('user_id', $ownerId)
                    : $q->whereRaw('1=0'),
                default => $q->whereRaw('1=0'),
            };

            $userIdsToNotify = $userIdsToNotify->merge($q->pluck('user_id'));
        }

        $userIdsToNotify = $userIdsToNotify->unique()->values();
        if ($userIdsToNotify->isEmpty()) return;

        User::whereIn('id', $userIdsToNotify)->get()->each(
            fn (User $u) => $u->notify(new GenericEvent($eventKey, $payload))
        );
    }

    /** [role_id => scope] cho các role có scope != off. */
    protected function enabledPrefs(string $eventKey): Collection
    {
        // Cache array chứ không cache Collection (serializer đôi khi trả __PHP_Incomplete_Class).
        $rows = Cache::remember(
            "notif_prefs.$eventKey",
            300,
            fn () => NotificationPref::query()
                ->where('event_key', $eventKey)
                ->where('scope', '!=', NotificationPref::SCOPE_OFF)
                ->pluck('scope', 'role_id')
                ->all()
        );
        return collect($rows);
    }

    /**
     * Trả về id OrgUnit cấp "cơ sở" (depth=1) là ancestor của $orgUnitId.
     * Node depth=0 = công ty, depth=1 = cơ sở (HCM/HN/DN).
     */
    protected function facilityAncestorId(int $orgUnitId): ?int
    {
        return Cache::remember("orgunit_facility.$orgUnitId", 3600, function () use ($orgUnitId) {
            $node = OrgUnit::find($orgUnitId);
            if (! $node) return null;
            if ($node->depth === 1) return $node->id;
            // path dạng /1/5/12/ — lấy id đầu tiên sau /1/ (root là depth 0)
            $parts = array_values(array_filter(explode('/', $node->path)));
            return isset($parts[1]) ? (int) $parts[1] : null;
        });
    }

    public static function flushCache(): void
    {
        foreach (NotificationEvents::keys() as $k) {
            Cache::forget("notif_prefs.$k");
        }
    }
}
