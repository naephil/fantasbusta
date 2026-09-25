@props(['daRivedere' => null])

{{--
    Il palco dell'apertura: tutto ciò che serve perché una busta possa andare in
    scena su questa pagina.

    Sta in un componente perché i posti da cui si sbusta sono due e non uno. C'è
    il draft in corso, dove la busta si apre adesso, e c'è la pagina che dice
    «nessuna busta all'orizzonte» — dove ci si ritrova proprio nel caso che
    conta, cioè a draft finito, con le carte già in rosa e l'apertura andata in
    scena per nessuno. Lasciare l'invito solo sulla prima significava non
    mostrarlo mai a chi arriva a giochi fatti.

    L'overlay e il lampo stanno fuori dal blocco: servono anche quando non c'è
    niente da rivedere, perché è lì dentro che si apre la busta vera. Sono
    entrambi `position: fixed`, quindi il punto del documento in cui compaiono
    non conta.
--}}

@if ($daRivedere?->isNotEmpty())
    {{-- La giornata si legge dal primo turno: sono tutti dello stesso draft,
         e dirla serve perché la busta da recuperare può benissimo essere di
         una giornata già chiusa mentre il draft aperto è quello dopo. --}}
    @php($giornata = $daRivedere->first()->draft->matchday)

    <section class="mb-8 border border-rosso bg-ink-2 p-6">
        <p class="eyebrow mb-3 text-rosso">
            {{ $daRivedere->count() === 1 ? 'Una busta aperta senza di te' : $daRivedere->count().' buste aperte senza di te' }}
            · giornata {{ $giornata }}
        </p>

        <p class="mb-5 max-w-xl text-sm leading-relaxed text-paper-dim">
            Il turno è passato mentre non c'eri — scaduto, o sbustato in automatico. Le carte
            sono già in rosa e sono le stesse: di quello che hai non cambia niente. L'unica cosa
            che ti sei perso è <span class="text-paper">vederle uscire</span>, e quella si
            recupera adesso.
        </p>

        <div class="flex flex-wrap gap-3">
            @foreach ($daRivedere as $turno)
                <button data-rivedi="{{ route('draft.rivedi', $turno) }}"
                        class="border border-rosso px-5 py-2.5 font-display text-xl font-black uppercase tracking-wide text-rosso transition hover:bg-rosso hover:text-paper disabled:cursor-wait disabled:opacity-60">
                    Busta {{ $turno->round }} · {{ $turno->cards_count }} carte
                </button>
            @endforeach
        </div>
    </section>
@endif

<div class="overlay" data-overlay></div>
<div class="flash" data-flash></div>
