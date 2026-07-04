@extends('layouts.app')

@section('title', 'Sơ đồ Tổ chức & Phạm vi Dữ liệu')

@section('content')
    @include('org.partials.tabs', ['active' => 'chart'])
    <livewire:org.org-chart />
@endsection
