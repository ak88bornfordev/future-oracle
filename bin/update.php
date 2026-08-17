<?php
declare(strict_types=1);

/**
 * Пайплайн обновления Future Oracle.
 *
 * Использование:
 *   php bin/update.php               живой режим (ходит в CoinGecko и Cointelegraph)
 *   php bin/update.php --fixtures    офлайн-режим: реальный снапшот из data/fixtures
 *   php bin/update.php --fixtures --seed-eval-demo
 *                                    дополнительно создаёт один "созревший" прогноз
 *                                    сутками ранее, чтобы показать блок самопроверки
 *
 * Шаги: fetch -> raw_records -> normalize (metrics, news) -> evaluate old -> predict new.
 */

require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/Http.php';
require __DIR__ . '/../src/Source/CoinGeckoSource.php';
require __DIR__ . '/../src/Source/CointelegraphSource.php';
require __DIR__ . '/../src/SentimentAnalyzer.php';
require __DIR__ . '/../src/Predictor.php';
require __DIR__ . '/../src/Evaluator.php';

use Oracle\Database;
use Oracle\Evaluator;
use Oracle\Http;
use Oracle\Predictor;
use Oracle\SentimentAnalyzer;
use Oracle\Source\CoinGeckoSource;
use Oracle\Source\CointelegraphSource;

$config = require __DIR__ . '/../config.php';
$useFixtures = in_array('--fixtures', $argv, true);
$seedEvalDemo = in_array('--seed-eval-demo', $argv, true);
$mode = $useFixtures ? 'fixtures' : 'live';
$now = gmdate('c');

$db = new Database($config['db_path'], __DIR__ . '/../schema.sql');
$pdo = $db->pdo();
[$sourceIds, $assetIds] = $db->syncDictionaries($config);

$pdo->prepare('INSERT INTO update_runs (started_at, mode) VALUES (?,?)')->execute([$now, $mode]);
$runId = (int)$pdo->lastInsertId();
echo "[run #$runId] mode=$mode started $now\n";

$http = new Http($useFixtures);

// ---------- 1. FETCH + RAW ----------
$gecko = new CoinGeckoSource($http, $config['sources']['coingecko']['url'], $config['sources']['coingecko']['fixture']);
$prices = $gecko->fetch();
$pdo->prepare('INSERT INTO raw_records (run_id, source_id, fetched_at, payload) VALUES (?,?,?,?)')
    ->execute([$runId, $sourceIds['coingecko'], $now, $prices['raw']]);
echo '  prices: ' . count($prices['rows']) . " assets\n";

$ct = new CointelegraphSource($http, $config['sources']['cointelegraph']['url'], $config['sources']['cointelegraph']['fixture']);
$news = $ct->fetch();
$pdo->prepare('INSERT INTO raw_records (run_id, source_id, fetched_at, payload) VALUES (?,?,?,?)')
    ->execute([$runId, $sourceIds['cointelegraph'], $now, $news['raw']]);
echo '  news: ' . count($news['items']) . " items\n";

// ---------- 2. NORMALIZE ----------
$insMetric = $pdo->prepare(
    'INSERT INTO metrics (run_id, asset_id, captured_at, price_usd, change_24h_pct, change_7d_pct,
                          high_24h, low_24h, volume_24h, market_cap)
     VALUES (?,?,?,?,?,?,?,?,?,?)'
);
foreach ($prices['rows'] as $code => $m) {
    if (!isset($assetIds[$code])) continue;
    $insMetric->execute([
        $runId, $assetIds[$code], $m['captured_at'], $m['price_usd'], $m['change_24h_pct'],
        $m['change_7d_pct'], $m['high_24h'], $m['low_24h'], $m['volume_24h'], $m['market_cap'],
    ]);
}

$analyzer = new SentimentAnalyzer();
$assetKeywords = array_map(fn($a) => $a['keywords'], $config['assets']);
$insNews = $pdo->prepare(
    'INSERT INTO news_items (run_id, source_id, guid, title, summary, url, published_at,
                             sentiment_score, matched_assets, matched_words)
     VALUES (?,?,?,?,?,?,?,?,?,?)
     ON CONFLICT(guid) DO NOTHING'
);
$newsByAsset = []; // code => list of {score, weight, title}
foreach ($news['items'] as $item) {
    $a = $analyzer->analyze($item['title'], $item['summary'], $assetKeywords);
    $insNews->execute([
        $runId, $sourceIds['cointelegraph'], $item['guid'], $item['title'], $item['summary'],
        $item['url'], $item['published_at'], $a['score'],
        implode(',', $a['assets']), implode(' ', $a['words']),
    ]);
    foreach ($a['assets'] as $assetCode) {
        if ($assetCode === 'market') {
            // общерыночная новость влияет на все активы с весом 0.4
            foreach (array_keys($config['assets']) as $c) {
                $newsByAsset[$c][] = ['score' => $a['score'], 'weight' => 0.4, 'title' => $item['title']];
            }
        } else {
            $newsByAsset[$assetCode][] = ['score' => $a['score'], 'weight' => 1.0, 'title' => $item['title']];
        }
    }
}

