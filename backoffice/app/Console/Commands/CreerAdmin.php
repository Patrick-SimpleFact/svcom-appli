<?php

namespace App\Console\Commands;

use App\Models\Admin;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Crée un compte du back-office avec un mot de passe provisoire.
 * La double authentification est configurée à la première connexion.
 */
class CreerAdmin extends Command
{
    protected $signature = 'admin:creer {email} {nom}';

    protected $description = 'Crée un compte administrateur du back-office (mot de passe provisoire affiché une fois)';

    public function handle(): int
    {
        $email = mb_strtolower($this->argument('email'));

        if (Admin::where('email', $email)->exists()) {
            $this->error("Un compte existe déjà pour {$email}.");

            return self::FAILURE;
        }

        $motDePasse = Str::password(16, symbols: false);

        Admin::create([
            'nom' => $this->argument('nom'),
            'email' => $email,
            'password' => $motDePasse,
        ]);

        $this->info("Compte créé pour {$email}.");
        $this->line("Mot de passe provisoire : {$motDePasse}");
        $this->line('À changer dans « Profil » après la première connexion (la double authentification sera demandée).');

        return self::SUCCESS;
    }
}
