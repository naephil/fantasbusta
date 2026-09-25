<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * La rete di sicurezza per quando la radice del sito è puntata male.
 *
 * Nel pannello si sceglie una cartella, e quella che si è appena caricata è
 * quella del progetto: puntarla lì invece che dentro `public/` è l'errore
 * naturale. È successo davvero, e si è presentato come «Forbidden» sulla home
 * mentre l'intero albero — vendor, deploy, docs — era scaricabile da chiunque.
 *
 * L'`.htaccess` nella radice rimedia, ma solo se c'è E se viaggia
 * nell'archivio: un file di regole dimenticato in locale non protegge niente.
 */
class PubblicazioneTest extends TestCase
{
    private function radice(): string
    {
        return (string) file_get_contents(base_path('.htaccess'));
    }

    public function test_la_radice_ha_il_suo_htaccess(): void
    {
        $this->assertFileExists(base_path('.htaccess'));
    }

    public function test_manda_le_richieste_dentro_public(): void
    {
        // Senza questa riga la home resta «Forbidden»: nella cartella del
        // progetto non c'è nessun indice da servire.
        $this->assertMatchesRegularExpression(
            '/RewriteRule \^\(\.\*\)\$ public\/\$1/',
            $this->radice(),
        );
    }

    public function test_sbarra_il_env_prima_di_riscrivere(): void
    {
        $testo = $this->radice();

        $sbarra = strpos($testo, 'RewriteRule ^\.env');
        $riscrive = strpos($testo, 'public/$1');

        // ⚠️ L'ordine è la sostanza: un .env esiste davvero sul disco, quindi
        // se la riscrittura venisse prima il file verrebbe servito e basta.
        $this->assertIsInt($sbarra);
        $this->assertIsInt($riscrive);
        $this->assertLessThan($riscrive, $sbarra);
    }

    public function test_sbarra_le_cartelle_dell_applicazione(): void
    {
        $testo = $this->radice();

        foreach (['vendor', 'storage', 'deploy', 'config', 'database'] as $cartella) {
            $this->assertStringContainsString($cartella, $testo, "«{$cartella}» non è sbarrata.");
        }
    }

    public function test_regge_anche_senza_mod_rewrite(): void
    {
        // Se il modulo mancasse, le RewriteRule verrebbero saltate in silenzio
        // e resterebbe tutto scoperto senza che nessun errore lo dica.
        $testo = $this->radice();

        $this->assertStringContainsString('Require all denied', $testo);
        $this->assertStringContainsString('Options -Indexes', $testo);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function scriptDiPreparazione(): array
    {
        return [
            'powershell' => ['deploy/prepara.ps1'],
            'bash' => ['deploy/prepara.sh'],
        ];
    }

    /**
     * @dataProvider scriptDiPreparazione
     */
    public function test_l_htaccess_viaggia_nell_archivio(string $script): void
    {
        // I due script elencano ciò che ENTRA: un file non nominato resta a
        // casa, e sul server la rete di sicurezza semplicemente non c'è.
        $this->assertStringContainsString(
            '.htaccess',
            (string) file_get_contents(base_path($script)),
            "{$script} non impacchetta l'.htaccess della radice.",
        );
    }
}
