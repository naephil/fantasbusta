<?php

namespace App\Http\Controllers;

use App\Services\Stats\LiveMatchday;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MatchdayController extends Controller
{
    public function __construct(private LiveMatchday $live) {}

    public function show(Request $request): View
    {
        $manager = $request->user();
        $stagione = $this->stagioneOAbort($manager);
        $giornate = $this->live->availableMatchdays($stagione->season);

        $matchday = $request->integer('giornata') ?: $giornate->first();

        return view('matchday.show', [
            'stagione' => $stagione,
            'matchday' => $matchday,
            'giornate' => $giornate,
            'partite' => $matchday
                ? $this->live->forMatchday($stagione, $matchday, $manager->id)
                : collect(),
        ]);
    }
}
