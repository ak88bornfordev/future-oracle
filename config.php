<?php
/**
 * Future Oracle - конфигурация.
 */
return [
    'db_path' => __DIR__ . '/data/oracle.sqlite',

    // Источники данных (минимум 2 реальных: цены + новости)
    'sources' => [
        'coingecko' => [
            'name' => 'CoinGecko API',
            'type' => 'prices',
            'url'  => 'https://api.coingecko.com/api/v3/coins/markets'
                    . '?vs_currency=usd&ids=bitcoin,ethereum,solana,ripple,cardano'
                    . '&price_change_percentage=24h,7d',
            'fixture' => __DIR__ . '/data/fixtures/coingecko_markets.json',
        ],
        'cointelegraph' => [
            'name' => 'Cointelegraph RSS',
            'type' => 'news',
            'url'  => 'https://cointelegraph.com/rss',
            'fixture' => __DIR__ . '/data/fixtures/cointelegraph_rss.xml',
        ],
    ],

    // Активы, по которым делаем прогноз
    'assets' => [
        'bitcoin'  => ['symbol' => 'BTC', 'name' => 'Bitcoin',  'keywords' => ['bitcoin', 'btc']],
        'ethereum' => ['symbol' => 'ETH', 'name' => 'Ethereum', 'keywords' => ['ethereum', 'eth', 'ether']],
        'solana'   => ['symbol' => 'SOL', 'name' => 'Solana',   'keywords' => ['solana', 'sol']],
        'ripple'   => ['symbol' => 'XRP', 'name' => 'XRP',      'keywords' => ['xrp', 'ripple']],
        'cardano'  => ['symbol' => 'ADA', 'name' => 'Cardano',  'keywords' => ['cardano', 'ada']],
    ],

    // Веса факторов скоринга (сумма = 1). Меняются здесь - алгоритм прозрачен.
    'weights' => [
        'momentum_24h'   => 0.35, // импульс за сутки
        'momentum_7d'    => 0.20, // недельный тренд
        'news_sentiment' => 0.30, // тональность новостей
        'range_position' => 0.15, // положение цены в дневном диапазоне
    ],

    'horizon_hours' => 24,          // горизонт прогноза
    'flat_threshold' => 0.15,       // |score| ниже - прогноз "боковик"
    'hit_tolerance_pct' => 0.3,     // порог движения, чтобы засчитать "рост"/"падение"
];
