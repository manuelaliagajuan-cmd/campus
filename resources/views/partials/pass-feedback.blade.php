@if(count($feedback))
<section data-survey class="access-section" id="encuestas-bonos" aria-labelledby="encuestas-titulo">
    <label>Idioma / Language <select data-survey-language><option value="es">Español</option><option value="en">English</option></select></label>
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
<script>
(() => {
    const root = document.querySelector('[data-survey]');
    const select = root.querySelector('[data-survey-language]');
    const words = {"Encuesta de satisfacción":"Satisfaction survey","Encuestas de satisfacción":"Satisfaction surveys","Cuéntanos cómo ha ido tu bono. Tus respuestas son voluntarias y las recibe administración.":"Tell us about your lesson package. Taking part is optional. Your answers go to the administration team.","El enlace no es válido o ha caducado. Pide a Administración que te envíe uno nuevo.":"This link is invalid or has expired. Please ask the administration team for a new one.","Gracias por tu opinión. Administración ha recibido tu respuesta.":"Thank you for your feedback. The administration team has received your response.","Ya se ha recibido una respuesta con este enlace.":"A response has already been submitted using this link.","Cuéntanos cómo han ido tus clases. Las respuestas las revisa Administración.":"How were your lessons? The administration team will review your feedback.","Satisfacción general":"Overall satisfaction","Claridad y atención":"Clarity of teaching and individual support","Progreso":"Your progress","Horarios y organización":"Scheduling and organisation","Relación calidad-precio":"Value for money","Elige una puntuación":"Choose a rating","Muy baja":"Very low","Muy alta":"Very high","¿Qué te ha ayudado más y qué podríamos mejorar? (opcional)":"What helped you most, and what could we improve? (optional)","Enviar respuesta":"Submit feedback","El comentario admite hasta 1.500 caracteres.":"Please keep your comment to 1,500 characters.","Selecciona una puntuación en cada pregunta.":"Please choose a rating for each question.","No se pudo guardar la respuesta. Vuelve a intentarlo.":"Your feedback could not be saved. Please try again.","Encuesta enviada":"Feedback submitted","Valorar mis clases":"Rate my lessons","1 = Muy insatisfecho · 5 = Muy satisfecho":"1 = Very dissatisfied · 5 = Very satisfied","Claridad y atención del profesor":"Clarity of teaching and individual support","Progreso hacia tus objetivos":"Progress towards your goals","Selecciona una puntuación":"Choose a rating","¿Qué te ha gustado y qué podríamos mejorar?":"What did you enjoy, and what could we improve?","Enviar a administración":"Send feedback"};
    const texts = [];
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    while (walker.nextNode()) {
        const node = walker.currentNode;
        if (node.parentElement.closest('textarea,script,style')) continue;
        const original = node.data;
        const key = original.trim();
        let english = Object.hasOwn(words, key) ? original.replace(key, words[key]) : original;
        for (const suffix of ['Muy alta','Muy baja','Encuesta enviada','Valorar mis clases']) {
            if (key.endsWith(' · ' + suffix)) english = original.slice(0,original.lastIndexOf(suffix)) + words[suffix];
        }
        if (english !== original) texts.push({node,original,english});
    }
    function apply() {
        root.lang = select.value;
        for (const text of texts) text.node.data = select.value === 'en' ? text.english : text.original;
    }
    try { select.value = sessionStorage.getItem('cec-survey-language') === 'en' ? 'en' : 'es'; } catch {}
    select.addEventListener('change', () => {
        apply();
        try { sessionStorage.setItem('cec-survey-language',select.value); } catch {}
    });
    apply();
})();
</script>
@endif
