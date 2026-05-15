<?php

namespace SubKit\Support;

class CheckoutSuccessUrlSigner
{
    /** Avoid parsing unusually large query strings when normalizing signed URLs. */
    private const MAX_QUERY_STRING_LENGTH = 2048;

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

        $queryString = (string) ($parts['query'] ?? '');

        if (strlen($queryString) > self::MAX_QUERY_STRING_LENGTH) {
            return $url;
        }

        $query = $this->parseQueryParameters($queryString);

        if ($query === null) {
            return $url;
        }

        unset($query['signature']);

        $baseUrl = $this->baseUrl($parts);
        $normalizedQuery = http_build_query($query);
        $unsignedUrl = $normalizedQuery === '' ? $baseUrl : "{$baseUrl}?{$normalizedQuery}";
        $signature = hash_hmac('sha256', $unsignedUrl, $this->signingKey());
        $signedUrl = "{$unsignedUrl}".($normalizedQuery === '' ? '?' : '&')."signature={$signature}";

        if (! is_string($fragment) || $fragment === '') {
            return $signedUrl;
        }

        return "{$signedUrl}#{$fragment}";
    }

    private function containsProviderPlaceholder(string $url): bool
    {
        return preg_match('/\{[A-Za-z0-9_]+\}/', $url) === 1;
    }

    private function alreadySigned(string $url): bool
    {
        $query = parse_url($url, PHP_URL_QUERY);

        if (! is_string($query) || $query === '') {
            return false;
        }

        $parameters = $this->parseQueryParameters($query);

        if ($parameters === null) {
            return false;
        }

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

    /**
     * @return array<string, string>|null
     */
    private function parseQueryParameters(string $queryString): ?array
    {
        if ($queryString === '') {
            return [];
        }

        $parameters = [];

        foreach (explode('&', $queryString) as $segment) {
            if ($segment === '') {
                continue;
            }

            [$rawKey, $rawValue] = array_pad(explode('=', $segment, 2), 2, '');
            $key = rawurldecode($rawKey);

            if (
                $key === ''
                || str_contains($key, '[')
                || str_contains($key, ']')
                || array_key_exists($key, $parameters)
            ) {
                return null;
            }

            $parameters[$key] = rawurldecode($rawValue);
        }

        return $parameters;
    }
}
