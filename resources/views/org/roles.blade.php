@extends('layouts.app')

@section('title', 'Thiết lập vai trò & Quyền hạn')

@section('content')
    @include('org.partials.tabs', ['active' => 'roles'])
    <livewire:org.role-manager />
@endsection
