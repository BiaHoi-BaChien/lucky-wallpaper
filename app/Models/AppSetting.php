<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $key
 * @property string|null $value
 */
class AppSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    public static function read(string $key): ?string
    {
        return static::query()->find($key)?->value;
    }

    public static function write(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
