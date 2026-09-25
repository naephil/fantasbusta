<?php

namespace App\Services\Scoring;

use App\Models\LeagueSeason;

/**
 * Le regole del gioco, tarabili per gruppo e per stagione.
 *
 * Tre livelli, dal generale al particolare: i default del codice, poi le regole
 * di casa del gruppo, poi gli scostamenti di quella stagione. Ogni gruppo gioca
 * come vuole, e può cambiare idea da un anno all'altro senza riscrivere il
 * passato.
 *
 * I valori stanno in tabella e non in un file di config perché si tarano a
 * stagione in corso: cambiare un coefficiente non deve richiedere un deploy.
 * Si salvano solo gli scostamenti, per foglia — così si ritocca un evento senza
 * ricopiare tutta la tabella, e un parametro aggiunto in seguito entra in
 * vigore da solo invece di restare congelato al valore di quel giorno.
 *
 * ⚠️ Regole diverse per gruppo sono la ragione per cui `player_scores` e
 * `player_power` portano `league_season_id`: lo stesso giocatore nella stessa
 * giornata vale diversamente a seconda di chi lo schiera, quindi i voti non
 * sono condivisibili fra leghe.
 *
 * ⚠️ Nessun coefficiente è «per definizione» un bonus. Un evento vale ciò che
 * dice il suo segno: portare `gol` a −3 lo trasforma in un malus, e la
 * ripartizione fra le colonne `bonus` e `malus` segue di conseguenza. Vedi
 * FantavotoCalculator::compute().
 */
