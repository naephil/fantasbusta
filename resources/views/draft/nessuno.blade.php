<x-layouts.app title="Draft — Fantasbusta">
    <header class="mb-8">
        <p class="eyebrow mb-2 text-rosso">Draft</p>
        <h1 class="font-display text-5xl font-black uppercase leading-none tracking-tight">
            Nessuna busta all'orizzonte
        </h1>
    </header>

    {{-- ⚠️ Il palco serve ANCHE qui, e proprio qui più che altrove: a draft
         concluso è questa la pagina che si trova, ed è il caso in cui la busta
         si è aperta senza di te per definizione — il turno è passato, il draft
         si è chiuso, e chi arriva adesso trova le carte in rosa e nient'altro.
         Senza questo, l'invito a rivederla non sarebbe mai comparso proprio a
         chi ne aveva bisogno. --}}
    <x-sbustamento :da-rivedere="$daRivedere" />

    <p class="max-w-xl border-l-2 border-ink-4 pl-3 text-sm leading-relaxed text-paper-dim">
        Il draft della prossima giornata non è ancora stato preparato. Apre al primo fischio
        della giornata in corso — da lì ci sono
        <strong class="text-paper">{{ $settings->giriDraft() }} buste
        da {{ $settings->cartePerBusta() }} carte</strong>
        ({{ $settings->giriDraft() * $settings->cartePerBusta() }} in tutto), e chi sta peggio
        in classifica pesca per primo.
    </p>
</x-layouts.app>
