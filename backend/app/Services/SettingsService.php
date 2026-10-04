<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Typed application settings. Only keys declared in config('offshore.settings')
 * are readable/writable; unknown keys are rejected.
 */
class SettingsService
{
    private const CACHE_KEY = 'offshore.settings.all';

    /** @return array<string, array{group:string,type:string,default:mixed,rules:list<string>}> */
    public function registry(): array
    {
        return config('offshore.settings');
    }

    /** @return array<string, mixed> key => typed value */
    public function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            $stored = Setting::query()->pluck('value', 'key');
            $values = [];

            foreach ($this->registry() as $key => $def) {
                $values[$key] = $stored->has($key)
                    ? $this->cast($stored[$key], $def['type'])
                    : $def['default'];
            }

            return $values;
        });
    }

    public function get(string $key, mixed $fallback = null): mixed
    {
        return $this->all()[$key] ?? $fallback;
    }

    /** @return array<string, array<string, mixed>> grouped for the UI */
    public function grouped(): array
    {
        $values = $this->all();
        $groups = [];

        foreach ($this->registry() as $key => $def) {
            $in = collect($def['rules'] ?? [])->first(fn ($r) => is_string($r) && str_starts_with($r, 'in:'));
            $groups[$def['group']][$key] = ['value' => $values[$key], 'type' => $def['type'],
                'options' => $in ? explode(',', substr($in, 3)) : null, 'help' => $def['help'] ?? null];
        }

        return $groups;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function update(array $input, User $user): array
    {
        $registry = $this->registry();

        $unknown = array_diff(array_keys($input), array_keys($registry));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['settings' => ['Unknown setting(s): '.implode(', ', $unknown)]]);
        }

        $rules = [];
        foreach ($input as $key => $_) {
            $rules[$key] = $registry[$key]['rules'];
        }
        // Keys contain dots; validate against a flat structure.
        validator(collect($input)->mapWithKeys(fn ($v, $k) => [str_replace('.', '__', $k) => $v])->all(),
            collect($rules)->mapWithKeys(fn ($r, $k) => [str_replace('.', '__', $k) => $r])->all()
        )->validate();

        $before = $this->all();

        DB::transaction(function () use ($input, $registry, $user) {
            foreach ($input as $key => $value) {
                Setting::query()->updateOrCreate(
                    ['key' => $key],
                    [
                        'value' => $this->serialize($value, $registry[$key]['type']),
                        'type' => $registry[$key]['type'],
                        'group' => $registry[$key]['group'],
                        'updated_by' => $user->id,
                    ],
                );
            }
        });

        Cache::forget(self::CACHE_KEY);
        $after = $this->all();

        $changed = array_filter(array_keys($input), fn ($k) => $before[$k] !== $after[$k]);
        if ($changed !== []) {
            activity('settings')->causedBy($user)
                ->withProperties([
                    'old' => array_intersect_key($before, array_flip($changed)),
                    'attributes' => array_intersect_key($after, array_flip($changed)),
                ])
                ->event('updated')
                ->log('Settings updated');
        }

        return $after;
    }

    private function cast(?string $value, string $type): mixed
    {
        return match ($type) {
            'int' => (int) $value,
            'bool' => $value === '1',
            default => (string) $value, // decimals stay strings
        };
    }

    private function serialize(mixed $value, string $type): string
    {
        return match ($type) {
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
            'int' => (string) (int) $value,
            default => (string) ($value ?? ''),
        };
    }
}
