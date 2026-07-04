@extends('layouts.app')

@section('title', 'Chi tiết khách hàng')

@section('content')
    <livewire:leads.lead-detail :lead="$lead" />
    <div class="mt-6">
        <livewire:leads.lead-services :lead="$lead" />
    </div>
@endsection
