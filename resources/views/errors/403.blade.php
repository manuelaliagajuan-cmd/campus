@extends('layout')
@section('title','403')
@section('content')<div class="eyebrow">403</div><h1>{{ __('ACCESO RESTRINGIDO.') }}</h1><p>{{ __('Tu cuenta no tiene acceso a este contenido. Consulta con tu profesor si necesitas una matrícula.') }}</p><a class="button" href="{{ route('dashboard') }}">{{ __('Volver al campus') }}</a>@endsection