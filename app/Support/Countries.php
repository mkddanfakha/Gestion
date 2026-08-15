<?php

namespace App\Support;

class Countries
{
    public const SENEGAL_CODE = 'SN';

    /** @var list<array{code: string, name: string}>|null */
    private static ?array $list = null;

    /** @return list<array{code: string, name: string}> */
    public static function all(): array
    {
        if (self::$list !== null) {
            return self::$list;
        }

        $path = resource_path('data/countries.json');
        $contents = is_file($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            self::$list = [
                ['code' => self::SENEGAL_CODE, 'name' => 'Sénégal'],
            ];

            return self::$list;
        }

        $decoded = json_decode($contents, true);

        if (!is_array($decoded)) {
            self::$list = [
                ['code' => self::SENEGAL_CODE, 'name' => 'Sénégal'],
            ];

            return self::$list;
        }

        self::$list = array_values(array_filter($decoded, function ($item) {
            return is_array($item)
                && isset($item['code'], $item['name'])
                && is_string($item['code'])
                && is_string($item['name'])
                && strlen($item['code']) === 2;
        }));

        return self::$list;
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_column(self::all(), 'code');
    }

    public static function isValidCode(?string $code): bool
    {
        if ($code === null || $code === '') {
            return false;
        }

        return in_array(strtoupper($code), self::codes(), true);
    }

    public static function name(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        $normalized = strtoupper($code);

        foreach (self::all() as $country) {
            if ($country['code'] === $normalized) {
                return $country['name'];
            }
        }

        return null;
    }
}
