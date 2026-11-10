@extends('layouts.app')

@section('title', 'UPS hôm nay')

@section('content')
    @include('ups._admin_import_export')

    {{-- Ai có ups.confirm_daily (admin cơ sở + super admin) thao tác được ngay; user khác vẫn read-only. --}}
    <livewire:ups.ups-board :read-only="! auth()->user()?->hasPermission('ups.confirm_daily')" />
@endsection
