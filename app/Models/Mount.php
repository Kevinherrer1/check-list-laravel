<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['path',
    'server_id',
])]

class Mount extends Model
{
    use HasFactory;

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
