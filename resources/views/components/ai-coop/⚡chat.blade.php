<?php

use App\Services\ClaudeChatClient;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;

new class extends Component {
    public string $draft = '';
    public string $target = 'both'; // 'a' | 'b' | 'both'
    public string $transcript = '';

    private const FILE = 'ai-coop/transcript.md';

    private const SYSTEM_PROMPT = <<<TXT
Bạn đang ở trong 1 phòng chat 3 bên: 1 user + bạn + 1 AI khác (Claude, tài khoản riêng).
Transcript hiển thị dạng "## [sender] · timestamp\\n\\ncontent\\n---". Sender = user / ai_a / ai_b.
Bạn được gọi tên là AI-A hoặc AI-B (xem đầu prompt hệ thống ghi rõ). AI kia là đối tác cùng làm task.

Rule:
- Trả lời ngắn gọn, đi thẳng vấn đề. Không lặp lại nội dung tin trước.
- Tôn trọng user (ở giữa xử lý). Có thể phản biện AI khác nếu thấy sai.
- Không suy đoán ngoài context. Nếu cần thông tin — hỏi user.
- Bối cảnh mặc định: dev/code chung, cùng làm task + test.
TXT;

    public function mount(): void
    {
        $this->loadTranscript();
    }

    public function loadTranscript(): void
    {
        $this->transcript = Storage::exists(self::FILE)
            ? Storage::get(self::FILE)
            : "# AI-Coop Transcript\n\nRoom bắt đầu " . now()->format('Y-m-d H:i') . ".\n---\n";
    }

    public function send(): void
    {
        $msg = trim($this->draft);
        if ($msg === '') return;

        $this->appendEntry('user', $msg);
        $this->draft = '';
        $this->loadTranscript();

        // Gọi API cho từng AI được tag.
        if (in_array($this->target, ['a', 'both'], true)) {
            $this->askAi('a');
        }
        if (in_array($this->target, ['b', 'both'], true)) {
            $this->askAi('b');
        }
        $this->loadTranscript();
    }

    public function refresh(): void
    {
        $this->loadTranscript();
    }

    public function reset_transcript(): void
    {
        Storage::delete(self::FILE);
        $this->loadTranscript();
    }

    private function askAi(string $side): void
    {
        $envKey = $side === 'a' ? 'AI_COOP_KEY_A' : 'AI_COOP_KEY_B';
        $apiKey = env($envKey) ?? '';
        $tag = $side === 'a' ? 'ai_a' : 'ai_b';
        $selfName = $side === 'a' ? 'AI-A' : 'AI-B';

        if (! $apiKey) {
            $this->appendEntry($tag, "[env {$envKey} chưa có — báo user set]");
            return;
        }

        $sys = self::SYSTEM_PROMPT . "\n\nBạn là {$selfName}.";
        $messages = [
            [
                'role' => 'user',
                'content' => "Đây là transcript hiện tại:\n\n" . Storage::get(self::FILE) . "\n\nHãy phản hồi tin nhắn cuối của user với vai {$selfName}. Chỉ trả nội dung, không lặp lại tên/timestamp.",
            ],
        ];

        $reply = ClaudeChatClient::chat($apiKey, $sys, $messages);
        $this->appendEntry($tag, $reply);
    }

    private function appendEntry(string $sender, string $content): void
    {
        $ts = now()->format('H:i:s');
        $entry = "\n## [{$sender}] · {$ts}\n\n{$content}\n---\n";
        $existing = Storage::exists(self::FILE) ? Storage::get(self::FILE) : "# AI-Coop Transcript\n";
        Storage::put(self::FILE, $existing . $entry);
    }
}; ?>

<div wire:poll.3s="refresh" class="max-w-4xl mx-auto py-6 space-y-4">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-bold">AI-Coop · phòng chat 3 bên</h1>
        <div class="flex items-center gap-2">
            <span class="text-[11px] text-ink/50">Poll mỗi 3s</span>
            <button wire:click="reset_transcript" wire:confirm="Xoá toàn bộ transcript?"
                    class="text-xs text-red-600 border border-red-200 hover:bg-red-50 px-2 py-1 rounded">Reset</button>
        </div>
    </div>

    <div class="bg-white border border-gold-200 rounded-lg p-4 h-[60vh] overflow-y-auto text-sm font-mono whitespace-pre-wrap leading-relaxed">{{ $transcript }}</div>

    <form wire:submit.prevent="send" class="bg-white border border-gold-200 rounded-lg p-4 space-y-3">
        <div class="flex items-center gap-4 text-sm">
            <label class="font-semibold text-ink/70">Gửi cho:</label>
            <label class="inline-flex items-center gap-1.5 cursor-pointer">
                <input type="radio" wire:model="target" value="a"> AI-A
            </label>
            <label class="inline-flex items-center gap-1.5 cursor-pointer">
                <input type="radio" wire:model="target" value="b"> AI-B
            </label>
            <label class="inline-flex items-center gap-1.5 cursor-pointer">
                <input type="radio" wire:model="target" value="both"> Cả 2
            </label>
        </div>
        <textarea wire:model="draft" rows="4" placeholder="Nội dung tin nhắn... (Enter+Shift = xuống dòng)"
                  class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-gold-500"></textarea>
        <div class="flex justify-end">
            <button type="submit"
                    wire:loading.attr="disabled"
                    class="bg-gold-600 hover:bg-gold-700 text-white font-semibold text-sm px-6 py-2 rounded-md disabled:opacity-50">
                <span wire:loading.remove wire:target="send">Gửi</span>
                <span wire:loading wire:target="send">Đang gọi AI…</span>
            </button>
        </div>
    </form>
</div>
