@if($erp['state']==='connected')
<p class="help">{{ __('Actualizado desde Gestión CEC') }} · {{ \Illuminate\Support\Carbon::parse($erp['updated_at'])->timezone('Europe/Madrid')->format('d/m/Y H:i') }}</p>
<div class="session-passes">@forelse($erp['passes'] as $pass)
<article class="panel session-pass"><div class="section-head"><h3>{{ $pass['name'] }}</h3><span class="badge">{{ __($pass['status']) }}</span></div><p><strong class="pass-balance">{{ $pass['remaining'] }}</strong> {{ __('clases restantes de') }} {{ $pass['sessions'] }}</p><p>{{ __('Clases utilizadas') }}: {{ $pass['used'] }}</p><progress max="{{ max(1,$pass['sessions']) }}" value="{{ min($pass['sessions'],$pass['used']) }}" aria-label="{{ __('Clases utilizadas') }}"></progress><p class="help">{{ __('Inicio') }}: {{ $pass['start'] ?: '—' }} · {{ __('Caducidad') }}: {{ $pass['expires'] ?: __('Sin caducidad') }}</p><p><a href="{{ route('passes.show',$pass['id']) }}">{{ __('Materiales del bono →') }}</a></p></article>
@empty<p class="notice">{{ __('No tienes bonos asignados en Gestión CEC.') }}</p>@endforelse</div>
@elseif($erp['state']==='verify')
<div class="notice"><h3>{{ __('Confirma tu correo') }}</h3><p>{{ __('Para conectar los cursos y bonos de Gestión, confirma que este correo te pertenece.') }}</p><form method="post" action="{{ route('verification.send') }}">@csrf<button>{{ in_array(config('mail.default'),['log','array'],true)?__('Cómo confirmar mi correo'):__('Enviar enlace de confirmación') }}</button></form>@if(in_array(config('mail.default'),['log','array'],true))<p>{{ __('El centro puede revisar y vincular tu cuenta desde Nuevos accesos en Gestión.') }}</p>@endif</div>
@elseif(in_array($erp['state'],['ambiguous','review'],true))
<p class="notice">{{ __('Necesitamos revisar tu vinculación con Gestión. Contacta con el centro para comprobar el correo de tu ficha.') }}</p>
@elseif($erp['state']==='unassigned')
<p class="notice">{{ __('No hay una ficha activa de Gestión vinculada a tu correo. Contacta con el centro si ya estás matriculado.') }}</p>
@else
<p class="notice">{{ __('El saldo de tus bonos todavía no está disponible en el campus. Consulta con el centro.') }}</p>@if(auth()->user()->role==='admin')<p>{{ __('Abre Gestión en el navegador donde están tus datos y espera al aviso de guardado conectado con campus.') }}</p><a href="https://gestion.cecalicante.es" target="_blank" rel="noopener">{{ __('Abrir Gestión CEC') }} ↗</a>@endif
@endif
