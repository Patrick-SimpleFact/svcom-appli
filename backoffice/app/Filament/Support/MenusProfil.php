<?php

namespace App\Filament\Support;

use Closure;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Schemas\Components\Section;
use Illuminate\Database\Eloquent\Model;

/**
 * Menus qu'un modèle de profil peut ouvrir : toutes les ressources et pages du back-office, sauf la boîte de travail
 * et la documentation (ouvertes à tous) et ce qui est réservé aux super admins. Un nouveau menu y apparaît tout seul.
 */
class MenusProfil
{
    /** Ordre des groupes du menu. */
    public const GROUPES = ['Collecte', 'Catalogue', 'Référentiels', 'Contributions', 'Espace salle', 'Suggestions', 'Statistiques', 'Administration'];

    /** @return array<string, array<class-string, string>> groupe → (classe → libellé), dans l'ordre du menu */
    public static function parGroupe(): array
    {
        $panel = Filament::getPanel('admin');
        $groupes = [];

        foreach ([...$panel->getResources(), ...$panel->getPages()] as $ecran) {
            if (in_array($ecran, Documentation::OUVERTS_A_TOUS, true) || in_array(ReserveAuSuperAdmin::class, class_uses_recursive($ecran), true)) {
                continue;
            }

            $groupes[self::groupe($ecran)][$ecran] = $ecran::getNavigationLabel();
        }

        $ordre = array_flip(self::GROUPES);
        uksort($groupes, fn (string $a, string $b) => ($ordre[$a] ?? 99) <=> ($ordre[$b] ?? 99) ?: strcmp($a, $b));

        return array_map(fn (array $menus) => collect($menus)->sort()->all(), $groupes);
    }

    /** @return list<class-string> */
    public static function tous(): array
    {
        return array_merge(...array_map('array_keys', array_values(self::parGroupe())));
    }

    /**
     * Une section de cases par groupe (modèle de profil, compte « Libre ») ; l'enregistrement lu a une colonne « menus ».
     *
     * @return list<Section>
     */
    public static function sections(Closure $visible): array
    {
        return collect(self::parGroupe())->map(fn (array $menus, string $groupe) => Section::make($groupe)
            ->compact()
            ->visible($visible)
            ->schema([
                // Un champ par groupe, une seule colonne « menus » : chaque groupe ne lit et n'écrit que ses menus.
                CheckboxList::make('menus_'.md5($groupe))
                    ->hiddenLabel()
                    ->options($menus)
                    ->bulkToggleable()
                    ->columns(2)
                    ->afterStateHydrated(fn (CheckboxList $c, ?Model $record) => $c->state(array_values(array_intersect($record->menus ?? [], array_keys($menus))))),
            ]))->values()->all();
    }

    /** Réunit les menus cochés de chaque groupe (champs de sections()) dans « menus », dans l'ordre du menu. */
    public static function reunir(array $data): array
    {
        $menus = [];

        foreach (array_keys(self::parGroupe()) as $groupe) {
            $menus = [...$menus, ...($data['menus_'.md5($groupe)] ?? [])];
            unset($data['menus_'.md5($groupe)]);
        }

        return [...$data, 'menus' => array_values(array_intersect(self::tous(), $menus))];
    }

    /** @param  list<string>  $menus */
    public static function libelles(array $menus): array
    {
        $tous = array_merge(...array_values(self::parGroupe()));

        return array_values(array_filter(array_map(fn (string $m) => $tous[$m] ?? null, $menus)));
    }

    private static function groupe(string $ecran): string
    {
        $groupe = $ecran::getNavigationGroup();

        return $groupe instanceof \UnitEnum ? $groupe->name : ($groupe ?? 'Général');
    }
}
