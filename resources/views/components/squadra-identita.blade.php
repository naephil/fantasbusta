@props([
    'manager',
    'dimensione' => 34,
    'conAllenatore' => true,
    'conMaglia' => false,
])

{{--
    Nome, stemma e — se richiesta — maglia di una squadra.

    Sta in un componente e non copiato in cinque viste perché l'identità
    compare ovunque: classifica, mercato, sfide, feed. Cambiarne la forma in
    un posto solo è l'unico modo perché resti la stessa ovunque.
--}}
<span {{ $attributes->merge(['class' => 'flex min-w-0 items-center gap-2.5']) }}>
    <x-stemma :stemma="$manager->crest ?? []" :file="$manager->crest_path"
              :iniziali="$manager->initials()" :dimensione="$dimensione" class="shrink-0" />

    @if ($conMaglia)
        <x-maglia :maglia="$manager->jersey ?? []" :sponsor="$manager->sponsor ?? []"
                  :sponsor-file="$manager->sponsor_path" :dimensione="$dimensione"
                  class="shrink-0" />
    @endif

    <span class="min-w-0">
        <span class="block truncate font-display text-lg font-bold uppercase leading-none">
            {{ $manager->name }}
        </span>

        @if ($conAllenatore && $manager->coach_name)
            <span class="block truncate font-mono text-[10px] tracking-wide text-paper-dim">
                {{ $manager->coach_name }}
            </span>
        @endif
    </span>
</span>
