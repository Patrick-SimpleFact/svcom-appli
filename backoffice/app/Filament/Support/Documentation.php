<?php

namespace App\Filament\Support;

use App\Filament\Pages\BoiteDeTravail;
use App\Filament\Pages\Documentation as PageDocumentation;
use Filament\Facades\Filament;
use Illuminate\Support\Str;

/**
 * Documentation du back-office : un fichier Markdown par écran dans resources/documentation, nommé d'après le slug de
 * l'écran (« a-trier.md »). Chaque admin ne lit que les écrans qu'il peut ouvrir. Un écran sans fichier fait échouer
 * les tests : la documentation suit les menus.
 */
class Documentation
{
    /** Écrans ouverts à tous les comptes : pas dans les modèles de profil. */
    public const OUVERTS_A_TOUS = [BoiteDeTravail::class, PageDocumentation::class];

    /** @return list<class-string> tous les écrans du panneau à documenter, dans l'ordre du menu */
    public static function ecrans(): array
    {
        $panel = Filament::getPanel('admin');
        $ecrans = array_values(array_filter(
            array_unique([...$panel->getPages(), ...$panel->getResources()]),
            fn (string $ecran): bool => $ecran !== PageDocumentation::class,
        ));

        $groupes = array_flip(MenusProfil::GROUPES);
        $rang = fn (string $ecran): array => [
            $ecran === BoiteDeTravail::class ? -1 : ($groupes[self::groupe($ecran)] ?? 98),
            Str::lower(Str::ascii($ecran::getNavigationLabel())),
        ];
        usort($ecrans, fn (string $a, string $b): int => $rang($a) <=> $rang($b));

        return $ecrans;
    }

    /**
     * Les chapitres que l'admin connecté peut lire, regroupés comme le menu.
     *
     * @return array<string, list<array{ancre: string, titre: string, url: ?string, html: string}>>
     */
    public static function chapitres(): array
    {
        $chapitres = [];

        foreach (self::ecrans() as $ecran) {
            if (! $ecran::canAccess()) {
                continue;
            }

            $chapitres[$ecran === BoiteDeTravail::class ? 'Pour commencer' : self::groupe($ecran)][] = [
                'ancre' => self::ancre($ecran),
                'titre' => $ecran::getNavigationLabel(),
                'url' => $ecran::getUrl(),
                'html' => Str::markdown(self::texte($ecran), ['html_input' => 'strip', 'allow_unsafe_links' => false]),
            ];
        }

        return $chapitres;
    }

    public static function ancre(string $ecran): string
    {
        return $ecran === BoiteDeTravail::class ? 'boite-de-travail' : Str::afterLast($ecran::getSlug(), '/');
    }

    public static function fichier(string $ecran): string
    {
        return resource_path('documentation/'.self::ancre($ecran).'.md');
    }

    public static function texte(string $ecran): string
    {
        return is_file($fichier = self::fichier($ecran)) ? file_get_contents($fichier) : '';
    }

    /** Lien « Aide » de l'en-tête d'un écran documenté (portée : la page et sa ressource). */
    public static function lienAide(array $scopes): ?string
    {
        foreach ($scopes as $ecran) {
            if (in_array($ecran, self::ecrans(), true) && is_file(self::fichier($ecran))) {
                return PageDocumentation::getUrl().'#'.self::ancre($ecran);
            }
        }

        return null;
    }

    private static function groupe(string $ecran): string
    {
        $groupe = $ecran::getNavigationGroup();

        return $groupe instanceof \UnitEnum ? $groupe->name : ($groupe ?? 'Général');
    }
}
