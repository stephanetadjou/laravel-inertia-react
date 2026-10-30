<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Donne (ou retire) l'accès au back-office plateforme /admin à un compte
 * existant. Seul moyen de créer un super-admin Menentia : aucun écran ne le
 * permet, ni pour une entreprise, ni pour un autre super-admin.
 */
class MakePlatformAdmin extends Command
{
    protected $signature = 'menentia:make-admin {email : Adresse du compte existant} {--revoke : Retirer l\'accès}';

    protected $description = 'Donne ou retire l’accès au back-office plateforme Menentia (/admin)';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error('Aucun compte avec cette adresse. Créez-le d’abord (inscription ou Utilisateurs).');

            return self::FAILURE;
        }

        $revoke = (bool) $this->option('revoke');
        $user->forceFill(['is_platform_admin' => ! $revoke])->save();

        $this->info($revoke
            ? "{$user->email} n’a plus accès au back-office plateforme."
            : "{$user->email} a maintenant accès au back-office plateforme : /admin");

        return self::SUCCESS;
    }
}