final class Settings
{
    /**
     * @var array<string,mixed>
     */
    public const DEFAULTS = [
        // Il rating API-Football è compresso: quasi tutti fra 6.0 e 7.5.
        // La molla tiene fermo il centro e allarga le distanze di k, così la
        // prestazione torna a pesare quanto i bonus. k = 1 la disattiva.
        'voto_base' => [
            'centro' => 6.0,
            'k' => 1.2,
            'min' => 4.0,
            'max' => 9.0,
        ],

        // Coefficiente per evento, con override per ruolo.
        // Si sommano al voto base moltiplicati per quante volte l'evento è
        // accaduto, quindi il segno è tutto: cambiarlo ribalta bonus e malus.
        'eventi' => [
            'gol' => ['default' => 3],
            'rigore_segnato' => ['default' => 3],
            'rigore_parato' => ['default' => 3],
            'rigore_sbagliato' => ['default' => -3],
            'assist' => ['default' => 1],
            'ammonizione' => ['default' => -0.5],
            'espulsione' => ['default' => -1],
            'autorete' => ['default' => -2],
            'gol_subito' => ['default' => 0, 'P' => -1],
        ],

        // Quanto vale un titolare rimasto senza voto e senza sostituto.
        // Il 4 al portiere è ciò che dà valore al secondo portiere sul mercato:
        // senza una penalità non averlo non costerebbe nulla e nessuno
        // scambierebbe mai per procurarselo. Doloroso, non fatale.
        'senza_voto' => ['default' => 0, 'P' => 4],

        'sostituzioni' => ['max' => 3],

        // Pesi del power score, ricalcolato ogni giornata: è ciò che fa salire
        // e scendere le carte di tier settimana per settimana.
        //
        // `forma` alto rende le carte molto volatili, `forma` basso tiene la
        // rarità stabile e vicina al valore reale del giocatore. È il parametro
        // che decide che gioco è: caccia al giocatore in forma, o mercato di
        // valori consolidati.
        'power' => [
            'pesi' => [
                'baseline' => 0.25,     // quotazione del listone, per ruolo
                'fantamedia' => 0.30,   // rendimento stagionale
                'forma' => 0.25,        // media delle ultime giornate
                'titolarita' => 0.20,   // quota di minuti giocati
                'rischio' => 0.05,      // si SOTTRAE
            ],
            'giornate_forma' => 3,

            // Le due soglie di fondo della piramide, sulla scala 0..100 del
            // power. Sotto queste una carta è Pacco o Monnezza a prescindere
            // dalla posizione nel ruolo.
            //
            // ⚠️ Servono perché il listone vero ha centinaia di giocatori a
            // quotazione 1 che non hanno mai giocato — terzi portieri,
            // primavera, ceduti a gennaio. Contati nella piramide occupavano
            // tutta la fascia Comune e spingevano in alto tutti gli altri: «la
            // maggior parte delle carte è Rara» diventava vero senza che
            // nessuna carta fosse migliorata.
            //
            // Sono tarabili perché il power è una somma PESATA, e i pesi si
            // cambiano da qui sopra: spostandoli, la scala si sposta con loro e
            // una soglia fissa nel codice vorrebbe dire un'altra cosa.
            'soglie' => [
                'pacco' => 10.0,
                'monnezza' => 2.0,
            ],
        ],

        // Si somma al totale, quindi è negativa. Chi si dimentica di schierare
        // non viene escluso dal campionato, ma non gioca nemmeno gratis.
        'formazione_mancante' => ['penalita' => -3],

        'modificatore_difesa' => ['attivo' => false],

        // Con 12 manager un girone dura 11 turni: due gironi ne occupano 22
        // delle 38 giornate. Il margine è voluto — la lega può partire a
        // stagione già iniziata, e ciò che avanza resta per un torneo finale.
        'calendario' => ['gironi' => 2],

        // Come si spartisce la finestra fra due giornate: prima il draft, poi
        // il mercato. Fra due primi fischi passano da ~48h infrasettimanali a
        // una settimana piena, quindi fissare le ore a mano non regge — una
        // quota sì. Con 0.43 su una settimana da venerdì a venerdì il draft
        // chiude il lunedì sera, che è esattamente il ritmo di §1.
        // Cinque buste da cinque: venticinque carte. Sono valori di PARTENZA,
        // non una struttura: si cambiano per stagione da /admin/regole, e tre
        // buste da otto — ventiquattro carte, meno turni e più lunghi — è una
        // taratura altrettanto legittima. Tutto ciò che dipende da questi due
        // numeri li legge, nessuno li dà per scontati.
        'draft' => [
            'quota_finestra' => 0.43,
            'giri' => 5,
            'carte_per_busta' => 5,

            // Quanto può durare un turno. La durata vera è la finestra che
            // resta divisa per i turni che mancano — così il draft si
            // autocomprime — ma va tenuta fra due estremi: sotto il minimo
            // nessuno farebbe in tempo a vedere il proprio turno, sopra il
            // massimo un solo assente terrebbe fermi tutti mezza giornata.
            //
            // Sono minuti e sono tarabili perché il minimo, che nel gioco vero
            // è una cortesia, in una stagione di prova è un muro: sessanta
            // turni da venti minuti fanno venti ore per una giornata sola.
            // Portarlo a un minuto rende il collaudo questione di secondi.
            'turno_min_minuti' => 20,
            'turno_max_minuti' => 240,
        ],

        'sfida' => [
            // I fantapunti diventano reti, come nel fantacalcio di sempre:
            // sotto 66 zero gol, a 66 il primo, poi uno ogni 6. Una giornata
            // da 74 batte una da 71 per 2–1, e una da 71 contro una da 70
            // finisce 1–1 — la differenza c'è ma non decide da sola.
            //
            // 66 e 6 sono i valori con cui gioca praticamente tutto il
            // fantacalcio italiano: chi arriva qui li riconosce senza doverli
            // leggere, ed è metà del motivo per cui sono il punto di partenza.
            // L'altra metà è che sono tarati bene — con undici titolari da 6
            // in pagella si sta a 66, cioè la soglia del primo gol.
            //
            // Spegnendoli si torna al sistema a scarto: vince chi ha più
            // fantapunti e sotto `soglia_pareggio` è pari. Più severo, e c'è
            // chi lo preferisce; vedi Arbitro.
            'gol' => [
                'attivo' => true,
                'prima_soglia' => 66.0,
                'passo' => 6.0,
            ],

            // Usata solo a gol spenti. Sotto questo scarto la sfida è pari:
            // serve a non far decidere una giornata da mezzo punto, che dipende
            // più dall'arrotondamento di un rating che da come si è schierato.
            'soglia_pareggio' => 2.0,

            'vittoria' => 3,
            'pareggio' => 1,
            'sconfitta' => 0,
        ],
    ];

    /**
     * La taratura di ogni stagione, letta una volta sola.
     *
     * Il punteggio di una giornata la chiede centinaia di volte — una per
     * giocatore — e senza memoria sarebbero centinaia di query identiche.
     *
     * @var array<int,self>
     */
    private static array $memoria = [];

    /** @var array<string,mixed> */
    private array $values;

    /** @param  array<string,mixed>|null  $overrides */
    public function __construct(?array $overrides = null)
    {
        $this->values = array_replace_recursive(self::DEFAULTS, $overrides ?? []);
    }

    /** Le regole in vigore per una stagione di lega. */
    public static function for(LeagueSeason $stagione): self
    {
        return self::$memoria[$stagione->id] ??= new self(self::scostamenti($stagione));
    }

    /**
     * Riscrive la taratura di una stagione.
     *
     * Non tocca i punteggi già calcolati: quelli si rifanno rilanciando la
     * giornata, che è rieseguibile apposta. Cambiare un coefficiente a metà
     * stagione senza ricalcolare lascia le giornate vecchie com'erano — ed è
     * il comportamento giusto, perché riscriverle a sorpresa cambierebbe una
     * classifica che tutti hanno già letto.
     *
     * @param  array<string,mixed>  $overrides  solo gli scostamenti dai default
     */
    public static function salva(LeagueSeason $stagione, array $overrides): self
    {
        $stagione->update(['settings' => $overrides]);
        unset(self::$memoria[$stagione->id]);

        return self::for($stagione->refresh());
    }

