@if($erp['state']==='connected')
<p class="help">{{ __('Actualizado') }} · {{ \Illuminate\Support\Carbon::parse($erp['updated_at'])->timezone('Europe/Madrid')->format('d/m/Y H:i') }}</p>
<div class="session-passes">@forelse($erp['passes'] as $pass)
<article class="panel session-pass"><div class="section-head"><h3>{{ $pass['name'] }}</h3><span class="badge">{{ __($pass['status']) }}</span></div><p><strong class="pass-balance">{{ $pass['remaining'] }}</strong> {{ __('clases restantes de') }} {{ $pass['sessions'] }}</p><p>{{ __('Clases utilizadas') }}: {{ $pass['used'] }}</p><progress max="{{ max(1,$pass['sessions']) }}" value="{{ min($pass['sessions'],$pass['used']) }}" aria-label="{{ __('Clases utilizadas') }}"></progress><p class="help">{{ __('Inicio') }}: {{ $pass['start'] ?: '—' }} · {{ __('Caducidad') }}: {{ $pass['expires'] ?: __('Sin caducidad') }}</p><p><a href="{{ route('passes.show',$pass['id']) }}">{{ __('Materiales del bono →') }}</a></p></article>
@empty<p class="notice">{{ __('Todavía no tienes bonos asignados.') }}</p>@endforelse</div>
@elseif(!auth()->user()->hasVerifiedEmail() || $erp['state']==='verify')
<div class="notice account-review"><h3>{{ __('Estamos preparando tu acceso') }}</h3><p>{{ __('Estamos revisando tu inscripción. En cuanto el centro confirme tu acceso, aquí aparecerán tus cursos y bonos. No necesitas hacer nada más.') }}</p></div>
@elseif(in_array($erp['state'],['ambiguous','review'],true))
<div class="notice account-review"><h3>{{ __('Estamos preparando tu acceso') }}</h3><p>{{ __('Estamos revisando tu inscripción. En cuanto el centro confirme tu acceso, aquí aparecerán tus cursos y bonos. No necesitas hacer nada más.') }}</p></div>
@elseif($erp['state']==='unassigned')
<p class="notice">{{ __('Todavía no tienes bonos asignados. Los verás aquí cuando estén disponibles.') }}</p>
@else
<p class="notice">{{ __('No podemos mostrar tus bonos en este momento. Vuelve a intentarlo más tarde.') }}</p>@if(auth()->user()->role==='admin')<p>{{ __('Abre Gestión en el navegador donde están tus datos y espera al aviso de guardado conectado con campus.') }}</p><a href="https://gestion.cecalicante.es" target="_blank" rel="noopener">{{ __('Abrir Gestión CEC') }} ↗</a>@endif
@endif
