@extends('layouts.app')

@section('title', 'Danh sách API v1')

@php
    // Manually curated — thứ tự khớp Phase A/B/C/D. Update khi có endpoint mới.
    $groups = [
        [
            'title'    => 'SCRM — dữ liệu SCRM',
            'base'     => rtrim(config('app.url'), '/') . '/api/v1',
            'accent'   => 'blue',
            'sections' => [
                'Phase A' => [
                    ['GET',    '/users',                            'List users (filter, sort, paginate)'],
                    ['GET',    '/users/{id}',                       'Xem user'],
                    ['POST',   '/users',                            'Tạo user'],
                    ['PATCH',  '/users/{id}',                       'Sửa user'],
                    ['DELETE', '/users/{id}',                       'Lock user (status=locked)'],
                    ['GET',    '/facilities',                       'List facilities'],
                    ['GET',    '/facilities/{id}',                  'Xem facility'],
                    ['POST',   '/facilities',                       'Tạo facility'],
                    ['PATCH',  '/facilities/{id}',                  'Sửa facility'],
                    ['DELETE', '/facilities/{id}',                  'Xoá facility (chặn nếu còn con)'],
                ],
                'Phase B' => [
                    ['GET',    '/org-units',                        'List org units'],
                    ['GET',    '/org-units/tree',                   'Trả full cây org tree'],
                    ['POST',   '/org-units',                        'Tạo org unit'],
                    ['PATCH',  '/org-units/{id}',                   'Sửa org unit'],
                    ['DELETE', '/org-units/{id}',                   'Xoá (chặn nếu còn con)'],
                    ['POST',   '/org-units/{id}/move',              'Move parent + position'],
                    ['GET',    '/users/{user}/assignments',         'List assignments của user'],
                    ['POST',   '/users/{user}/assignments',         'Gán role + org_unit + scope'],
                    ['PATCH',  '/users/{user}/assignments/{id}',    'Sửa assignment'],
                    ['DELETE', '/users/{user}/assignments/{id}',    'Xoá assignment'],
                ],
                'Phase C' => [
                    ['GET',    '/leads',                            'List leads (filter, from/to)'],
                    ['GET',    '/leads/export',                     'Aggregate JSON — group=day|week|month|year'],
                    ['GET',    '/leads/{id}',                       'Xem lead'],
                    ['POST',   '/leads',                            'Tạo lead'],
                    ['PATCH',  '/leads/{id}',                       'Sửa lead'],
                    ['DELETE', '/leads/{id}',                       'Soft-delete lead'],
                    ['GET',    '/booking-logs',                     'List BL (filter lead_id, sync_status, from/to)'],
                    ['GET',    '/booking-logs/export',              'Aggregate JSON'],
                    ['GET',    '/booking-logs/{id}',                'Xem BL'],
                    ['POST',   '/booking-logs',                     'Tạo BL (không auto sync)'],
                    ['PATCH',  '/booking-logs/{id}',                'Sửa BL'],
                    ['DELETE', '/booking-logs/{id}',                'Xoá BL'],
                    ['POST',   '/booking-logs/{id}/push',           'Force retry SbookingClient::pushBooking'],
                ],
                'Phase D — Debug' => [
                    ['GET',    '/audit-logs',                       'Log mọi write op — filter path/method/status'],
                    ['GET',    '/inspect/booking-log/{id}',         'BL + lead + owner + facility + consultants + sync + audit history — 1 call'],
                    ['GET',    '/inspect/lead/{id}',                'Lead + owner + org + facility + call_logs + booking_logs + ownership + status_logs — 1 call'],
                ],
            ],
        ],
        [
            'title'    => 'SBooking — dữ liệu Booking',
            'base'     => rtrim(config('services.booking.url') ?: '', '/') . '/api/v1',
            'accent'   => 'emerald',
            'sections' => [
                'Phase A' => [
                    ['GET',    '/users',                       'List users SB'],
                    ['GET',    '/users/{id}',                  'Xem user'],
                    ['POST',   '/users',                       'Tạo user'],
                    ['PATCH',  '/users/{id}',                  'Sửa user'],
                    ['DELETE', '/users/{id}',                  'Xoá user'],
                    ['PATCH',  '/users/{id}/move',             'Đổi co_so_id nhanh (case Quỳnh HCM→HN)'],
                    ['GET',    '/phong-ban',                   'CRUD phòng ban'],
                    ['GET',    '/co-so',                       'CRUD cơ sở'],
                    ['GET',    '/bac-si',                      'CRUD bác sĩ'],
                    ['POST',   '/bac-si/{id}/attach-phong',    'Attach phòng vào BS'],
                    ['POST',   '/bac-si/{id}/detach-phong',    'Detach phòng khỏi BS'],
                ],
                'Phase B' => [
                    ['GET',    '/phong',                       'CRUD phòng'],
                    ['POST',   '/phong/{id}/attach-bac-si',    'Attach BS vào phòng'],
                    ['POST',   '/phong/{id}/detach-bac-si',    'Detach BS khỏi phòng'],
                    ['GET',    '/dich-vu',                     'CRUD dịch vụ'],
                ],
                'Phase C' => [
                    ['GET',    '/lich-lam-viec',               'List lịch tháng (filter co_so_id/trang_thai/month)'],
                    ['GET',    '/lich-lam-viec/{id}?with_chi_tiet=1', 'Kèm chi tiết'],
                    ['POST',   '/lich-lam-viec',               'Tạo lịch tháng'],
                    ['PATCH',  '/lich-lam-viec/{id}',          'Duyệt/từ chối/note'],
                    ['DELETE', '/lich-lam-viec/{id}',          'Xoá lịch (cascade chi tiết)'],
                    ['GET',    '/lich-lam-viec/{id}/chi-tiet', 'List chi tiết + filter'],
                    ['POST',   '/lich-lam-viec/{id}/chi-tiet', 'Bulk add chi tiết (chunk 500)'],
                    ['DELETE', '/lich-lam-viec/{id}/chi-tiet/{ct}', 'Xoá 1 chi tiết'],
                    ['GET',    '/bookings',                    'List booking (filter, from/to)'],
                    ['GET',    '/bookings/export',             'Aggregate JSON theo period'],
                    ['POST',   '/bookings',                    'Tạo booking (v1 chuẩn, song song /api/bookings cũ)'],
                    ['PATCH',  '/bookings/{id}',               'Sửa booking'],
                    ['DELETE', '/bookings/{id}',               'Xoá booking'],
                ],
                'Phase D — Debug' => [
                    ['GET',    '/audit-logs',                  'Log write op'],
                    ['GET',    '/inspect/booking/{id}',        'Booking + khách + phòng + BS + DV + tiếp đón + comments + audit — 1 call'],
                ],
            ],
        ],
    ];

    $methodBadge = [
        'GET'    => 'bg-emerald-100 text-emerald-800',
        'POST'   => 'bg-blue-100 text-blue-800',
        'PATCH'  => 'bg-amber-100 text-amber-800',
        'PUT'    => 'bg-amber-100 text-amber-800',
        'DELETE' => 'bg-rose-100 text-rose-800',
    ];
