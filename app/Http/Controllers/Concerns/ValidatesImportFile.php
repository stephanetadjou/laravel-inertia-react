<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * Validation du fichier d'un import (contacts, achats) avec des messages
 * explicites : un envoi que le serveur n'a pas pu recevoir (disque plein,
 * fichier trop lourd pour PHP) doit se voir dans la fenêtre d'import, pas
 * passer pour un clic sans effet.
 */
trait ValidatesImportFile
{
    /**
     * @param  array<string, mixed>  $rules  règles supplémentaires
     * @return array<string, mixed>
     */
    protected function validateImportFile(Request $request, array $rules = []): array
    {
        return $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls'],
            ...$rules,
        ], [
            'file.required' => 'Choisissez un fichier CSV ou Excel.',
            'file.uploaded' => 'Le serveur n\'a pas pu recevoir le fichier (espace disque insuffisant sur le serveur ou fichier trop volumineux). Réessayez ou contactez l\'administrateur.',
            'file.file' => 'Le serveur n\'a pas pu recevoir le fichier (espace disque insuffisant sur le serveur ou fichier trop volumineux). Réessayez ou contactez l\'administrateur.',
            'file.mimes' => 'Format non pris en charge : envoyez un fichier CSV, XLSX ou XLS.',
        ]);
    }
}
