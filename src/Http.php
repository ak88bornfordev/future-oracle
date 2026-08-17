<?php
declare(strict_types=1);

namespace Oracle;

use RuntimeException;

/**
 * HTTP-клиент с офлайн-режимом.
 *
 * В live-режиме ходит в сеть через curl.
 * В fixtures-режиме читает сохранённый реальный снапшот ответа
 * (data/fixtures/*) - удобно для демо и тестов без сети.
 */
final class Http
{
    public function __construct(private bool $useFixtures = false) {}

    public function get(string $url, ?string $fixturePath = null): string
    {
        if ($this->useFixtures) {
            if ($fixturePath === null || !is_file($fixturePath)) {
                throw new RuntimeException("Fixture not found for $url");
            }
            return file_get_contents($fixturePath);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_USERAGENT      => 'FutureOracle/1.0 (+test task prototype)',
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($body === false || $code >= 400) {
            $err = curl_error($ch) ?: "HTTP $code";
            curl_close($ch);
            throw new RuntimeException("Fetch failed for $url: $err");
        }
        curl_close($ch);
        return $body;
    }
}
