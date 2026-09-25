<!DOCTYPE html>
<html lang="it" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Fantasbusta' }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@500;700;900&family=Archivo:wght@400;600;800&family=Chivo+Mono:wght@400;700&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen font-sans antialiased">

<x-sprite-simboli />

@auth
    @php
        /*
         * La navigazione, raggruppata per COSA SI STA FACENDO.
         *
         * Era una fila di dieci voci tutte allo stesso livello, che su un
         * telefono andava a capo tre volte e non entrava comunque. Ma il
         * problema non era solo lo spazio: dieci parole in fila non dicono che
         * «Formazione» e «Mercato» sono la stessa attività vista in due momenti,
         * mentre «Listone» è un'altra cosa. Il raggruppamento è la mappa del
         * gioco, e sul telefono diventa anche il modo di starci dentro.
         *
         * ⚠️ Serie A resta da solo e senza tendina: è l'unica voce che parla del
         * mondo vero invece che della lega, e metterlo in un gruppo lo farebbe
         * sembrare una schermata del gioco come le altre.
         *
         * ⚠️ I tornei stanno con i risultati e NON sotto Admin, dove pure
         * andrebbero per «chi li crea». A crearli è l'amministratore, ma il
         * tabellone lo guardano tutti — ed è esattamente quello che si cerca
         * quando si è appena visto un risultato. Sotto Admin sarebbero
         * invisibili a undici manager su dodici.
         */
        $gruppi = [
            ['etichetta' => 'Gioco', 'voci' => [
                'draft.show' => 'Draft',
                'lineup.show' => 'Formazione',
                'trades.index' => 'Mercato',
            ]],
            ['etichetta' => 'Risultati', 'voci' => [
                'risultati.index' => 'Risultati',
                'standings.index' => 'Classifica',
                'tornei.index' => 'Tornei',
            ]],
            ['etichetta' => 'La tua lega', 'voci' => [
                'team.edit' => 'Squadra',
                'listone.index' => 'Listone',
                'stats.index' => 'Statistiche',
            ]],
        ];

        if (auth()->user()->is_admin) {
            $gruppi[] = ['etichetta' => 'Admin', 'voci' => [
                'admin.gestione' => 'Gestione',
                'admin.squadre.index' => 'Iscritti lega',
                'admin.regole.edit' => 'Regole',
            ]];
        }

        $attivo = fn (string $rotta) => request()->routeIs(Str::beforeLast($rotta, '.').'.*');
    @endphp

    <header class="border-b border-ink-4/60">
        <div class="mx-auto flex max-w-6xl items-center gap-x-6 gap-y-3 px-4 py-3 sm:px-6 sm:py-4">
            <a href="{{ route('home') }}" class="font-display text-2xl font-black uppercase leading-none tracking-tight sm:text-3xl">
                Fanta<span class="text-rosso">busta</span>
            </a>

            {{-- Il pulsante del menu esiste solo dove serve: sotto lg la fila
                 non ci sta e diventa un pannello che si apre. --}}
            <button type="button" data-menu-toggle aria-expanded="false" aria-controls="menu-principale"
                    class="ml-auto border border-ink-4 px-3 py-2 text-paper-dim transition hover:border-rosso hover:text-rosso lg:hidden">
                <span class="sr-only">Apri il menu</span>
                <svg viewBox="0 0 20 14" class="h-3.5 w-5" aria-hidden="true">
                    <path d="M0 1h20M0 7h20M0 13h20" stroke="currentColor" stroke-width="2" fill="none" />
                </svg>
            </button>

            <nav id="menu-principale" data-menu
                 class="order-last hidden w-full flex-col gap-1 border-t border-ink-4/60 pt-3 text-sm
                        lg:order-none lg:flex lg:w-auto lg:flex-1 lg:flex-row lg:items-center lg:gap-6 lg:border-0 lg:pt-0">
                <a href="{{ route('matchday.show') }}"
                   class="nav-voce {{ $attivo('matchday.show') ? 'text-paper' : '' }}">
                    Serie A
                </a>

                @foreach ($gruppi as $gruppo)
                    @php($gruppoAttivo = collect(array_keys($gruppo['voci']))->contains(fn ($r) => $attivo($r)))

                    <details class="nav-gruppo" @if ($gruppoAttivo) data-corrente @endif>
                        <summary class="nav-voce {{ $gruppoAttivo ? 'text-paper' : '' }}">
                            {{ $gruppo['etichetta'] }}
                            <svg viewBox="0 0 10 6" class="nav-freccia" aria-hidden="true">
                                <path d="M1 1l4 4 4-4" stroke="currentColor" stroke-width="1.5" fill="none" />
                            </svg>
                        </summary>

                        <div class="nav-tendina">
                            @foreach ($gruppo['voci'] as $rotta => $etichetta)
                                <a href="{{ route($rotta) }}"
                                   class="nav-sottovoce {{ $attivo($rotta) ? 'text-paper' : '' }}">
                                    {{ $etichetta }}
                                </a>
                            @endforeach
                        </div>
                    </details>
                @endforeach

                {{-- Il cartellino rosso resta all'admin ed è FUORI dalle tendine:
                     è un lavoro che aspetta, e chiuso dentro un menu non lo
                     vedrebbe mai nessuno. --}}
                @if (auth()->user()->is_admin && $stagioneAttiva && $daRivedere = \App\Models\PlayerSeason::daConfermare($stagioneAttiva->season)->count())
                    <a href="{{ route('listone.index') }}" class="nav-voce text-rosso hover:text-rosso-vivo">
                        {{ $daRivedere }} da decidere
                    </a>
                @endif
            </nav>

            {{--
                Il selettore di stagione sta accanto al nome, non dentro le
                singole schermate: è il contesto di TUTTA la navigazione — rosa,
                classifica, sfide — e nasconderlo in una pagina renderebbe
                impossibile capire perché la classifica «è sbagliata».
            --}}
            <div class="ml-auto flex items-center gap-3 sm:gap-4">
                @if ($stagioniDisponibili->count() > 1)
                    <form method="POST" action="{{ route('stagione.scegli') }}">
                        @csrf
                        <select name="stagione" onchange="this.form.submit()"
                                class="border border-ink-4 bg-ink-2 px-2 py-1 font-mono text-xs text-paper-dim">
                            @foreach ($stagioniDisponibili as $s)
                                <option value="{{ $s->id }}" @selected($stagioneAttiva?->id === $s->id)>
                                    {{ $s->etichetta() }}{{ $s->state === 'conclusa' ? ' · conclusa' : '' }}
                                </option>
                            @endforeach
                        </select>
                    </form>
                @elseif ($stagioneAttiva)
                    <span class="hidden font-mono text-xs text-paper-dim sm:inline">{{ $stagioneAttiva->etichetta() }}</span>
                @endif

                <form method="POST" action="{{ route('logout') }}" class="flex items-center gap-3">
                    @csrf
                    <span class="eyebrow hidden text-paper-dim sm:inline">{{ auth()->user()->name }}</span>
                    <button type="submit" class="text-sm text-paper-dim underline-offset-4 transition hover:text-paper hover:underline">
                        Esci
                    </button>
                </form>
            </div>
        </div>
    </header>
@endauth

<main class="mx-auto max-w-6xl px-4 py-8 sm:px-6 sm:py-10">
    @if (session('successo'))
        <div class="mb-6 border-l-2 border-ruolo-d bg-ink-2 px-4 py-3 text-sm">
            {{ session('successo') }}
        </div>
    @endif

    {{ $slot }}
</main>

</body>
</html>
