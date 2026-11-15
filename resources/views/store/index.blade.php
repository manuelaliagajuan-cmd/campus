@extends('layout')
@section('title','Comprar clases')
@section('content')<div class="eyebrow">{{ __('Sigue aprendiendo con CEC') }}</div><h1>{{ __('COMPRAR CLASES.') }}</h1><p>{{ __('Elige un bono de sesiones o un curso del campus.') }}</p>@if($unavailable)<p class="notice">{{ __('No se ha podido actualizar el catálogo. Vuelve a intentarlo en unos minutos.') }}</p>@elseif(!$enabled)<p class="notice">{{ __('Las compras online estarán disponibles próximamente. Puedes consultar los productos y sus precios.') }}</p>@endif
@if($salesOrigin==='cecalicante.es')<p class="help">{{ __('Compra iniciada en cecalicante.es') }}</p>@endif
<p class="help" id="store-sync-status" role="status"></p>
<div data-store-state="{{ route('store.state') }}" data-store-version="{{ hash('sha256',json_encode($items)) }}" data-store-enabled="{{ $enabled?'1':'0' }}">
@php($passes=collect($items)->where('kind','pass')->values())
@php($courses=collect($items)->where('kind','course')->values())
@if($passes->isNotEmpty())
<section class="panel" style="max-width:760px" aria-labelledby="store-pass-heading">
<h2 id="store-pass-heading">{{ __('Bonos de sesiones') }}</h2>
<label class="field" for="store-pass-select">{{ __('Selecciona un bono') }}<select id="store-pass-select">
@foreach($passes as $pass)<option value="{{ $pass['key'] }}" @selected($selectedProduct===$pass['key'])>{{ $pass['name'] }} — {{ number_format($pass['amount']/100,2,',','.') }} €</option>@endforeach
</select></label>
<div id="store-selected-pass" aria-live="polite">
@foreach($passes as $pass)<article data-pass-option="{{ $pass['key'] }}" {{ $loop->first ? '' : 'hidden' }}>
<h3>{{ $pass['name'] }}</h3><p>{{ $pass['sessions'] }} {{ __('sesiones') }} · {{ $pass['online_enabled']?__('Clases online'):__('Clases presenciales') }}</p><p>{{ $pass['days']?__('Validez: :days días desde la compra',['days'=>$pass['days']]):__('Sin caducidad') }}</p>
<strong class="store-price">{{ number_format($pass['amount']/100,2,',','.') }} €</strong><p class="help">{{ __('Pago único') }}</p><a class="button outline" href="{{ route('store.pass',['type'=>$pass['source_id'],'source_token'=>$sourceToken]) }}">{{ __('Ver horarios y comprar') }}</a>
</article>@endforeach
</div></section>
@endif
@if($courses->isNotEmpty())<h2>{{ __('Cursos del campus') }}</h2><div class="store-grid">
@foreach($courses as $item)<article class="panel store-product"><span class="eyebrow">{{ __('Curso del campus') }}</span><h2>{{ $item['name'] }}</h2><p>{{ Str::limit($item['description'],240) }}</p><strong class="store-price">{{ number_format($item['amount']/100,2,',','.') }} €</strong><p class="help">{{ __('Pago único') }}</p><form method="post" action="{{ route('store.checkout') }}">@csrf<input type="hidden" name="source_token" value="{{ $sourceToken }}"><input type="hidden" name="product" value="{{ $item['key'] }}"><input type="hidden" name="token" value="{{ (string)Str::uuid() }}"><button class="button" @disabled(!$enabled)>{{ __('Comprar') }}</button></form></article>@endforeach
</div>@endif
@if(!$items)<p>{{ __('El catálogo estará disponible próximamente.') }}</p>@endif
</div><p class="help">{{ __('Los métodos de pago disponibles se muestran al continuar al pago seguro. La compra se activa al confirmarse el cobro.') }}</p>
<h2>{{ auth()->user()->role==='admin'?__('Compras del campus'):__('Mis compras') }}</h2>@forelse($orders as $order)<div class="resource"><div><strong>{{ $order->product_name }}</strong>@if(auth()->user()->role==='admin')<p>{{ $order->buyer_name }} · {{ $order->buyer_email }}</p>@endif<p>{{ number_format($order->amount/100,2,',','.') }} € · {{ $order->status==='fulfilled'?__('Compra confirmada'):($order->status==='paid'?__('Pago recibido. Activación pendiente'):__('Pendiente de pago')) }}</p></div><a href="{{ route('store.order',$order) }}">{{ __('Ver compra') }}</a></div>@empty<p>{{ __('Todavía no has realizado compras.') }}</p>@endforelse @endsection

@push('scripts')<script src="{{ asset('assets/store.js') }}?v=3" defer></script>@endpush
