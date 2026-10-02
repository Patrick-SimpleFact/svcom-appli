<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Les files techniques de la boîte de travail (SCHEMA-BDD §4, F7.10). */
enum FileATraiter: string implements HasLabel
{
    case DoublonProbable = 'doublon_probable';
    case FusionAControler = 'fusion_a_controler';
    case ATrier = 'a_trier';
    case AClasser = 'a_classer';
    case LieuAVerifier = 'lieu_a_verifier';
    case ArtisteAVerifier = 'artiste_a_verifier';

    public function getLabel(): string
    {
        return match ($this) {
            self::DoublonProbable => 'Doublons probables',
            self::FusionAControler => 'Fusions à contrôler',
            self::ATrier => 'À trier',
            self::AClasser => 'À classer',
            self::LieuAVerifier => 'Lieux à vérifier',
            self::ArtisteAVerifier => 'Artistes à vérifier',
        };
    }
}
