@extends('layouts.app')

@section('title', 'UPS hôm nay')

@section('content')
    <livewire:ups.ups-board :read-only="true" />
@endsection
