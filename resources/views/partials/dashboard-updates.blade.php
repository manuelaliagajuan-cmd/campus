@if(count($rooms))
<section class="class-preview dashboard-next-class" data-schedule-state="{{ route('online.schedule-state') }}" data-revision="{{ $sourceRevision??'' }}"><div class="section-head"><h2>{{ \App\Services\ScheduledClasses::heading($upcoming[0]??null) }}</h2><a href="{{ route('online.index') }}">{{ __('Clases online') }} →</a></div>
@if(count($upcoming))
@include('partials.scheduled-class-card',['entry'=>$upcoming[0],'featured'=>true])
@else
<p>{{ __('Todavía no tienes clases online programadas. El centro confirmará contigo el próximo horario.') }}</p>
@endif
<p class="help schedule-sync-state" role="status">{{ __('Los cambios de horario confirmados por el centro se actualizan aquí automáticamente.') }}</p></section>
@endif
@if($dashboardNotices->isNotEmpty())
<section class="dashboard-notices"><div class="section-head"><h2>{{ __('Notificaciones') }}</h2><a href="{{ route('notifications') }}">{{ __('TUS NOTIFICACIONES.') }} →</a></div>
@foreach($dashboardNotices as $notice)<article class="notice-card notice-unread"><span class="eyebrow">{{ $notice->course->title }}</span><h3>{{ $notice->title }}</h3><p>{{ Str::limit($notice->body,280) }}</p><a href="{{ route('courses.show',$notice->course) }}">{{ __('Ver curso →') }}</a></article>@endforeach
</section>
@endif