    /**
     * Gli scostamenti in vigore, senza i default: gruppo più stagione.
     *
     * @return array<string,mixed>
     */
    public static function scostamenti(LeagueSeason $stagione): array
    {
        return array_replace_recursive(
            $stagione->league->settings ?? [],
            $stagione->settings ?? [],
        );
    }

    /** Da chiamare quando la tabella cambia sotto i piedi: test, e poco altro. */
    public static function dimentica(): void
    {
        self::$memoria = [];
    }

    /** @return array<string,mixed> la taratura completa, default inclusi */
    public function tutto(): array
    {
        return $this->values;
    }

    /**
     * Coefficiente dell'evento per quel ruolo.
     *
     * L'override di ruolo serve a due cose diverse con la stessa meccanica:
     * eventi che riguardano un solo reparto (il gol subito è del portiere) e
     * eventi che pesano diversamente per reparto (il gol del difensore).
     */
    public function coefficient(string $evento, string $role): float
    {
        $scale = $this->values['eventi'][$evento] ?? [];

        return (float) ($scale[$role] ?? $scale['default'] ?? 0);
    }

    /** Voto di chi è rimasto senza voto e senza sostituto. */
    public function senzaVoto(string $role): float
    {
        $scale = $this->values['senza_voto'];

        return (float) ($scale[$role] ?? $scale['default'] ?? 0);
    }

    public function votoBaseParams(): array
    {
        return $this->values['voto_base'];
    }

    public function maxSostituzioni(): int
    {
        return (int) $this->values['sostituzioni']['max'];
    }

    /** Negativa: si somma al totale. */
    public function penalitaFormazioneMancante(): float
    {
        return (float) $this->values['formazione_mancante']['penalita'];
    }

    /** @return array<string,float> */
    public function pesiPower(): array
    {
        return array_map('floatval', $this->values['power']['pesi']);
    }

    public function giornateForma(): int
    {
        return (int) $this->values['power']['giornate_forma'];
    }

    /** Sotto questo power una carta è Pacco, qualunque sia la sua posizione. */
    public function sogliaPacco(): float
    {
        return (float) $this->values['power']['soglie']['pacco'];
    }

    /** Sotto questo power è Monnezza. Più bassa della soglia Pacco, per costruzione. */
    public function sogliaMonnezza(): float
    {
        return min(
            (float) $this->values['power']['soglie']['monnezza'],
            $this->sogliaPacco(),
        );
    }

    public function gironi(): int
    {
        return (int) $this->values['calendario']['gironi'];
    }

    /** Quota della finestra fra due giornate riservata al draft; il resto è mercato. */
    public function quotaFinestraDraft(): float
    {
        return (float) $this->values['draft']['quota_finestra'];
    }

    public function giriDraft(): int
    {
        return (int) $this->values['draft']['giri'];
    }

    public function cartePerBusta(): int
    {
        return (int) $this->values['draft']['carte_per_busta'];
    }

    /** Sotto questa durata un turno non scende, per quanto il draft sia stretto. */
    public function turnoMinSecondi(): int
    {
        return max(1, (int) $this->values['draft']['turno_min_minuti'] * 60);
    }

    /** Sopra questa durata un turno non sale, per quanto la finestra sia larga. */
    public function turnoMaxSecondi(): int
    {
        return max($this->turnoMinSecondi(), (int) $this->values['draft']['turno_max_minuti'] * 60);
    }

    /** Se la lega gioca a gol invece che a scarto di fantapunti. */
    public function golAttivi(): bool
    {
        return (bool) $this->values['sfida']['gol']['attivo'];
    }

    /** I fantapunti che valgono la prima rete. */
    public function primaSogliaGol(): float
    {
        return (float) $this->values['sfida']['gol']['prima_soglia'];
    }

    /** Quanti fantapunti in più vale ogni rete successiva. */
    public function passoGol(): float
    {
        return (float) $this->values['sfida']['gol']['passo'];
    }

    /** Solo a gol spenti: sotto questo scarto la sfida è pari. */
    public function sogliaPareggio(): float
    {
        return (float) $this->values['sfida']['soglia_pareggio'];
    }

    /** @return array{vittoria: int, pareggio: int, sconfitta: int} */
    public function puntiSfida(): array
    {
        return [
            'vittoria' => (int) $this->values['sfida']['vittoria'],
            'pareggio' => (int) $this->values['sfida']['pareggio'],
            'sconfitta' => (int) $this->values['sfida']['sconfitta'],
        ];
    }

    public function modificatoreDifesaAttivo(): bool
    {
        return (bool) $this->values['modificatore_difesa']['attivo'];
    }
}
