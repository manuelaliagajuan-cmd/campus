@extends('layout')
@section('title',__('Mi horario'))
@section('content')
<h1>{{ __('Mi horario') }}</h1><p>{{ __('Consulta tu disponibilidad, las clases asignadas y tus días no laborables.') }}</p>
<div id="teacher-schedule" data-url="{{ route('staff.schedule.data') }}" data-leave-url="{{ route('staff.schedule.leave') }}">
<label class="field">{{ __('Mes') }}<input type="month" id="teacher-schedule-month" value="{{ now('Europe/Madrid')->format('Y-m') }}"></label><p class="help">{{ __('Hora de Alicante (Europe/Madrid)') }}</p><p id="teacher-schedule-status" role="status"></p>
<section class="panel"><h2>{{ __('Disponibilidad semanal') }}</h2><div id="teacher-weekly"></div></section>
<section class="panel"><h2>{{ __('Clases y ausencias del mes') }}</h2><div id="teacher-month-events" class="teacher-schedule-calendar"></div></section>
<section class="panel"><h2>{{ __('Bonos a mi cargo') }}</h2><p>{{ __('Los bonos pendientes de programar no ocupan horas hasta confirmar sus clases.') }}</p><div id="teacher-assigned-passes"></div></section>
<section class="panel"><h2>{{ __('Cursos a mi cargo') }}</h2><div id="teacher-assigned-courses"></div></section>
<section class="panel"><h2>{{ __('Reservar días no laborables') }}</h2><p>{{ __('Si hay clases reservadas, contacta con el centro para reprogramarlas antes de bloquear esos días.') }}</p><form id="teacher-leave-form">@csrf<div class="form-row"><label class="field">{{ __('Desde el día') }}<input type="date" name="date" required min="{{ now('Europe/Madrid')->toDateString() }}"></label><label class="field">{{ __('Hasta el día (incluido)') }}<input type="date" name="endDate" required min="{{ now('Europe/Madrid')->toDateString() }}"></label></div><label class="field">{{ __('Motivo de la ausencia') }}<select name="category"><option value="{{ __('Vacaciones') }}">{{ __('Vacaciones') }}</option><option value="{{ __('Asuntos personales') }}">{{ __('Asuntos personales') }}</option><option value="{{ __('Formación') }}">{{ __('Formación') }}</option><option value="{{ __('Otro motivo') }}">{{ __('Otro motivo') }}</option></select></label><label class="field">{{ __('Detalles (opcional)') }}<textarea name="details" maxlength="900" rows="3"></textarea></label><button class="button">{{ __('Guardar días no laborables') }}</button><p id="teacher-leave-status" role="status"></p></form></section></div>
@endsection
@push('scripts')<script src="{{ asset('assets/teacher-schedule.js') }}?v=1" defer></script>@endpush
