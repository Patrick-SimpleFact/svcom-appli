<?php

namespace App\Models;

use App\Models\Concerns\IdentifiantNumerique;
use Illuminate\Database\Eloquent\Model;

class Genre extends Model
{
    use IdentifiantNumerique;

    protected $fillable = [
        'slug',
        'libelle',
        'ordre',
    ];

    protected function casts(): array
    {
        return [
            'ordre' => 'integer',
        ];
    }
}
