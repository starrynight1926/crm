@extends('layouts.app')

@section('title', 'Tổng quan')

@section('content')
    @php
        abort_unless(auth()->user()?->hasRole('Observer'), 403, 'Chỉ role Observer được xem trang này.');
    @endphp
    <div class="max-w-screen-2xl mx-auto p-4 md:p-6">
        <livewire:reports.report-charts :scoped="true" />
    </div>
@endsection
