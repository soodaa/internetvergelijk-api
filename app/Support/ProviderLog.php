<?php

namespace App\Support;

class ProviderLog
{
    public static function context(string $provider, ?\stdClass $address = null, array $extra = []): array
    {
        $base = ['provider' => $provider];

        if ($address !== null) {
            $base['postcode'] = $address->postcode ?? null;
            $base['number'] = $address->number ?? null;
            $base['extension'] = $address->extension ?? null;
        }

        return array_merge($base, $extra);
    }
}
