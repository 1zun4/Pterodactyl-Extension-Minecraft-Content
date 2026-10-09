<?php

declare(strict_types=1);

namespace MinecraftContent\Sources;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * JSON requests to the content APIs, cached briefly so browsing does not hit rate limits.
 */
final class Api
{
    public const string USER_AGENT = '1zun4/Pterodactyl-Extension-Minecraft-Content (github.com/1zun4/Pterodactyl-Extension-Minecraft-Content)';

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $headers
     * @return array<array-key, mixed>
     */
    public static function get(string $url, array $query = [], array $headers = [], int $ttl = 600): array
    {
        return self::cached('GET', $url, $query, $headers, $ttl);
    }

    /**
     * @param  array<array-key, mixed>  $body
     * @param  array<string, string>  $headers
     * @return array<array-key, mixed>
     */
    public static function post(string $url, array $body, array $headers = [], int $ttl = 0): array
    {
        return self::cached('POST', $url, $body, $headers, $ttl);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  array<string, string>  $headers
     * @return array<array-key, mixed>
     */
    private static function cached(string $method, string $url, array $data, array $headers, int $ttl): array
    {
        $request = static fn (): array => self::send($method, $url, $data, $headers);

        if ($ttl <= 0) {
            return $request();
        }

        return Cache::remember('ext:mcc:api:'.sha1($method.$url.json_encode($data).json_encode($headers)), $ttl, $request);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  array<string, string>  $headers
     * @return array<array-key, mixed>
     */
    private static function send(string $method, string $url, array $data, array $headers): array
    {
        $client = Http::withUserAgent(self::USER_AGENT)->withHeaders($headers)->acceptJson()->timeout(15)->connectTimeout(5);

        try {
            $response = $method === 'GET' ? $client->get($url, $data) : $client->post($url, $data);
            $json = $response->throw()->json();
        } catch (RequestException $exception) {
            throw new RuntimeException(sprintf('%s answered %d.', parse_url($url, PHP_URL_HOST), $exception->response->status()), previous: $exception);
        } catch (ConnectionException $exception) {
            throw new RuntimeException(sprintf('%s could not be reached.', parse_url($url, PHP_URL_HOST)), previous: $exception);
        }

        return is_array($json) ? $json : [];
    }
}
