@props(['carta' => null])

{{--
    Un nome che, passandoci sopra, mostra la sua carta.

    Senza `carta` è testo e basta — e capita spesso: nella giornata di Serie A
    la maggior parte dei giocatori non appartiene a nessuno, quindi una carta
    non esiste proprio. Il componente si usa lo stesso ovunque, così chi scrive
    la vista non deve ricordarsi la differenza.
--}}
@if ($carta)
    <span {{ $attributes->merge(['class' => 'anteprima-carta']) }}
          data-carta="{{ $carta }}" tabindex="0">{{ $slot }}</span>
@else
    <span {{ $attributes }}>{{ $slot }}</span>
@endif
