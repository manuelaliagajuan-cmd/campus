@extends('layout')
@section('title','403')
@section('content')<div class="eyebrow">403</div><h1>ACCESO RESTRINGIDO.</h1><p>Tu cuenta no tiene acceso a este contenido. Consulta con tu profesor si necesitas una matrícula.</p><a class="button" href="{{ route('dashboard') }}">Volver al campus</a>@endsection