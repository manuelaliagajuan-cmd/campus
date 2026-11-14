@extends('layout')
@section('title','500')
@section('content')<div class="eyebrow">500</div><h1>{{ __('NO HEMOS PODIDO COMPLETAR LA SOLICITUD.') }}</h1><p>{{ __('Inténtalo de nuevo más tarde.') }}</p><a class="button" href="{{ route('dashboard') }}">{{ __('Volver al campus') }}</a>@endsection