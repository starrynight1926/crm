<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    protected $fillable = ['key', 'value'];

    protected static array $cache = [];

    public static function get(string $key, ?string $default = null): ?string
    {
        if (! array_key_exists($key, self::$cache)) {
            self::$cache[$key] = self::where('key', $key)->value('value');
        }
        return self::$cache[$key] ?? $default;
    }

    public static function set(string $key, ?string $value): void
    {
        self::updateOrCreate(['key' => $key], ['value' => $value]);
        self::$cache[$key] = $value;
    }
}
