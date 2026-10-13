<?php

use App\Collecte\NomDeLieu;

it('trouve le nom de la salle avant ou après l’adresse (lignes vues chez DATAtourisme le 13/10)', function (array $lignes, ?string $nom, array $reste) {
    expect(NomDeLieu::separer($lignes))->toBe([$nom, $reste]);
})->with([
    'nom en tête' => [['TMP - Théâtre Municipal Pazenais', '7 rue du Ballon'], 'TMP - Théâtre Municipal Pazenais', ['7 rue du Ballon']],
    'une ligne sans numéro' => [['Cinéma Le Doron'], 'Cinéma Le Doron', []],
    'nom après l’adresse' => [['41 rue Jobin', 'Cabaret Aléatoire', 'Friche la Belle de Mai'], 'Cabaret Aléatoire', ['41 rue Jobin', 'Friche la Belle de Mai']],
    'après un lieu-dit' => [['3 Rue du Président Rene Coty', 'Notre-Dame-de-Gravenchon', 'Médiathèque'], 'Médiathèque', ['3 Rue du Président Rene Coty', 'Notre-Dame-de-Gravenchon']],
    'salle accentuée' => [['80 Le Bourg', 'Salle des Fêtes'], 'Salle des Fêtes', ['80 Le Bourg']],
    'boîte postale ignorée' => [['2 avenue des Alcyons', 'BP 15'], null, ['2 avenue des Alcyons', 'BP 15']],
    'lieu-dit seul ignoré' => [['1 rue de la Citadelle', 'Saint-Pierre-aux-Nonnains'], null, ['1 rue de la Citadelle', 'Saint-Pierre-aux-Nonnains']],
    'rue qui porte un mot de salle' => [['12', 'Place du Théâtre'], null, ['12', 'Place du Théâtre']],
    'adresse seule' => [['62a Grand-Rue'], null, ['62a Grand-Rue']],
    'rien' => [[' ', ''], null, []],
]);

it('repère un nom qui ne dit pas où aller', function (string $nom, ?string $adresse, ?string $commune, bool $imprecis) {
    expect(NomDeLieu::estImprecis($nom, $adresse, $commune))->toBe($imprecis);
})->with([
    'adresse' => ['62a Grand-Rue', '62a Grand-Rue', 'Bouxwiller', true],
    'rue sans numéro' => ['Rue de Torigni', null, 'Saint-Lô', true],
    'commune' => ['Hunspach', 'Hunspach', 'Hunspach', true],
    'générique' => ['Salle polyvalente', null, 'Frayssinhes', true],
    'hors les murs' => ['Hors les murs', 'à venir', null, true],
    'théâtre municipal' => ['Théâtre municipal', null, 'Châteaudun', true],
    'vrai nom' => ['Gare du Midi', '23, avenue Maréchal Foch', 'Biarritz', false],
    'Théâtre de la Ville' => ['Théâtre de la Ville', '2 place du Châtelet', 'Paris', false],
    'salle nommée' => ['Salle Biltoki', '24 route de St Ignace', 'Sare', false],
    'square nommé, avec son adresse' => ['Square Carpeaux', '23 rue Carpeaux', 'Paris', false],
    'nom qui commence par un numéro' => ['7-9 Comedy Room', '7, rue Louise Emilie de la Tour d’Auvergne', 'Paris', false],
    'La Place (Paris)' => ['La Place', '10 Passage de la Canopée', 'Paris', false],
    'numéro seul' => ['211', '27 galerie', 'Paris', true],
]);
