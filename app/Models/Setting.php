<?php

namespace App\Models;

use App\Services\Setting\StoreSettingService;
use App\Services\Shipping\ShippingRateService;
use App\Services\Storefront\HomepageService;
use App\Services\Storefront\StorefrontContentService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class Setting extends Model
{
    protected $fillable = [
        'group',
        'key',
        'value',
        'type',
        'is_public',
    ];

    protected static function booted(): void
    {
        $clearCaches = static function (self $setting): void {
            Cache::forget(StoreSettingService::CACHE_KEY);
            HomepageService::clearCache();
            StorefrontContentService::clearCache();

            if (in_array($setting->group, ['rajaongkir', 'store'], true)) {
                ShippingRateService::invalidateCache();
            }
        };

        static::saved($clearCaches);
        static::deleted($clearCaches);
    }

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
        ];
    }

    public function decodedValue(): mixed
    {
        if ($this->value === null) {
            return null;
        }

        return match ($this->type) {
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $this->value,
            'decimal' => (float) $this->value,
            'json' => json_decode($this->value, true),
            'encrypted' => $this->value !== '' ? Crypt::decryptString($this->value) : '',
            default => $this->value,
        };
    }

    public static function encodeValue(mixed $value, string $type): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
            'json' => json_encode($value, JSON_UNESCAPED_UNICODE),
            'encrypted' => (string) $value !== '' ? Crypt::encryptString((string) $value) : '',
            default => (string) $value,
        };
    }
}
