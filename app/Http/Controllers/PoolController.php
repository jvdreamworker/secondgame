<?php

namespace App\Http\Controllers;

use App\Models\Season;
use Illuminate\Contracts\View\View;

class PoolController extends Controller
{
    /**
     * The offline-capable PWA shell. All real work happens client-side; the
     * only thing the server injects is which season to pull on first load.
     */
    public function index(): View
    {
        return view('pool.index', [
            'seasonId' => Season::current()?->id,
        ]);
    }
}