// ---------- 2b. Демо-сид для блока самопроверки (только fixtures) ----------
if ($useFixtures && $seedEvalDemo) {
    // Восстанавливаем цену BTC сутки назад из реального change_24h (арифметика,
    // а не выдумка: base = current / (1 + change/100)) и создаём прогноз,
    // датированный (горизонт+1ч) назад, чтобы он созрел прямо сейчас.
    $btc = $prices['rows']['bitcoin'];
    $basePrice = $btc['price_usd'] / (1 + ($btc['change_24h_pct'] ?? 0) / 100);
    $createdAt = gmdate('c', time() - ($config['horizon_hours'] + 1) * 3600);
    $demoFactors = [
        'momentum_24h' => ['value' => -0.4, 'weight' => 0.35, 'contribution' => -0.14,
            'note' => 'демо: суточное движение на тот момент было отрицательным (BTC падал к $62.5K, см. новость от 14.08)'],
        'momentum_7d' => ['value' => -0.3, 'weight' => 0.20, 'contribution' => -0.06,
            'note' => 'демо: неделя закрылась ниже 200-недельной средней'],
        'news_sentiment' => ['value' => -0.5, 'weight' => 0.30, 'contribution' => -0.15,
            'note' => 'демо: негативный новостной фон (взлом кошелька на $116M, предупреждения трейдеров)'],
        'range_position' => ['value' => -0.2, 'weight' => 0.15, 'contribution' => -0.03,
            'note' => 'демо: цена была в нижней части диапазона'],
    ];
    $pdo->prepare(
        'INSERT INTO predictions (run_id, asset_id, created_at, horizon_hours, direction, score,
                                  confidence_pct, base_price, range_low, range_high,
                                  arguments_json, risk_text, status)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $runId, $assetIds['bitcoin'], $createdAt, $config['horizon_hours'], 'down', -0.38, 62,
        round($basePrice, 2), round($basePrice * 0.985, 2), round($basePrice * 1.002, 2),
        json_encode($demoFactors, JSON_UNESCAPED_UNICODE),
        'Демо-прогноз, созданный сутки назад для демонстрации самопроверки.', 'open',
    ]);
    echo "  seeded 1 matured demo prediction (BTC, down) for evaluation demo\n";
}

// ---------- 3. EVALUATE: самопроверка созревших прогнозов ----------
$evaluator = new Evaluator($pdo, $config);
$evaluated = $evaluator->evaluateDue($prices['rows'], $assetIds, $now);
echo "  evaluated: $evaluated matured prediction(s)\n";

// ---------- 4. PREDICT ----------
$predictor = new Predictor($config);
$insPred = $pdo->prepare(
    'INSERT INTO predictions (run_id, asset_id, created_at, horizon_hours, direction, score,
                              confidence_pct, base_price, range_low, range_high,
                              arguments_json, risk_text)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
);
foreach ($config['assets'] as $code => $_) {
    if (!isset($prices['rows'][$code])) {
        echo "  ! no metrics for $code, skipping prediction\n";
        continue;
    }
    $p = $predictor->predict($code, $prices['rows'][$code], $newsByAsset[$code] ?? []);
    $insPred->execute([
        $runId, $assetIds[$code], $now, $config['horizon_hours'], $p['direction'], $p['score'],
        $p['confidence_pct'], $p['base_price'], $p['range_low'], $p['range_high'],
        json_encode($p['factors'], JSON_UNESCAPED_UNICODE), $p['risk_text'],
    ]);
    printf("  %-8s %-4s score=%+.3f conf=%d%%\n", $code, $p['direction'], $p['score'], $p['confidence_pct']);
}

$pdo->prepare('UPDATE update_runs SET finished_at = ?, notes = ? WHERE id = ?')
    ->execute([gmdate('c'), "prices+news ok, evaluated=$evaluated", $runId]);
echo "[run #$runId] done\n";
