<x-layouts.app title="Iscriviti — Fantasbusta">
    @php($campo = 'w-full border border-ink-4 bg-ink-2 px-3 py-2.5 text-paper outline-none transition focus:border-rosso')

    <div class="mx-auto max-w-sm py-16">
        <p class="eyebrow mb-3 text-rosso">{{ $league->name }}</p>

        <h1 class="font-display text-6xl font-black uppercase leading-[0.82] tracking-tight">
            Fanta<span class="text-rosso">busta</span>
        </h1>

        <p class="mt-4 border-l-2 border-rosso pl-3 text-sm leading-relaxed text-paper-dim">
            Scegli il nome della squadra e una password: da qui in poi entri con quelle.
        </p>

        <form method="POST" action="{{ route('registra', $gettone) }}" class="mt-10 space-y-5">
            @csrf

            <div>
                <label for="name" class="eyebrow mb-2 block text-paper-dim">Nome squadra</label>
                <input id="name" name="name" type="text" value="{{ old('name') }}"
                       required autofocus maxlength="60" class="{{ $campo }}">
                @error('name')<p class="mt-2 text-sm text-rosso">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="coach_name" class="eyebrow mb-2 block text-paper-dim">
                    Come ti chiami <span class="text-ink-4">· se vuoi</span>
                </label>
                <input id="coach_name" name="coach_name" type="text" value="{{ old('coach_name') }}"
                       maxlength="60" autocomplete="name" class="{{ $campo }}">
                @error('coach_name')<p class="mt-2 text-sm text-rosso">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="email" class="eyebrow mb-2 block text-paper-dim">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}"
                       required autocomplete="email" class="{{ $campo }}">
                @error('email')<p class="mt-2 text-sm text-rosso">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="password" class="eyebrow mb-2 block text-paper-dim">
                    Password <span class="text-ink-4">· almeno 8 caratteri</span>
                </label>
                <input id="password" name="password" type="password" required
                       autocomplete="new-password" class="{{ $campo }}">
                @error('password')<p class="mt-2 text-sm text-rosso">{{ $message }}</p>@enderror
            </div>

            <button type="submit"
                    class="w-full bg-rosso px-6 py-3 font-display text-xl font-black uppercase tracking-wider text-paper transition hover:bg-rosso-vivo">
                Entra nella lega
            </button>
        </form>

        <p class="mt-8 border-t border-ink-4/60 pt-5 text-sm text-paper-dim">
            Hai già una squadra?
            <a href="{{ route('login') }}" class="text-rosso underline-offset-4 hover:underline">Accedi</a>.
        </p>
    </div>
</x-layouts.app>
