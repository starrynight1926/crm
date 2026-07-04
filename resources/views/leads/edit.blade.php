@extends('layouts.app')

@section('title', 'Cập nhật khách hàng')

@section('content')
    <livewire:leads.lead-form :lead="$lead" />
@endsection
