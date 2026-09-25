<x-layouts.app title="Accedi — Fantasbusta">
    <div class="mx-auto max-w-sm py-16">
        <p class="eyebrow mb-3 text-rosso">Lega privata</p>

        <h1 class="font-display text-6xl font-black uppercase leading-[0.82] tracking-tight">
            Fanta<span class="text-rosso">busta</span>
        </h1>

        <p class="mt-4 border-l-2 border-rosso pl-3 text-sm leading-relaxed text-paper-dim">
            Fantacalcio a carte collezionabili. Rose estratte ogni giornata.
        </p>

        <form method="POST" action="{{ route('login') }}" class="mt-10 space-y-5">
            @csrf

            <div>
                <label for="email" class="eyebrow mb-2 block text-paper-dim">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}"
                       required autofocus autocomplete="email"
                       class="w-full border border-ink-4 bg-ink-2 px-3 py-2.5 text-paper outline-none transition focus:border-rosso">
            </div>

            <div>
                <label for="password" class="eyebrow mb-2 block text-paper-dim">Password</label>
                <input id="password" name="password" type="password" required autocomplete="current-password"
                       class="w-full border border-ink-4 bg-ink-2 px-3 py-2.5 text-paper outline-none transition focus:border-rosso">
            </div>

            @error('email')
                <p class="border-l-2 border-rosso bg-ink-2 px-3 py-2 text-sm text-paper">{{ $message }}</p>
            @enderror

            <label class="flex items-center gap-2 text-sm text-paper-dim">
                <input type="checkbox" name="ricordami" value="1" class="accent-rosso">
                Ricordami
            </label>

            <button type="submit"
                    class="w-full bg-rosso px-6 py-3 font-display text-xl font-black uppercase tracking-wider text-paper transition hover:bg-rosso-vivo">
                Entra
            </button>
        </form>
    </div>
</x-layouts.app>
