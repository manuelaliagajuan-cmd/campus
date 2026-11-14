@extends('layout')
@section('title','419')
@section('content')<div class="eyebrow">419</div><h1>{{ __('TU SESIÓN HA CADUCADO.') }}</h1><p>{{ __('Vuelve a abrir el formulario e inténtalo de nuevo.') }}</p><a class="button" href="{{ route('dashboard') }}">{{ __('Volver al campus') }}</a>@endsection