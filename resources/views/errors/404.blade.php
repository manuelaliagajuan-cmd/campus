@extends('layout')
@section('title','404')
@section('content')<div class="eyebrow">404</div><h1>NO ENCONTRAMOS ESTA PÁGINA.</h1><p>Vuelve al campus para continuar.</p><a class="button" href="{{ route('dashboard') }}">Volver al campus</a>@endsection