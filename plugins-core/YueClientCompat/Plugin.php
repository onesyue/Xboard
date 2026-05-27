<?php

namespace Plugin\YueClientCompat;

use App\Services\Plugin\AbstractPlugin;
use Illuminate\Http\Request;

class Plugin extends AbstractPlugin
{
    private const DEFAULTS = [
        'meta' => 'meta/1.19.9',
        'verge' => 'verge/2.0.0',
        'flclash' => 'flclash/0.8.0',
        'nekobox' => 'nekobox/1.2.7',
        'clashmetaforandroid' => 'clashmetaforandroid/2.9.0',
        'stash' => 'stash/3.3.0',
        'sing-box' => 'sing-box/1.13.0',
    ];

    public function boot(): void
    {
        // Run after risk-control and browser-preview hooks so they still see the original request.
        $this->listen('client.subscribe.before', function (): void {
            $this->normalizeFlag(request());
        }, 40);
    }

    private function normalizeFlag(Request $request): void
    {
        $rawFlag = $request->query('flag');
        $source = filled($rawFlag) ? (string) $rawFlag : (string) $request->header('User-Agent', '');

        if ($source === '') {
            return;
        }

        $source = strtolower(trim(rawurldecode($source)));
        $source = str_replace('_', '-', $source);
        $version = $this->extractVersion($source);
        $normalized = $this->mapClient($source, $version);

        if ($normalized === null) {
            return;
        }

        $request->query->set('flag', $normalized);
        $request->request->set('flag', $normalized);
    }

    private function extractVersion(string $source): ?string
    {
        if (preg_match('/(?:^|[\\s\\/])v?(\\d+(?:\\.\\d+){0,2})(?:\\b|$)/', $source, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function mapClient(string $source, ?string $version): ?string
    {
        if (
            str_contains($source, 'sing-box')
            || preg_match('/\bsingbox\b/', $source)
            || preg_match('/\b(?:sfa|sfi)\b/', $source)
        ) {
            return $this->withVersion('sing-box', $version);
        }

        if (str_contains($source, 'quantumult x') || str_contains($source, 'quantumult-x')) {
            return $version ? "quantumult-x/{$version}" : 'quantumult-x';
        }

        if (preg_match('/\\bstash\\b/', $source)) {
            return $this->withVersion('stash', $version);
        }

        if (str_contains($source, 'clashmetaforandroid') || str_contains($source, 'clash meta for android')) {
            return $this->withVersion('clashmetaforandroid', $version);
        }

        if (str_contains($source, 'flclash')) {
            return $this->withVersion('flclash', $version);
        }

        if (str_contains($source, 'nekobox')) {
            return $this->withVersion('nekobox', $version);
        }

        if (str_contains($source, 'clash verge') || preg_match('/\\bverge\\b/', $source)) {
            return $this->withVersion('verge', $version);
        }

        if (
            str_contains($source, 'mihomo')
            || str_contains($source, 'clash-meta')
            || str_contains($source, 'clash meta')
            || preg_match('/\\bmeta\\b/', $source)
        ) {
            return $this->withVersion('meta', $version);
        }

        return null;
    }

    private function withVersion(string $client, ?string $version): string
    {
        return $version ? "{$client}/{$version}" : self::DEFAULTS[$client];
    }
}
