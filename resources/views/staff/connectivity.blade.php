@extends('layout')
@section('title',__('Conexión del aula'))
@section('content')
<div class="eyebrow">{{ __('Administración') }}</div><h1>{{ __('Conexión del aula') }}</h1>
<section class="panel"><h2>{{ $configured?__('Servicio configurado'):__('Falta activar el servicio de conexión') }}</h2><p>{{ __('Este servicio permite conectar el audio y el vídeo entre redes distintas, incluidas redes móviles y redes que bloquean conexiones directas.') }}</p>
<ol><li>{{ __('Crea una cuenta en Cloudflare y abre Realtime → TURN.') }} <a href="https://dash.cloudflare.com/sign-up" target="_blank" rel="noopener">Cloudflare ↗</a></li><li>{{ __('Crea una clave TURN y copia su identificador y su token API en este formulario.') }}</li><li>{{ __('Pulsa verificar y guardar. Después vuelve a entrar al aula desde ambos dispositivos.') }}</li></ol>
<p class="help">{{ __('Revisa el consumo y las condiciones del proveedor. No es necesario cambiar el dominio ni trasladar la web.') }} <a href="https://developers.cloudflare.com/realtime/turn/faq/" target="_blank" rel="noopener">{{ __('Información del proveedor') }} ↗</a></p>
<form method="post" action="{{ route('connectivity.update') }}" autocomplete="off">@csrf<label class="field">{{ __('Identificador de la clave TURN') }}<input name="turn_key" required maxlength="128" autocomplete="off"></label><label class="field">{{ __('Token API de TURN') }}<input type="password" name="turn_token" required minlength="20" maxlength="2000" autocomplete="new-password"></label><p class="help">{{ __('Las claves se guardan cifradas en el servidor. No se muestran a los alumnos ni vuelven a aparecer en este formulario.') }}</p><button type="submit">{{ __('Verificar y guardar') }}</button></form><hr><button type="button" class="button outline" id="rtc-test-route" data-url="{{ route('connectivity.test') }}">{{ __('Comprobar ruta de vídeo') }}</button><p id="rtc-test-result" role="status"></p></section>
@endsection

@push('scripts')<script src="{{ asset('assets/rtc-connectivity-check.js') }}?v=1" defer></script>@endpush
