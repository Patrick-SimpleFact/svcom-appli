<?php

namespace App\Models;

use App\Enums\ActionJournal;
use App\Models\Concerns\IdentifiantNumerique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalAction extends Model
{
    use IdentifiantNumerique;

    public const UPDATED_AT = null;

    protected $table = 'journal_actions';

    protected $fillable = [
        'admin_id',
        'action',
        'cible_type',
        'cible_id',
        'avant',
        'apres',
    ];

    protected function casts(): array
    {
        return [
            'action' => ActionJournal::class,
            'avant' => 'array',
            'apres' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
