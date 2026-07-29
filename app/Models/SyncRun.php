<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $type
 * @property string $status
 * @property Carbon|null $started_at
 */
class SyncRun extends Model
{
    use HasUuids;

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'warnings' => 'array',
            'retryable' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'checkpoint_at' => 'datetime',
        ];
    }
}