@endphp

@section('content')
<div class="max-w-6xl mx-auto" x-data="{ q: '' }">
    <div class="flex items-center gap-3 mb-1">
        <svg class="w-7 h-7 text-gold-600" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17.25 6.75L22.5 12l-5.25 5.25m-10.5 0L1.5 12l5.25-5.25m7.5-3l-4.5 16.5"/></svg>
        <h1 class="text-3xl font-bold">API v1 — endpoint list</h1>
    </div>
    <p class="text-sm text-ink/60 mb-5">
        Bearer <code>BOOKING_API_TOKEN</code> (server-to-server), rate 60 req/min/token
        (env <code>API_V1_RATE_LIMIT=0</code> tắt cho bulk). Response chuẩn <code>{data, meta}</code>.
        Filter nested: <code>?filter[co_so_id]=1&sort=-created_at&per_page=50</code>.
    </p>

    <input type="text" x-model="q" placeholder="Tìm endpoint (path / mô tả)…"
        class="w-full mb-5 px-4 py-2.5 text-sm border border-gold-200 rounded-lg focus:outline-none focus:border-gold-500">

    @foreach ($groups as $g)
        <section class="mb-8">
            <div class="flex items-baseline gap-3 mb-3">
                <h2 class="text-xl font-bold text-{{ $g['accent'] }}-700">{{ $g['title'] }}</h2>
                <code class="text-xs text-ink/50">{{ $g['base'] }}</code>
            </div>
            @foreach ($g['sections'] as $phase => $rows)
                <div class="mb-4">
                    <div class="text-xs font-semibold text-ink/50 uppercase tracking-wider mb-2">{{ $phase }}</div>
                    <div class="bg-white border border-gold-200 rounded-xl overflow-hidden divide-y divide-gold-100">
                        @foreach ($rows as [$m, $p, $d])
                            <div class="flex items-start gap-3 px-4 py-2 text-sm hover:bg-gold-50/40"
                                 x-show="!q || '{{ strtolower($p . ' ' . $d) }}'.includes(q.toLowerCase())">
                                <span class="shrink-0 text-[10px] font-bold px-2 py-0.5 rounded {{ $methodBadge[$m] }}" style="min-width: 3rem; text-align: center;">{{ $m }}</span>
                                <code class="shrink-0 text-ink font-mono text-[13px]">{{ $p }}</code>
                                <span class="text-ink/60 flex-1 min-w-0">{{ $d }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </section>
    @endforeach

    <div class="mt-8 bg-gold-50 border border-gold-200 rounded-xl p-5 text-sm">
        <div class="font-semibold text-ink mb-2">Python SDK — <code>py-test-booking/scenarios/sb_api.py</code></div>
        <pre class="text-xs bg-white/70 rounded p-3 overflow-x-auto"><code>from scenarios.sb_api import sb, scrm

# Query
sb.users.list(filter={'co_so_id': 3})
scrm.leads.list(filter={'facility_id': 6}, per_page=50)

# Debug — 1 call trả full context
scrm.inspect.booking_log(30)
scrm.inspect.lead(786)
sb.inspect.booking(21)

# Audit
scrm.audit_logs.list(filter={'path': 'booking-logs'})

# Mutation
sb.users.move(74, co_so_id=1)
scrm.booking_logs.push(30)
scrm.leads.export(from_='2026-08-01', to='2026-08-31', group='day')</code></pre>
    </div>
</div>
@endsection
