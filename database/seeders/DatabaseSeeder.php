<?php

namespace Database\Seeders;

use App\Models\League;
use App\Models\Manager;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Il minimo per entrare: un gruppo e almeno un amministratore.
 *
 * Nessuna stagione: quale annata giocare è una decisione, e si prende dalla
 * pagina di gestione dopo aver scaricato i dati. Nessun giocatore inventato: il
 * listone arriva da `stagione:carica`, e riempirlo di finti renderebbe solo più
 * difficile accorgersi che l'import vero non è stato fatto.
 *
 * Gira a ogni pubblicazione, quindi è scritto per non fare danni la seconda
 * volta: non duplica, non ripristina password, e non aggiunge un account con
 * credenziali note a un gruppo che è già amministrato.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $league = League::firstOrCreate(['name' => 'Fantasbusta']);

        $this->command?->info('Gruppo «'.$league->name.'» pronto.');

        // Se qualcuno amministra già non serve nulla — e soprattutto non serve
        // affiancargli un account la cui password sta scritta in chiaro nella
        // documentazione.
        if (Manager::where('league_id', $league->id)->where('is_admin', true)->exists()) {
            $this->command?->line('Un amministratore c\'è già: non tocco niente.');

            return;
        }

        // ⚠️ Un gruppo senza amministratori è ingestibile, e non c'è nessuna
        // schermata da cui uscirne: per amministrare bisogna già amministrare.
        // Se una squadra c'è, si promuove quella invece di aggiungerne un'altra.
        $esistente = Manager::where('league_id', $league->id)->orderBy('id')->first();

        if ($esistente) {
            $esistente->update(['is_admin' => true]);
            $this->command?->warn("Il gruppo era senza amministratori: promosso «{$esistente->name}».");

            return;
        }

        Manager::create([
            'league_id' => $league->id,
            'name' => 'Admin',
            'email' => 'admin@fantasbusta.test',
            'password' => 'password',
            'is_admin' => true,
        ]);

        $this->command?->line('Accesso: admin@fantasbusta.test / password');
        $this->command?->warn('⚠️ Cambia questa password prima di dare l\'indirizzo a qualcuno.');
        $this->command?->line('Poi: /admin/squadre per invitare gli altri.');
    }
}
