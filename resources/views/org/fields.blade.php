@extends('layouts.app')

@section('title', 'Trường tùy biến theo phòng ban')

@section('content')
    @include('org.partials.tabs', ['active' => 'fields'])
    <livewire:org.field-manager />
@endsection
