<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * Runtime-editable settings stored in system_settings, layered over config('jobhunter.defaults').
 * Secret values (API keys) are stored encrypted with the application key.
 */
class SettingsService
{
    public const SECRET_KEYS = ['anthropic_api_key', 'adzuna_app_id', 'adzuna_app_key'];

    private const CACHE_KEY = 'jobhunter.settings';

    public function all(): array
    {
        $stored = Cache::remember(self::CACHE_KEY, 300, function () {
            return SystemSetting::query()
                ->whereNotIn('key', self::SECRET_KEYS)
                ->get()
                ->mapWithKeys(fn (SystemSetting $s) => [$s->key => json_decode((string) $s->value, true)])
                ->all();
        });

        return array_replace(config('jobhunter.defaults'), $stored);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->all(), $key, $default);
    }

    public function set(string $key, mixed $value): void
    {
        if (in_array($key, self::SECRET_KEYS, true)) {
            $this->setSecret($key, $value);

            return;
        }

        SystemSetting::updateOrCreate(['key' => $key], ['value' => json_encode($value), 'is_encrypted' => false]);
        Cache::forget(self::CACHE_KEY);
    }

    public function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value);
        }
    }

    public function setSecret(string $key, ?string $value): void
    {
        if (blank($value)) {
            SystemSetting::where('key', $key)->delete();

            return;
        }

        SystemSetting::updateOrCreate(['key' => $key], ['value' => Crypt::encryptString($value), 'is_encrypted' => true]);
    }

    public function secret(string $key): ?string
    {
        $row = SystemSetting::where('key', $key)->first();
        if (! $row || blank($row->value)) {
            return null;
        }

        try {
            return Crypt::decryptString($row->value);
        } catch (\Throwable) {
            return null;
        }
    }

    public function hasSecret(string $key): bool
    {
        return SystemSetting::where('key', $key)->whereNotNull('value')->exists();
    }

    public function anthropicApiKey(): ?string
    {
        return $this->secret('anthropic_api_key') ?: config('jobhunter.anthropic.api_key');
    }

    public function adzunaCredentials(): ?array
    {
        $id = $this->secret('adzuna_app_id') ?: config('jobhunter.adzuna.app_id');
        $key = $this->secret('adzuna_app_key') ?: config('jobhunter.adzuna.app_key');

        return ($id && $key) ? ['app_id' => $id, 'app_key' => $key] : null;
    }

    /** Non-setting runtime state (e.g. last digest date). Stored in the same table, prefixed. */
    public function state(string $key, mixed $default = null): mixed
    {
        $row = SystemSetting::where('key', 'state.'.$key)->first();

        return $row ? json_decode((string) $row->value, true) : $default;
    }

    public function putState(string $key, mixed $value): void
    {
        SystemSetting::updateOrCreate(['key' => 'state.'.$key], ['value' => json_encode($value)]);
    }
}
