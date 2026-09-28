<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'review_id',
    'server_id',
    'powered_on',
    'mounts_status',
    'mounts_details',
    'backup',
    'generated_at',
    'backup_date',
    'size',
    'root_cause',
    'notified',
    'channel',
    'observations',
    'review_result',
    'reviewed_by',
    'ssh_status',
    'ssh_job_id',
    'ssh_error',
    'ssh_started_at',
    'ssh_finished_at',
])]

class Check extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'mounts_details' => 'array',
            'backup_date' => 'date:Y-m-d',
            'ssh_started_at' => 'datetime',
            'ssh_finished_at' => 'datetime',
        ];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);

    }
}
