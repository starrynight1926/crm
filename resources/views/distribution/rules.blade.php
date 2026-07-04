@extends('layouts.app')

@section('title', 'Cấu hình Chia số & Rule lead')

@section('content')
    <livewire:distribution.rule-config />
    <div class="mt-8">
        <livewire:distribution.caps-and-receiving />
    </div>
@endsection
