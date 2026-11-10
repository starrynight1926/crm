<?php

use App\Models\Lead;
use App\Models\LeadContactSnapshot;
use App\Models\LeadContactSnapshotFile;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * B2 (2026-08-14) — "Liên hệ gần nhất" multi-media timeline.
 *
 * Mỗi lượt submit = 1 snapshot: sale hiện tại + note + N ảnh.
 * Timeline hiển thị mỗi snapshot là 1 card riêng biệt (không trộn ảnh giữa các lượt),
 * để trace được lead qua tay 2-3-4 sale sau khi bị thu hồi rồi giao lại.
 */
new class extends Component {
    use WithFileUploads;

    public Lead $lead;
    public array $images = [];
    public string $note = '';

    public function mount(Lead $lead): void
    {
        $this->lead = $lead;
    }

    public function save(): void
    {
        $u = auth()->user();
        abort_unless($this->lead->canLogCall($u), 403); // dùng cùng quyền với ghi call — past owner OK

        $this->validate([
            'images.*' => ['nullable', 'image', 'max:5120'], // 5MB
            'note'     => ['nullable', 'string', 'max:2000'],
        ]);

        if (empty($this->images) && trim($this->note) === '') {
            $this->addError('note', 'Cần ít nhất 1 ảnh hoặc 1 dòng ghi chú.');
            return;
        }

        $snap = LeadContactSnapshot::create([
            'lead_id' => $this->lead->id,
            'user_id' => $u->id,
            'note'    => trim($this->note) ?: null,
        ]);

        foreach ($this->images as $img) {
            $path = $img->store('contact-snapshots/' . $this->lead->id, 'public');
            LeadContactSnapshotFile::create([
                'snapshot_id' => $snap->id,
                'path'        => $path,
                'mime'        => $img->getMimeType(),
                'size_bytes'  => $img->getSize(),
            ]);
        }

        $this->reset(['images', 'note']);
        session()->flash('snapshot_status', 'Đã lưu liên hệ gần nhất.');
    }

    public function delete(int $id): void
    {
        $snap = LeadContactSnapshot::find($id);
        if (! $snap) return;
        // Chỉ chủ snapshot hoặc admin (phase.rollback) mới xóa được.
        abort_unless($snap->user_id === auth()->id() || auth()->user()->hasPermission('phase.rollback'), 403);
        foreach ($snap->files as $f) {
            \Storage::disk('public')->delete($f->path);
        }
        $snap->delete();
    }

    public function with(): array
    {
        return [
            'snapshots' => LeadContactSnapshot::with(['user', 'files'])
                ->where('lead_id', $this->lead->id)
                ->orderByDesc('created_at')->get(),
        ];
    }
};
?>

<div class="bg-white border border-gold-200 rounded-xl shadow-card p-5">
    <h2 class="text-lg font-bold text-gold-700 mb-1">Liên hệ gần nhất</h2>
    <p class="text-xs text-ink/50 mb-4">Ảnh chat/tin nhắn giữa sale ↔ khách. Mỗi lượt upload là 1 khối riêng để trace nhiều sale cùng care.</p>

    @if (session('snapshot_status'))
        <p class="text-xs text-green-700 bg-green-50 border border-green-200 rounded px-3 py-1.5 mb-3">{{ session('snapshot_status') }}</p>
    @endif

    @if ($lead->canLogCall(auth()->user()))
        <form wire:submit.prevent="save" class="border border-dashed border-gold-300 rounded-lg p-3 mb-4 bg-gold-50/30">
            <label class="block text-xs font-semibold text-ink/60 mb-1">Thêm liên hệ mới</label>
            <input type="file" wire:model="images" multiple accept="image/*" class="text-xs mb-2 block">
            @error('images.*') <p class="text-[11px] text-red-600">{{ $message }}</p> @enderror
            <textarea wire:model.defer="note" rows="2" placeholder="Ghi chú (tình trạng, nội dung chat, ...)" class="w-full border border-gold-200 rounded px-2 py-1.5 text-sm mb-2"></textarea>
            @error('note') <p class="text-[11px] text-red-600 mb-1">{{ $message }}</p> @enderror
            <button type="submit" class="text-xs font-semibold bg-gold-600 hover:bg-gold-700 text-white px-3 py-1.5 rounded">
                <span wire:loading.remove wire:target="save">Lưu liên hệ</span>
                <span wire:loading wire:target="save">Đang upload...</span>
            </button>
        </form>
    @endif

    @forelse ($snapshots as $s)
        <div class="border border-gold-100 rounded-lg p-3 mb-3 bg-white">
            <div class="flex items-center justify-between mb-2">
                <div class="text-sm">
                    <span class="font-semibold text-gold-700">{{ $s->user->name ?? '#'.$s->user_id }}</span>
                    <span class="text-xs text-ink/50 ml-1">· {{ $s->created_at->format('d/m/Y H:i') }}</span>
                </div>
                @if ($s->user_id === auth()->id() || auth()->user()->hasPermission('phase.rollback'))
                    <button wire:click="delete({{ $s->id }})" wire:confirm="Xóa liên hệ này?" class="text-[11px] text-red-600 hover:underline">Xóa</button>
                @endif
            </div>
            @if ($s->note)
                <p class="text-sm text-ink/80 mb-2 whitespace-pre-wrap">{{ $s->note }}</p>
            @endif
            @if ($s->files->count())
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2">
                    @foreach ($s->files as $f)
                        <a href="{{ $f->url() }}" target="_blank" class="block aspect-square bg-slate-100 rounded overflow-hidden">
                            <img src="{{ $f->url() }}" alt="" class="w-full h-full object-cover hover:opacity-90 transition">
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    @empty
        <p class="text-xs text-ink/40 italic text-center py-6">Chưa có liên hệ nào được ghi.</p>
    @endforelse
</div>
