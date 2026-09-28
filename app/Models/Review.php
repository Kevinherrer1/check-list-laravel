<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['date', 'responsible', 'notes', 'closed'])]

class Review extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'closed' => 'boolean',
        ];
    }

    public function checks(): HasMany
    {
        return $this->hasMany(Check::class);
    }
}
