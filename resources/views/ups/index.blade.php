@extends('layouts.app')

@section('title', 'UPS System')

@section('content')
    @include('ups._admin_import_export')

    <livewire:ups.ups-board />
@endsection
