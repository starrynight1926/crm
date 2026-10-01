@extends('layouts.app')

@section('title', 'Cấu hình Chia số')

@php $tab = request()->query('tab') === 'auto' ? 'auto' : 'manual'; @endphp

@section('content')
    {{-- Tabs: Chia thủ công | Chia tự động --}}
    <div class="border-b border-gold-200 mb-6 flex gap-1 text-sm font-semibold uppercase tracking-wide">
        <a href="{{ route('distribution.rules', ['tab' => 'manual']) }}"
           class="px-4 py-3 border-b-2 -mb-px {{ $tab === 'manual' ? 'border-gold-600 text-gold-700' : 'border-transparent text-ink/50 hover:text-gold-700' }}">
            Chia thủ công
        </a>
        <a href="{{ route('distribution.rules', ['tab' => 'auto']) }}"
           class="px-4 py-3 border-b-2 -mb-px {{ $tab === 'auto' ? 'border-gold-600 text-gold-700' : 'border-transparent text-ink/50 hover:text-gold-700' }}">
            Chia tự động
        </a>
    </div>

    @if ($tab === 'auto')
        <livewire:distribution.rule-config />
        <div class="mt-8">
            <livewire:distribution.caps-and-receiving />
        </div>
    @else
        <livewire:distribution.lead-pools />
    @endif
@endsection
