<?php

namespace Tests;

use App\Services\Scoring\Settings;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // La taratura si tiene in memoria per non rifare la stessa query a ogni
        // giocatore. Fra un test e l'altro il database sparisce ma la memoria
        // statica no, e la stagione 1 del test precedente si ritroverebbe le
        // regole di quello prima — un fallimento che dipende dall'ordine di
        // esecuzione, cioè il peggiore da diagnosticare.
        Settings::dimentica();
    }
}
