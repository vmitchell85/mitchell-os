<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A setting shared by the whole household, such as its food rules.
 *
 * @property int $id
 * @property string $key
 * @property string|null $value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['key', 'value'])]
class HouseholdSetting extends Model
{
    public const string FoodRules = 'food_rules';

    public const string WeatherZip = 'weather_zip';

    public const string HomeAddress = 'home_address';

    public const string DefaultWeatherZip = '43081';

    public static function get(string $key): ?string
    {
        return static::where('key', $key)->value('value');
    }

    public static function put(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /**
     * The zip code the dashboard shows weather for.
     */
    public static function weatherZip(): string
    {
        return trim((string) static::get(self::WeatherZip)) ?: self::DefaultWeatherZip;
    }

    /**
     * Where drive times start from, or null when the household hasn't set it.
     */
    public static function homeAddress(): ?string
    {
        return trim((string) static::get(self::HomeAddress)) ?: null;
    }

    /**
     * The household's food rules as a prompt section, or null when there are none.
     */
    public static function foodRulesPrompt(): ?string
    {
        $rules = trim((string) static::get(self::FoodRules));

        return $rules !== '' ? "Household food rules (always follow these):\n{$rules}" : null;
    }
}
