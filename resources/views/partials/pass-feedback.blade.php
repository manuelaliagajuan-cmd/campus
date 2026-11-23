@if(count($feedback))
<section class="access-section" id="encuestas-bonos" aria-labelledby="encuestas-titulo">
    <h2 id="encuestas-titulo">Encuestas de satisfacción</h2>
    <p>Cuéntanos cómo ha ido tu bono. Tus respuestas son voluntarias y las recibe administración.</p>
    @foreach($feedback as $pass)
        <details class="panel" @if(old('pass') === $pass['id'] && $errors->any()) open @endif>
            <summary>{{ $pass['name'] }} · {{ $pass['submitted'] ? 'Encuesta enviada' : 'Valorar mis clases' }}</summary>
            @unless($pass['submitted'])
                <form method="post" action="{{ route('accesses.feedback') }}">
                    @csrf
                    <input type="hidden" name="pass" value="{{ $pass['id'] }}">
                    <p>1 = Muy insatisfecho · 5 = Muy satisfecho</p>
                    @foreach(['overall'=>'Satisfacción general','teaching'=>'Claridad y atención del profesor','progress'=>'Progreso hacia tus objetivos','schedule'=>'Horarios y organización','value'=>'Relación calidad-precio'] as $key => $label)
                        <p><label>{{ $label }}
                            <select name="{{ $key }}" required>
                                <option value="">Selecciona una puntuación</option>
                                @foreach(range(1,5) as $score)
                                    <option value="{{ $score }}" @selected(old('pass') === $pass['id'] && (string) old($key) === (string) $score)>{{ $score }}</option>
                                @endforeach
                            </select>
                        </label></p>
                    @endforeach
                    <p><label>¿Qué te ha gustado y qué podríamos mejorar?
                        <textarea name="comment" rows="4" maxlength="1500">{{ old('pass') === $pass['id'] ? old('comment') : '' }}</textarea>
                    </label></p>
                    <button class="button" type="submit">Enviar a administración</button>
                </form>
            @endunless
        </details>
    @endforeach
</section>
@endif
