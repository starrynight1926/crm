@extends('layouts.app')

@section('title', 'Cập nhật khách hàng')

@section('content')
    <livewire:leads.lead-form :lead="$lead" />

    {{-- 2026-08-04 fix delay: listen callback từ sbooking (approve/checkin/comment/edit/delete) → auto-refresh Livewire. --}}
    <script>
        (function () {
            const leadId = @json($lead->id);
            const wait = setInterval(() => {
                if (typeof window.EchoClient === 'undefined') return;
                clearInterval(wait);
                window.EchoClient.channel('lead.' + leadId)
                    .listen('.App\\Events\\BookingStatusSynced', (e) => {
                        // Toast nhỏ + refresh Livewire component (không reload cả trang).
                        try {
                            if (window.Livewire) window.Livewire.dispatch('refresh-lead', { detail: e });
                        } catch (_) {}
                        const t = document.createElement('div');
                        t.textContent = 'Cập nhật từ sbooking' + (e.type ? ' (' + e.type + ')' : '') + ' — đã đồng bộ.';
                        t.style.cssText = 'position:fixed;bottom:16px;right:16px;z-index:9999;padding:10px 14px;background:#059669;color:#fff;border-radius:8px;box-shadow:0 6px 20px rgba(0,0,0,.15);font-size:13px';
                        document.body.appendChild(t);
                        setTimeout(() => t.remove(), 3500);
                    });
            }, 300);
        })();
    </script>
@endsection
