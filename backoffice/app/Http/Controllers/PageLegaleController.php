<?php

namespace App\Http\Controllers;

use App\Models\PageLegale;
use Illuminate\View\View;

/** Pages légales publiques (F1.6, API §12), liées depuis le Profil de l'app et le pied des pages web. */
class PageLegaleController extends Controller
{
    public function __invoke(string $slug): View
    {
        return view('web.page-legale', ['page' => PageLegale::where('slug', $slug)->firstOrFail()]);
    }
}
