@extends('layouts.app')

@section('title', 'Trường tùy biến')

@section('content')
    <a href="{{ route('settings.index') }}" class="text-sm text-ink/50 hover:text-gold-700">← Thiết lập</a>
    <div class="mt-3"><livewire:org.field-manager /></div>
@endsection
