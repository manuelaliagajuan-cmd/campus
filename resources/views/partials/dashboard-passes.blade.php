@if(count($sessionPasses))
<section id="bonos-resumen" class="dashboard-passes" aria-labelledby="dashboard-passes-title">
<div class="section-head"><h2 id="dashboard-passes-title">{{ __('Mis bonos de clases') }}</h2><a href="{{ route('accesses') }}#mis-bonos">{{ __('Ver mis bonos →') }}</a></div>
<div class="dashboard-pass-grid">@foreach($sessionPasses as $pass)
<article class="dashboard-pass {{ $pass['status']==='Activo'?'is-active':($pass['status']==='Caducado'?'is-expired':'is-pending') }}" data-dashboard-pass="{{ $pass['id'] }}">
<div class="dashboard-pass-heading"><h3>{{ $pass['name'] }}</h3><span class="badge">{{ __($pass['status']) }}</span></div>
<p class="dashboard-pass-balance"><strong>{{ $pass['remaining'] }}</strong> {{ __('clases restantes de') }} {{ $pass['sessions'] }}</p>
<p class="small">{{ __('Clases utilizadas') }}: {{ $pass['used'] }} · {{ __('Caducidad') }}: {{ $pass['expires']?\Illuminate\Support\Carbon::parse($pass['expires'])->format('d/m/Y'):__('Sin caducidad') }}</p>
<a href="{{ route('accesses') }}#mis-bonos">{{ __('Ver detalle del bono →') }}</a>
</article>@endforeach</div>
</section>
@endif
