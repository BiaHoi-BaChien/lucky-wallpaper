<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $wallpaper_id
 * @property int $sequence
 * @property string $status
 * @property string $title
 * @property string $art_style
 * @property string $conclusion
 * @property string $overview
 * @property string $composition
 * @property string $color_wu_xing
 * @property string $symbolism
 * @property string $input_hash
 */
class CompositionProposal extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['calendar_context' => 'array'];
    }

    /** @return BelongsTo<Wallpaper, $this> */
    public function wallpaper(): BelongsTo
    {
        return $this->belongsTo(Wallpaper::class);
    }
}
