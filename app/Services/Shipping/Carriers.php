<?php

namespace App\Services\Shipping;

/**
 * The carriers a seller can name, and the ONLY source of tracking links. A link is built when the carrier is on the
 * allowlist in config('emall.shipping.carriers') and the tracking number matches that carrier's pattern; anything else
 * (another carrier, "Own delivery", a strange number) is shown as plain text with no link.
 */
class Carriers
{
    /** @return array<string, string> key => display name */
    public function options(): array
    {
        return collect((array) config('emall.shipping.carriers'))->map(fn ($carrier) => $carrier['name'])->all();
    }

    public function name(?string $key): ?string
    {
        return $key === null ? null : ((array) config("emall.shipping.carriers.{$key}"))['name'] ?? null;
    }

    public function trackingUrl(?string $key, ?string $number): ?string
    {
        $carrier = $key !== null ? config("emall.shipping.carriers.{$key}") : null;
        $number = trim((string) $number);

        if (! is_array($carrier) || $number === '' || ! preg_match($carrier['pattern'], $number)) {
            return null;
        }

        $url = str_replace('{n}', rawurlencode($number), $carrier['url']);

        // Belt and braces: whatever the template, only https links to the template's own host leave here.
        return str_starts_with($url, 'https://') && parse_url($url, PHP_URL_HOST) === parse_url($carrier['url'], PHP_URL_HOST) ? $url : null;
    }
}
