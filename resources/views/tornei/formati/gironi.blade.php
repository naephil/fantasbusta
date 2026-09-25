<section class="mb-10">
    <p class="eyebrow mb-3 text-paper-dim">I gruppi</p>

    <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
        @foreach ($stato['gruppi'] as $lettera => $gruppo)
            <div class="border border-ink-4 bg-ink-2 p-3">
                <p class="mb-2 font-display text-xl font-black uppercase">Gruppo {{ $lettera ?: '—' }}</p>
                <div class="space-y-1.5">
                    @foreach ($gruppo as $entry)
                        <div class="{{ $entry->state === 'eliminato' ? 'opacity-40' : '' }}">
                            <x-squadra-identita :manager="$entry->manager" :dimensione="24" :con-allenatore="false" />
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
</section>

@foreach ($stato['sfide'] as $stage => $sfide)
    <x-tornei.sfide :sfide="$sfide" :titolo="$stage" />
@endforeach
