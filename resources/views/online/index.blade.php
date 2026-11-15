@extends('layout')
@section('title','Clases online')
@section('content')
<section class="class-preview" data-schedule-state="{{ route('online.schedule-state') }}" data-revision="{{ $sourceRevision??'' }}"><header class="class-preview-heading"><div><p>{{ __('Hola, :name',['name'=>auth()->user()->name]) }}</p><h1>{{ \App\Services\ScheduledClasses::heading($upcoming[0]??null) }}</h1></div>@if(auth()->user()->isStaff())<a class="button outline" href="https://gestion.cecalicante.es/" target="_blank" rel="noopener">{{ __('Programar en Gestión') }}</a>@endif</header>
@if(count($upcoming))@include('partials.scheduled-class-card',['entry'=>$upcoming[0],'featured'=>true])@else<div class="panel"><h2>{{ __('Próxima clase') }}</h2><p>{{ __('Todavía no tienes clases online programadas. El centro confirmará contigo el próximo horario.') }}</p></div>@endif</section>
@if(count($upcoming)>1)<section class="scheduled-next"><h2>{{ __('Próximas clases') }}</h2>@foreach(array_slice($upcoming,1) as $entry)@include('partials.scheduled-class-card',['entry'=>$entry,'featured'=>false])@endforeach</section>@endif
<p class="help schedule-sync-state" role="status">{{ __('Los cambios de horario confirmados por el centro se actualizan aquí automáticamente.') }}</p>
@foreach($rooms as $room)
@if(!isset($room['pass'])||!array_key_exists('bookings',$room['pass']))<section class="panel"><h2>{{ $room['title'] }}</h2>@php($active=$sessions->first(fn($s)=>$s->scope===$room['key']&&!$s->ended_at))@if($active)<a class="button" href="{{ route('online.show',$active) }}">{{ __('Entrar a la clase') }}</a>@elseif($room['manage'])<form method="post" action="{{ route('online.start') }}">@csrf<input type="hidden" name="scope" value="{{ $room['key'] }}"><button>{{ __('Iniciar clase online') }}</button></form>@else<p class="notice">{{ __('Tu profesor todavía no ha iniciado la clase.') }}</p>@endif</section>@endif
@endforeach
<h2>{{ __('Sesiones finalizadas') }}</h2>@forelse($sessions->whereNotNull('ended_at') as $s)<div class="resource"><div><strong>{{ $s->title }}</strong><p>{{ $s->ended_at->timezone('Europe/Madrid')->format('d/m/Y H:i') }} @if(isset($ratings[$s->id])) · {{ $ratings[$s->id] }} / 5 ★ @endif</p></div><a href="{{ route('online.show',$s) }}">{{ __('Ver sesión y valoración') }}</a></div>@empty<p>{{ __('Todavía no hay sesiones finalizadas.') }}</p>@endforelse
@endsection
@push('scripts')<script src="{{ asset('assets/class-schedule.js') }}?v=1" defer></script>@endpush
