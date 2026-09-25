<?php

namespace App\Services\Trade;

use RuntimeException;

/**
 * Proposta malformata: carte non possedute, controparte di un'altra lega,
 * giornata sbagliata.
 *
 * Riguarda solo la costruzione della proposta, mai il suo esito. Uno scambio
 * che non regge il pavimento non è un errore ma un rifiuto legittimo, e viene
 * restituito come stato sulla proposta — vedi TradeService::accept().
 */
class TradeException extends RuntimeException {}
