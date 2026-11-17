<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Support\DemoSwitcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Phase de test : bascule vers l'entreprise de démo d'un autre secteur
 * (cf. App\Support\DemoSwitcher), sans ressaisir de mot de passe.
 */
class DemoSwitchController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        if (! DemoSwitcher::allowedFor($request->user())) {
            throw new HttpException(404);
        }

        $data = $request->validate(['slug' => ['required', 'string']]);
        $target = DemoSwitcher::accountFor($data['slug']);

        if ($target === null) {
            return back()->with('error', 'Cette entreprise de démo n’est pas chargée. Lancez sa commande demo:… d’abord.');
        }

        Audit::record('demo.switch', $target, "Bascule de démo vers {$target->organization->name}", ['from_user' => $request->user()->id], $request->user());

        Auth::login($target);
        $request->session()->regenerate();

        return redirect('/dashboard')->with('success', "Démo : {$target->organization->name}.");
    }
}
