@extends('layouts.app')

@section('title', 'Duyệt trường')

@section('content')
    <a href="{{ route('settings.index') }}" class="text-sm text-ink/50 hover:text-gold-700">← Thiết lập</a>
    <div class="mt-3"><livewire:org.field-approval /></div>
@endsection
