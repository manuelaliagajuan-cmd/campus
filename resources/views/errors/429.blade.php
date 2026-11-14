@extends('layout')
@section('title','429')
@section('content')<div class="eyebrow">429</div><h1>{{ __('UN MOMENTO, POR FAVOR.') }}</h1><p>{{ __('Has realizado varios intentos. Espera un minuto antes de continuar.') }}</p><a class="button" href="{{ route('dashboard') }}">{{ __('Volver al campus') }}</a>@endsection