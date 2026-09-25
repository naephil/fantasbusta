<?php

namespace App\Enums;

enum Role: string
{
    case P = 'P';
    case D = 'D';
    case C = 'C';
    case A = 'A';

    public function label(): string
    {
        return match ($this) {
            self::P => 'Portiere',
            self::D => 'Difensore',
            self::C => 'Centrocampista',
            self::A => 'Attaccante',
        };
    }

    /** Colore della linguetta sulla carta — convenzione fantacalcio italiana. */
    public function color(): string
    {
        return match ($this) {
            self::P => '#f5a524',
            self::D => '#29b473',
            self::C => '#2e8fe0',
            self::A => '#e0392b',
        };
    }

    /** @return array<string,int> mappa ruolo => 0, comoda come accumulatore */
    public static function tally(): array
    {
        return array_fill_keys(array_column(self::cases(), 'value'), 0);
    }
}
