<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property string $id
 * @property string $type
 * @property string $model
 * @property string $status
 */
class ApiRun extends Model
{
    use HasUuids;

    public const WALLPAPER_GENERATION = 'wallpaper_generation';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'retryable' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function operation(): array
    {
        return [
            ...$this->only(['id', 'type', 'status', 'error_code', 'retryable']),
            'wallpaper_id' => $this->subject_type === (new Wallpaper)->getMorphClass() ? $this->subject_id : null,
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
