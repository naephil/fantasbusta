@props(['stat' => null, 'eventi' => null, 'ruolo' => null])

{{--
    I segnalini di giornata: cosa ha fatto, non solo quanto ha preso.

    Senza, un fantavoto è un numero e basta — un 9,5 non dice se è un 6 in
    pagella con tre gol o un 9 asciutto, e sono due giornate molto diverse. Coi
    segnalini il tabellino si legge come una moviola invece che come una colonna
    di cifre.

    Gli eventi vengono da `player_stats`, che sono FATTI dell'annata: i gol sono
    gli stessi per tutti i gruppi, mentre il fantavoto dipende dai coefficienti
    di lega. Per questo stanno accanto al voto e non dentro.
--}}
@php
    // Si accetta il modello o già i conteggi: la giornata di Serie A costruisce
    // le righe a mano per non caricare cinquecento modelli, il tabellino della
    // sfida i modelli ce li ha. I segnalini restano gli stessi — due elenchi
    // divergenti sono esattamente ciò che questo componente esiste per evitare.
    //
    // I gol dell'API includono i rigori: `events()` li ha già scorporati, e qui
    // interessa la stessa distinzione — un rigore non è un gol su azione.
    $e = $eventi ?? $stat?->events();
@endphp

@if ($e)
    <span {{ $attributes->merge(['class' => 'inline-flex flex-wrap items-center gap-1 align-middle']) }}>
        @if ($e['gol'] > 0)
            <span title="{{ $e['gol'] }} gol">{{ str_repeat('⚽', min(3, $e['gol'])) }}@if ($e['gol'] > 3)<span class="font-mono text-[10px]">×{{ $e['gol'] }}</span>@endif</span>
        @endif

        @if ($e['rigore_segnato'] > 0)
            <span class="font-mono text-[10px] tracking-wide text-ruolo-d"
                  title="rigore segnato">⚽R{{ $e['rigore_segnato'] > 1 ? '×'.$e['rigore_segnato'] : '' }}</span>
        @endif

        @if ($e['assist'] > 0)
            <span class="font-mono text-[10px] font-bold tracking-wide text-ruolo-c"
                  title="{{ $e['assist'] }} assist">{{ $e['assist'] }}A</span>
        @endif

        @if ($e['rigore_parato'] > 0)
            <span class="font-mono text-[10px] tracking-wide text-ruolo-d" title="rigore parato">✋R</span>
        @endif

        @if ($e['rigore_sbagliato'] > 0)
            <span class="font-mono text-[10px] tracking-wide text-rosso" title="rigore sbagliato">✗R</span>
        @endif

        @if ($e['autorete'] > 0)
            <span class="font-mono text-[10px] tracking-wide text-rosso"
                  title="autorete">⚽AUT{{ $e['autorete'] > 1 ? '×'.$e['autorete'] : '' }}</span>
        @endif

        {{-- I gol subiti: solo per chi li paga davvero.
             Il coefficiente è per ruolo e di fabbrica pesa solo sul portiere —
             un difensore non perde niente per un gol della squadra. Mostrarli
             a tutti farebbe leggere come malus una riga che per dieci undicesimi
             della formazione non cambia niente. --}}
        @if (($e['gol_subito'] ?? 0) > 0 && $ruolo === 'P')
            <span class="font-mono text-[10px] tracking-wide text-rosso"
                  title="{{ $e['gol_subito'] }} gol subiti">−{{ $e['gol_subito'] }}⚽</span>
        @endif

        {{-- Rosso e giallo non si sommano: chi è espulso per doppia
             ammonizione ha in tabellino sia il giallo sia il rosso, e mostrarli
             entrambi farebbe sembrare due punizioni dove ce n'è una. --}}
        @if ($e['espulsione'] > 0)
            <span class="text-rosso" title="espulso">▮</span>
        @elseif ($e['ammonizione'] > 0)
            <span class="text-ruolo-p" title="ammonito">▮</span>
        @endif
    </span>
@endif
