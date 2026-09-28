<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'hostname',
    'ip',
    'ssh_command',
    'username',
    'system',
    'typical_time',
    'does_backup',
    'observations',
    'review_script',
    'active',
    'sort_order',
    'netapp_volume',
    'nfs_root',
    'nfs_slug',

])]

class Server extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'does_backup' => 'boolean',
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function mounts(): HasMany
    {
        return $this->hasMany(Mount::class);
    }

    public function checks(): HasMany
    {
        return $this->hasMany(Check::class);
    }
}
