<?php
declare(strict_types=1);

namespace Oracle\Source;

use Oracle\Http;
use RuntimeException;

/**
 * Источник №1: цены и рыночные показатели (CoinGecko API).
 * Возвращает сырое тело ответа + нормализованные строки metrics.
 */
final class CoinGeckoSource
{
    public function __construct(
        private Http $http,
        private string $url,
        private ?string $fixture,
    ) {}

    /** @return array{raw: string, rows: array<string, array>} */
    public function fetch(): array
    {
        $raw = $this->http->get($this->url, $this->fixture);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('CoinGecko: invalid JSON');
        }

        $rows = [];
        foreach ($data as $coin) {
            if (!isset($coin['id'], $coin['current_price'])) {
                continue; // повреждённая запись - пропускаем, не падаем
            }
            $rows[$coin['id']] = [
                'price_usd'      => (float)$coin['current_price'],
                'change_24h_pct' => isset($coin['price_change_percentage_24h_in_currency'])
                    ? (float)$coin['price_change_percentage_24h_in_currency']
                    : (isset($coin['price_change_percentage_24h']) ? (float)$coin['price_change_percentage_24h'] : null),
                'change_7d_pct'  => isset($coin['price_change_percentage_7d_in_currency'])
                    ? (float)$coin['price_change_percentage_7d_in_currency'] : null,
                'high_24h'       => isset($coin['high_24h']) ? (float)$coin['high_24h'] : null,
                'low_24h'        => isset($coin['low_24h']) ? (float)$coin['low_24h'] : null,
                'volume_24h'     => isset($coin['total_volume']) ? (float)$coin['total_volume'] : null,
                'market_cap'     => isset($coin['market_cap']) ? (float)$coin['market_cap'] : null,
                'captured_at'    => $coin['last_updated'] ?? gmdate('c'),
            ];
        }
        if ($rows === []) {
            throw new RuntimeException('CoinGecko: no usable rows');
        }
        return ['raw' => $raw, 'rows' => $rows];
    }
}
