<?php
declare(strict_types=1);

namespace Oracle\Source;

use Oracle\Http;
use RuntimeException;
use SimpleXMLElement;

/**
 * Источник №2: новости (Cointelegraph RSS).
 */
final class CointelegraphSource
{
    public function __construct(
        private Http $http,
        private string $url,
        private ?string $fixture,
    ) {}

    /** @return array{raw: string, items: list<array>} */
    public function fetch(): array
    {
        $raw = $this->http->get($this->url, $this->fixture);
        $xml = @simplexml_load_string($raw);
        if (!$xml instanceof SimpleXMLElement || !isset($xml->channel->item)) {
            throw new RuntimeException('Cointelegraph: invalid RSS');
        }

        $items = [];
        foreach ($xml->channel->item as $item) {
            $guid = trim((string)$item->guid) ?: trim((string)$item->link);
            if ($guid === '') {
                continue;
            }
            $items[] = [
                'guid'         => $guid,
                'title'        => trim((string)$item->title),
                'summary'      => trim(strip_tags((string)$item->description)),
                'url'          => trim((string)$item->link) ?: $guid,
                'published_at' => ($t = strtotime((string)$item->pubDate)) ? gmdate('c', $t) : null,
            ];
        }
        if ($items === []) {
            throw new RuntimeException('Cointelegraph: no items');
        }
        return ['raw' => $raw, 'items' => $items];
    }
}
