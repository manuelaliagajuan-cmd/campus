<section class="presentation-viewer" data-presentation-viewer aria-label="{{ __('Presentación interactiva') }}">
@foreach(\App\Services\Presentation::display($lesson) as $slide)<article class="presentation-slide" data-slide-index="{{ $loop->index }}" @if(!$loop->first) hidden @endif><span class="eyebrow">{{ __('Diapositiva') }} {{ $loop->iteration }}</span>@if($slide['title'])<h2>{{ $slide['title'] }}</h2>@endif
@foreach($slide['blocks'] as $block)<div class="presentation-block">@switch($block['type'])
@case('text')<div class="lesson-copy">{{ $block['body'] }}</div>@break
@case('image')@if($block['resource'])<img src="{{ route('resources.file',['resource'=>$block['resource'],'preview'=>1]) }}" alt="{{ $block['resource']->title }}" loading="lazy">@endif @break
@case('video')<div class="video-box"><iframe src="https://www.youtube-nocookie.com/embed/{{ $block['youtube_id'] }}" title="{{ $slide['title']?:__('Vídeo de la diapositiva') }}" loading="lazy" allow="encrypted-media; picture-in-picture; fullscreen" allowfullscreen></iframe></div>@break
@case('activity')@if($block['activity'])<iframe class="slide-activity-frame" src="{{ route('activities.overview',['activity'=>$block['activity'],'leccion'=>$lesson->id,'embed'=>1]) }}" title="{{ $block['activity']->title }}" loading="lazy"></iframe>@else<p class="notice">{{ __('Esta actividad todavía no está disponible.') }}</p>@endif @break
@endswitch</div>@endforeach</article>@endforeach
<div class="presentation-navigation"><button type="button" data-slide-previous>{{ __('Anterior') }}</button><span data-slide-counter aria-live="polite"></span><button type="button" data-slide-next>{{ __('Siguiente') }}</button></div><progress data-slide-progress aria-label="{{ __('Progreso de la presentación') }}"></progress></section>
@push('scripts')<script src="{{ asset('assets/presentations.js') }}?v=1" defer></script>@endpush
