<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $data_hash
 * @property string $summary
 * @property string $status
 */
class AnalysisSnapshot extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['statistics' => 'array'];
    }
}
