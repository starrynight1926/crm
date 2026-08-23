@extends('layouts.app')
@section('title', 'Ticket #' . ($ticketId ?? ''))
@section('content')
<livewire:support-ticket-detail :ticketId="$ticketId" />
@endsection
