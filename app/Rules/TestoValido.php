<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rifiuta il testo che non è UTF-8 valido.
 *
 * Sembra una precauzione teorica e non lo è: i campi liberi di questa pagina
 * finiscono dentro una colonna JSON, e `json_encode` su una sequenza di byte
 * malformata non restituisce null — solleva una JsonEncodingException che
 * arriva all'utente come un 500 bianco, senza dire niente a nessuno.
 *
 * Capita più facilmente di quanto sembri: un incolla da un documento in
 * latin-1, o un client che non dichiara la codifica. Meglio un messaggio di
 * validazione che una pagina di errore.
 */
class TestoValido implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
            $fail('Il campo :attribute contiene caratteri che non riesco a leggere.');
        }
    }
}
