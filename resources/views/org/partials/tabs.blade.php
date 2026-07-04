{{-- Tab điều hướng khu Tổ chức. Cần: $active (users|roles|chart) --}}
<div class="border-b border-gold-200 mb-7 flex gap-1 text-sm font-semibold uppercase tracking-wide overflow-x-auto -mx-4 px-4 md:mx-0 md:px-0">
    @foreach ([
        'users' => ['label' => 'Danh sách nhân viên', 'route' => 'org.users', 'permission' => 'user.manage'],
        'roles' => ['label' => 'Thiết lập vai trò', 'route' => 'org.roles', 'permission' => 'role.manage'],
        'chart' => ['label' => 'Sơ đồ tổ chức', 'route' => 'org.chart', 'permission' => 'org.manage'],
        'fields' => ['label' => 'Trường tùy biến', 'route' => 'org.fields', 'permission' => 'field.manage'],
    ] as $key => $tab)
        @if (auth()->user()->hasPermission($tab['permission']))
            <a href="{{ route($tab['route']) }}"
               class="px-4 py-3 border-b-2 -mb-px whitespace-nowrap shrink-0 {{ $active === $key ? 'border-gold-600 text-gold-700' : 'border-transparent text-ink/50 hover:text-gold-700' }}">
                {{ $tab['label'] }}
            </a>
        @endif
    @endforeach
</div>
