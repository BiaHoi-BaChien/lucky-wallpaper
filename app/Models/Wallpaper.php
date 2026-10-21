<?php

namespace App\Models;

use App\Services\KigakuPolicy;
use Database\Factories\WallpaperFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property string|null $composition_zone
 * @property string|null $color_wu_xing
 * @property string|null $symbolism
 * @property int|null $prize_vnd
 * @property int|null $purchase_count
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

    public const COMPOSITION_ZONE_LABELS = [
        'top_left' => '左上',
        'top' => '上部',
        'top_right' => '右上',
        'left' => '左',
        'center' => '中央',
        'right' => '右',
        'bottom_left' => '左下',
        'bottom' => '下部',
        'bottom_right' => '右下',
    ];

    public const COMPOSITION_ZONES = [
        'top_left',
        'top',
        'top_right',
        'left',
        'center',
        'right',
        'bottom_left',
        'bottom',
        'bottom_right',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'target_date' => 'date:Y-m-d',
            'prize_vnd' => 'integer',
            'purchase_count' => 'integer',
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
            ($kigaku = $this->kigaku()) === null ? null : KigakuPolicy::description($kigaku),
        ]));
    }

    /** @return BelongsTo<CompositionProposal, $this> */
    public function chosenProposal(): BelongsTo
    {
        return $this->belongsTo(CompositionProposal::class, 'chosen_proposal_id');
    }

    public function kigaku(): ?array
    {
        $proposal = $this->chosenProposal;
        if ($proposal === null || $proposal->wallpaper_id !== $this->id || $proposal->status !== 'approved') {
            return null;
        }
        $kigaku = $proposal->calendar_context['kigaku'] ?? null;

        return is_array($kigaku) ? $kigaku : null;
    }

    /** @param Builder<Wallpaper> $query */
    public function scopeFiltered(Builder $query, array $filters): void
    {
        $query->when($filters['date_from'] ?? null, fn (Builder $q, string $date) => $q->where('target_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $q, string $date) => $q->where('target_date', '<=', $date));
        $direction = $filters['direction'] ?? null;
        if ($direction === null || $direction === '') {
            return;
        }
        $matches = function (Builder $proposal) use ($direction): void {
            $proposal->where('status', 'approved')->whereColumn('composition_proposals.wallpaper_id', 'wallpapers.id');
            if ($direction === 'legacy') {
                $proposal->where(function (Builder $q): void {
                    $q->whereIn('calendar_context->kigaku->status', ['none', 'undetermined'])
                        ->orWhere(fn (Builder $q) => $q->where('calendar_context->kigaku->status', 'auspicious')
                            ->whereIn('calendar_context->kigaku->primary_direction', array_keys(KigakuPolicy::DIRECTIONS)));
                });
            } elseif (isset(KigakuPolicy::DIRECTIONS[$direction])) {
                $proposal->where('calendar_context->kigaku->status', 'auspicious')
                    ->where('calendar_context->kigaku->primary_direction', $direction);
            } else {
                $proposal->where('calendar_context->kigaku->status', $direction);
            }
        };
        if ($direction === 'legacy') {
            $query->whereDoesntHave('chosenProposal', $matches);
        } else {
            $query->whereHas('chosenProposal', $matches);
        }
    }
}
