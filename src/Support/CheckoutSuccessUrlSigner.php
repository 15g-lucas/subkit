<?php

namespace SubKit\Support;

class CheckoutSuccessUrlSigner
{
    public function sign(string $url): string
    {
        if (
            $url === ''
            || $url === '#'
            || $this->signingKey() === ''
            || $this->containsProviderPlaceholder($url)
            || $this->alreadySigned($url)
            || ! $this->isLocalUrl($url)
        ) {
            return $url;
        }

        $fragment = parse_url($url, PHP_URL_FRAGMENT);
        $urlWithoutFragment = strtok($url, '#') ?: $url;
        $parts = parse_url($urlWithoutFragment);

        if ($parts === false) {
            return $url;
        }

        parse_str($parts['query'] ?? '', $query);
        unset($query['signature']);

        $baseUrl = $this->baseUrl($parts);
        $queryString = http_build_query($query);
        $unsignedUrl = $queryString === '' ? $baseUrl : "{$baseUrl}?{$queryString}";
        $signature = hash_hmac('sha256', $unsignedUrl, $this->signingKey());
        $signedUrl = "{$unsignedUrl}".($queryString === '' ? '?' : '&')."signature={$signature}";

        if (! is_string($fragment) || $fragment === '') {
            return $signedUrl;
        }

        return "{$signedUrl}#{$fragment}";
    }

    private function containsProviderPlaceholder(string $url): bool
    {
        return preg_match('/\{[A-Z0-9_]+\}/', $url) === 1;
    }

    private function alreadySigned(string $url): bool
    {
        $query = parse_url($url, PHP_URL_QUERY);

        if (! is_string($query) || $query === '') {
            return false;
        }

        parse_str($query, $parameters);

        return array_key_exists('signature', $parameters);
    }

    private function isLocalUrl(string $url): bool
    {
        $target = parse_url($url);
        $application = parse_url(url('/'));

        if ($target === false || $application === false) {
            return false;
        }

        return ($target['scheme'] ?? null) === ($application['scheme'] ?? null)
            && ($target['host'] ?? null) === ($application['host'] ?? null)
            && ($target['port'] ?? null) === ($application['port'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $parts
     */
    private function baseUrl(array $parts): string
    {
        $scheme = $parts['scheme'] ?? 'http';
        $host = $parts['host'] ?? 'localhost';
        $port = isset($parts['port']) ? ":{$parts['port']}" : '';
        $path = $parts['path'] ?? '/';

        return "{$scheme}://{$host}{$port}{$path}";
    }

    private function signingKey(): string
    {
        return (string) config('app.key');
    }
}
