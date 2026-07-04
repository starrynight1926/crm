@extends('layouts.app')

@section('title', 'Quản lý nhân viên & Phân quyền')

@section('content')
    @include('org.partials.tabs', ['active' => 'users'])
    <livewire:org.user-manager />
@endsection
