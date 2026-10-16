<?php

/** Pages exigées par les boutiques : assistance (App Store) et suppression du compte (Google Play). */
it('sert les pages Assistance et Suppression du compte sur le domaine principal, sans aucun formulaire', function () {
    foreach (['assistance' => 'Assistance', 'suppression-compte' => 'Supprimer votre compte Spettacoli'] as $chemin => $titre) {
        $page = $this->get("https://spectacles-vivants.com/{$chemin}")->assertOk()
            ->assertSee($titre)
            ->assertSee('spettacoli@spectacles-vivants.com')
            ->assertSee('<link rel="canonical" href="'.config('app.url_site')."/{$chemin}\">", false)
            ->getContent();

        // Pas de champ de saisie : l'alerte Safe Browsing du 14/10/2026 venait d'un champ e-mail sur ce domaine.
        expect($page)->not->toContain('<form')->not->toContain('<input');
    }

    $this->get('https://spectacles-vivants.com/suppression-compte')
        ->assertSee('Profil')->assertSee('Supprimer mon compte')->assertSee('sous 30 jours');
});

it('met le lien Assistance dans le pied de l’accueil et des pages simples', function () {
    config(['app.url_site' => 'https://spectacles-vivants.com']);

    $this->get('https://spectacles-vivants.com/')->assertSee('/assistance">Assistance</a>', false);
    $this->get('https://spectacles-vivants.com/suppression-compte')->assertSee('/assistance">Assistance</a>', false);
});
