<?php

namespace App\Providers;

use App\Support\StagioneAttiva;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StagioneAttiva::class);
    }

    public function boot(): void
    {
        $this->condividiStagione();
    }

    /**
     * La stagione attiva, disponibile in ogni vista.
     *
     * Sta nell'intestazione, quindi la vedono tutte le pagine: passarla dai
     * singoli controller significherebbe dimenticarsela in una e ritrovarsi la
     * barra rotta in quell'unica schermata, di solito in produzione.
     *
     * ⚠️ Vale per TUTTE le viste, non solo per il layout. Limitarlo a
     * `components.layouts.app` sembrava un'ottimizzazione e ha prodotto due
     * pagine che esplodevano con «Undefined variable $stagioniDisponibili»:
     * Blade renderizza il corpo della pagina PRIMA del layout, quindi lì la
     * variabile non c'era ancora. Una delle due falliva solo in certi stati,
     * cioè nel modo peggiore.
     *
     * Costa poco perché StagioneAttiva tiene la risposta in memoria per tutta
     * la richiesta: la query si fa una volta sola comunque.
     */
    private function condividiStagione(): void
    {
        View::composer('*', function ($view) {
            $manager = auth()->user();

            $view->with([
                'stagioneAttiva' => $manager ? app(StagioneAttiva::class)->per($manager) : null,
                'stagioniDisponibili' => $manager
                    ? app(StagioneAttiva::class)->disponibili($manager)
                    : collect(),
            ]);
        });
    }
}
