@php
    $children = $byParent[$node['id']] ?? collect();
    $hasChildren = $children->isNotEmpty();
    $hasMembers = count($node['members']) > 0;
    $nodeKey = 'n' . $node['id'];
    $depthColors = [
        0 => 'bg-gold-600 text-white',
        1 => 'bg-gold-100 text-gold-800 border border-gold-300',
        2 => 'bg-blue-50 text-blue-800 border border-blue-200',
    ];
    $color = $depthColors[$node['depth']] ?? 'bg-gray-50 text-gray-700 border border-gray-200';
@endphp

<div class="{{ $node['depth'] > 0 ? 'ml-6 border-l-2 border-gold-100 pl-4 mt-2' : 'mt-3' }}">
    {{-- Node header --}}
    <div class="flex items-center gap-2 group">
        @if ($hasChildren)
            <button @click="collapsed['{{ $nodeKey }}'] = !collapsed['{{ $nodeKey }}']"
                    class="w-5 h-5 flex items-center justify-center rounded text-ink/40 hover:text-ink/70 hover:bg-gold-50 transition-transform"
                    :class="collapsed['{{ $nodeKey }}'] && '-rotate-90'">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
            </button>
        @else
            <span class="w-5 h-5 flex items-center justify-center text-ink/20">
                <svg class="w-2.5 h-2.5" fill="currentColor" viewBox="0 0 8 8"><circle cx="4" cy="4" r="3"/></svg>
            </span>
        @endif
        <span class="inline-flex items-center gap-1.5 text-sm font-bold px-3 py-1.5 rounded-lg {{ $color }}">
            {{ $node['name'] }}
            @if (!empty($node['managers']))
                <span class="text-xs opacity-80 font-normal">
                    :
                    @foreach ($node['managers'] as $mgr)
                        {{ $mgr['user_name'] }}@if ($mgr['job_title']) ({{ $mgr['job_title'] }})@endif{{ !$loop->last ? ', ' : '' }}
                    @endforeach
                </span>
            @endif
            @if ($hasMembers)
                <span class="text-xs opacity-60 font-normal">({{ count($node['members']) }})</span>
            @endif
        </span>
    </div>

    {{-- Members + children --}}
    <div x-show="!collapsed['{{ $nodeKey }}']" x-cloak>
        @if ($hasMembers)
            <div class="ml-7 mt-2 flex flex-wrap gap-2">
                @foreach ($node['members'] as $member)
                    <span class="inline-flex items-center gap-2 text-sm bg-white border border-gold-100 shadow-sm px-3 py-1.5 rounded-lg hover:border-gold-300 transition-colors">
                        <span class="w-7 h-7 rounded bg-gold-100 text-gold-700 text-xs font-bold flex items-center justify-center">{{ mb_substr($member['user_name'], 0, 1) }}</span>
                        <span>
                            <span class="font-medium">{{ $member['user_name'] }}</span>
                            @if ($member['job_title'])
                                <span class="text-xs text-ink/50 ml-1">{{ $member['job_title'] }}</span>
                            @endif
                            <span class="text-xs text-ink/30 ml-1">{{ $member['role'] }}</span>
                        </span>
                    </span>
                @endforeach
            </div>
        @endif

        @if ($hasChildren)
            @foreach ($children as $child)
                @include('components.org._org-tree-node', ['node' => $child, 'byParent' => $byParent])
            @endforeach
        @endif
    </div>
</div>
