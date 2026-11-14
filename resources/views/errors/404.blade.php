@extends('layout')
@section('title','404')
@section('content')<div class="eyebrow">404</div><h1>{{ __('NO ENCONTRAMOS ESTA PÁGINA.') }}</h1><p>{{ __('Vuelve al campus para continuar.') }}</p><a class="button" href="{{ route('dashboard') }}">{{ __('Volver al campus') }}</a>@endsection