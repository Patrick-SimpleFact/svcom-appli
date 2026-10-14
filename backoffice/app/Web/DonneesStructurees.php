<?php

namespace App\Web;

/**
 * Données structurées schema.org (SEO 1) en JSON-LD. Construites ici plutôt que dans le gabarit :
 * Blade prendrait la clé « @context » pour une de ses directives.
 */
class DonneesStructurees
{
    /** Accueil : le site et son éditeur (nom et logo dans la fiche de Google). */
    public static function accueil(string $site): string
    {
        return self::json([
            '@context' => 'https://schema.org',
            '@graph' => [
                ['@type' => 'WebSite', '@id' => $site.'/#site', 'url' => $site.'/', 'name' => 'Spettacoli', 'inLanguage' => 'fr-FR', 'publisher' => ['@id' => $site.'/#organisation']],
                ['@type' => 'Organization', '@id' => $site.'/#organisation', 'name' => 'Spettacoli', 'url' => $site.'/', 'logo' => $site.'/images/logo-512.png'],
            ],
        ]);
    }

    private static function json(array $donnees): string
    {
        // JSON_HEX_TAG : jamais de « </script> » possible dans le bloc.
        return json_encode($donnees, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_THROW_ON_ERROR);
    }
}
