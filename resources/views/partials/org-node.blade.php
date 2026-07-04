{{-- Node cây tổ chức, render đệ quy. Cần: $node, $memberCounts (từ ⚡org-chart) --}}
<div class="{{ $node->depth > 0 ? 'ml-8 border-l-2 border-gold-100 pl-4' : '' }}">
    <div class="bg-white border {{ $node->active ? 'border-gold-200' : 'border-gold-100 opacity-60' }} rounded-lg px-4 py-3 flex items-center gap-3 shadow-card max-w-3xl">
        <span class="w-9 h-9 rounded-lg bg-gold-50 border border-gold-100 text-gold-600 flex items-center justify-center shrink-0">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21"/>
            </svg>
        </span>

        @if ($editingId === $node->id)
            <input type="text" wire:model="editName" wire:keydown.enter="saveEdit"
                   class="flex-1 border border-gold-300 rounded-md px-3 py-1.5 text-sm focus:outline-none focus:border-gold-500">
            <button wire:click="saveEdit" class="text-sm font-semibold text-gold-700">Lưu</button>
            <button wire:click="cancelEdit" class="text-sm text-ink/50">Hủy</button>
        @else
            <div class="flex-1 min-w-0">
                <div class="font-semibold truncate">
                    {{ $node->name }}
                    @unless ($node->active)
                        <span class="text-[10px] font-semibold uppercase tracking-wider bg-gray-100 text-gray-500 px-2 py-0.5 rounded ml-1">Ngưng hoạt động</span>
                    @endunless
                </div>
                <div class="text-xs text-ink/40">
                    {{ $node->code }} · cấp {{ $node->depth }} · {{ $memberCounts[$node->id] ?? 0 }} nhân sự
                </div>
            </div>
            <div class="flex items-center gap-1 shrink-0">
                <button wire:click="startAdd({{ $node->id }})" class="p-1.5 rounded text-gold-600 hover:bg-gold-50" title="Thêm đơn vị con">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                </button>
                <button wire:click="startEdit({{ $node->id }})" class="p-1.5 rounded text-ink/50 hover:bg-gold-50" title="Đổi tên">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L6.832 19.82a4.5 4.5 0 01-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 011.13-1.897L16.863 4.487z"/></svg>
                </button>
                <button wire:click="toggleActive({{ $node->id }})" class="p-1.5 rounded text-ink/50 hover:bg-gold-50" title="{{ $node->active ? 'Ngưng hoạt động' : 'Kích hoạt lại' }}">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5.636 5.636a9 9 0 1012.728 0M12 3v9"/></svg>
                </button>
                <button wire:click="deleteUnit({{ $node->id }})" wire:confirm="Xóa đơn vị '{{ $node->name }}'?" class="p-1.5 rounded text-red-400 hover:bg-red-50" title="Xóa">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/></svg>
                </button>
            </div>
        @endif
    </div>

    @if ($addingParentId === $node->id)
        <div class="ml-8 mt-2 bg-white border border-gold-300 rounded-lg p-3 flex items-center gap-3 max-w-xl">
            <input type="text" wire:model="newName" wire:keydown.enter="saveAdd" placeholder="Tên đơn vị con của {{ $node->name }}"
                   class="flex-1 border border-gold-200 rounded-md px-3 py-1.5 text-sm focus:outline-none focus:border-gold-500">
            <button wire:click="saveAdd" class="bg-gold-600 text-white text-sm font-semibold px-4 py-1.5 rounded-md">Thêm</button>
            <button wire:click="cancelAdd" class="text-sm text-ink/50 px-1">Hủy</button>
        </div>
        @error('newName')<p class="ml-8 mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    @endif

    @if ($node->children->isNotEmpty())
        <div class="mt-2 space-y-2">
            @foreach ($node->children as $child)
                @include('partials.org-node', ['node' => $child, 'memberCounts' => $memberCounts])
            @endforeach
        </div>
    @endif
</div>
