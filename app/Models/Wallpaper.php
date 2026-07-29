<?php

namespace App\Models;

use Database\Factories\WallpaperFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property Carbon $target_date
 * @property string|null $title
 * @property string|null $art_style
 * @property string|null $conclusion
 * @property string|null $overview
 * @property string|null $composition
 * @property string|null $color_wu_xing
 * @property string|null $symbolism
 * @property int|null $prize_vnd
 * @property string|null $notion_page_id
 * @property int|null $chosen_proposal_id
 * @property string|null $image_disk
 * @property string|null $image_path
 * @property string|null $image_mime
 * @property int|null $image_bytes
 * @property string|null $image_sha256
 * @property string $state
 * @property array<int, string>|null $warnings
 */
class Wallpaper extends Model
{
    /** @use HasFactory<WallpaperFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'target_date' => 'date:Y-m-d',
            'prize_vnd' => 'integer',
            'image_bytes' => 'integer',
            'warnings' => 'array',
            'result_synced_at' => 'datetime',
        ];
    }

    /** @return HasMany<CompositionProposal, $this> */
    public function proposals(): HasMany
    {
        return $this->hasMany(CompositionProposal::class);
    }

    public function compositionDetails(): string
    {
        return implode("\n\n", array_filter([
            $this->conclusion,
            "概要\n".$this->overview,
            "配置\n".$this->composition,
            "色彩・五行\n".$this->color_wu_xing,
            "象徴意図\n".$this->symbolism,
        ]));
    }
}
