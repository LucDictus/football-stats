<?php

declare(strict_types=1);

namespace App;

/**
 * Minimal client for https://api.football-data.org (v4).
 *
 * - Responses are cached on disk.
 * - Optional throttling ($minInterval seconds between real requests) for batch imports;
 *   the free plan allows 10 requests per minute.
 * - On HTTP 429 it waits for the counter reset and retries.
 */
final class FootballDataClient
{
    private const BASE_URL = 'https://api.football-data.org/v4';
    private const MAX_RETRIES = 3;

    private float $lastRequestAt = 0.0;

    /** Whether the last get() was served from the cache. */
    public bool $lastFromCache = false;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $cacheDir,
        private readonly int $cacheTtl = 300,
        private readonly float $minInterval = 0.0,
    ) {
        if (!is_dir($this->cacheDir) && !mkdir($this->cacheDir, 0775, true) && !is_dir($this->cacheDir)) {
            throw new FootballDataException("Cannot create cache directory {$this->cacheDir}");
        }
    }

    public function competition(string $code): array
    {
        return $this->get("/competitions/{$code}");
    }

    /** Filters: season, matchday, stage, status, group, dateFrom, dateTo */
    public function matches(string $code, array $filters = []): array
    {
        return $this->get("/competitions/{$code}/matches", $filters);
    }

    /** Filters: season, matchday, date */
    public function standings(string $code, array $filters = []): array
    {
        return $this->get("/competitions/{$code}/standings", $filters);
    }

    /** Filters: season, limit */
    public function scorers(string $code, array $filters = []): array
    {
        return $this->get("/competitions/{$code}/scorers", $filters);
    }

    public function get(string $path, array $query = []): array
    {
        $url = self::BASE_URL . $path . ($query !== [] ? '?' . http_build_query($query) : '');
        $cacheFile = $this->cacheDir . '/' . sha1($url) . '.json';

        if (is_file($cacheFile) && time() - filemtime($cacheFile) < $this->cacheTtl) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                $this->lastFromCache = true;

                return $cached;
            }
        }

        $this->lastFromCache = false;

        for ($attempt = 1; ; $attempt++) {
            $this->throttle();
            [$status, $body, $headers] = $this->request($url);

            if ($status === 429 && $attempt <= self::MAX_RETRIES) {
                sleep(max(1, (int) ($headers['x-requestcounter-reset'] ?? 60)) + 1);
                continue;
            }
            break;
        }

        $data = json_decode($body, true);

        if ($status !== 200) {
            $message = is_array($data) && isset($data['message']) ? $data['message'] : "HTTP {$status}";
            throw new FootballDataException($message, $status);
        }

        if (!is_array($data)) {
            throw new FootballDataException('Unexpected (non-JSON) response from the API.');
        }

        file_put_contents($cacheFile, $body);

        return $data;
    }

    /** @return array{0: int, 1: string, 2: array<string, string>} */
    private function request(string $url): array
    {
        $headers = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['X-Auth-Token: ' . $this->apiKey],
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
        ]);

        $body = curl_exec($ch);
        $this->lastRequestAt = microtime(true);

        if ($body === false) {
            throw new FootballDataException('Request failed: ' . curl_error($ch));
        }

        return [(int) curl_getinfo($ch, CURLINFO_HTTP_CODE), $body, $headers];
    }

    private function throttle(): void
    {
        $wait = $this->lastRequestAt + $this->minInterval - microtime(true);
        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }
    }
}
