<?php
declare(strict_types=1);

namespace Oracle;

/**
 * Прогноз = взвешенная сумма четырёх объяснимых факторов, каждый в [-1..1]:
 *
 *   1. momentum_24h   - суточное изменение цены (нормировано: ±5% => ±1)
 *   2. momentum_7d    - недельное изменение (нормировано: ±10% => ±1)
 *   3. news_sentiment - средняя тональность новостей по активу
 *                       (общерыночные новости входят с весом 0.4)
 *   4. range_position - где цена внутри суточного диапазона low..high
 *                       (у верхней границы => +1, у нижней => -1)
 *
 * score = Σ weight_i * factor_i  ∈ [-1..1]
 * direction: up / down / flat по порогу flat_threshold.
 *
 * Уверенность (35..90%, никогда 100):
 *   base 50 + |score|*40, штрафы: -10 если моментум и новости противоречат,
 *   -5 если новостей по активу мало (<2), -5 если 24h и 7d разнонаправлены.
 *
 * Риск - текст из конкретных наблюдений (волатильность, противоречие
 * сигналов, мало данных), а не шаблонная фраза.
 */
final class Predictor
{
    public function __construct(private array $config) {}

    /**
     * @param array $metric нормализованные показатели актива
     * @param list<array{score: float, weight: float, title: string}> $newsScores
     */
    public function predict(string $assetCode, array $metric, array $newsScores): array
    {
        $w = $this->config['weights'];

        // --- факторы ---
        $m24 = $this->clamp(($metric['change_24h_pct'] ?? 0) / 5.0);
        $m7  = $this->clamp(($metric['change_7d_pct'] ?? 0) / 10.0);

        $newsFactor = 0.0;
        $weightSum = 0.0;
        foreach ($newsScores as $ns) {
            $newsFactor += $ns['score'] * $ns['weight'];
            $weightSum  += $ns['weight'];
        }
        $newsFactor = $weightSum > 0 ? $this->clamp($newsFactor / $weightSum) : 0.0;

        $rangePos = 0.0;
        if (!empty($metric['high_24h']) && !empty($metric['low_24h'])
            && $metric['high_24h'] > $metric['low_24h']) {
            $p = ($metric['price_usd'] - $metric['low_24h'])
               / ($metric['high_24h'] - $metric['low_24h']);
            $rangePos = $this->clamp(($p - 0.5) * 2);
        }

        $factors = [
            'momentum_24h'   => ['value' => round($m24, 3),      'weight' => $w['momentum_24h'],
                'note' => sprintf('изменение за 24ч: %+.2f%%', $metric['change_24h_pct'] ?? 0)],
            'momentum_7d'    => ['value' => round($m7, 3),       'weight' => $w['momentum_7d'],
                'note' => sprintf('изменение за 7д: %+.2f%%', $metric['change_7d_pct'] ?? 0)],
            'news_sentiment' => ['value' => round($newsFactor, 3), 'weight' => $w['news_sentiment'],
                'note' => sprintf('новостей учтено: %d, средняя тональность %+.2f', count($newsScores), $newsFactor)],
            'range_position' => ['value' => round($rangePos, 3), 'weight' => $w['range_position'],
                'note' => sprintf('цена %s в дневном диапазоне %s..%s',
                    $rangePos > 0.3 ? 'у верхней границы' : ($rangePos < -0.3 ? 'у нижней границы' : 'в середине'),
                    $this->fmt($metric['low_24h'] ?? 0), $this->fmt($metric['high_24h'] ?? 0))],
        ];

        $score = 0.0;
        foreach ($factors as $key => $f) {
            $factors[$key]['contribution'] = round($f['value'] * $f['weight'], 3);
            $score += $f['value'] * $f['weight'];
        }
        $score = round($this->clamp($score), 3);

        $flat = $this->config['flat_threshold'];
        $direction = $score > $flat ? 'up' : ($score < -$flat ? 'down' : 'flat');

        // --- уверенность ---
        $confidence = 50 + abs($score) * 40;
        $penalties = [];
        if ($m24 * $newsFactor < 0 && abs($newsFactor) > 0.1 && abs($m24) > 0.1) {
            $confidence -= 10;
            $penalties[] = 'ценовой импульс и новостной фон противоречат друг другу';
        }
        if (count($newsScores) < 2) {
            $confidence -= 5;
            $penalties[] = 'мало новостей по активу - новостной фактор ненадёжен';
        }
        if ($m24 * $m7 < 0 && abs($m24) > 0.05 && abs($m7) > 0.05) {
            $confidence -= 5;
            $penalties[] = 'суточный и недельный тренды разнонаправлены';
        }
        $confidence = (int)max(35, min(90, round($confidence)));

        // --- ожидаемый диапазон: половина суточной волатильности вокруг цены со сдвигом по score ---
        $price = (float)$metric['price_usd'];
        $volPct = (!empty($metric['high_24h']) && !empty($metric['low_24h']) && $price > 0)
            ? ($metric['high_24h'] - $metric['low_24h']) / $price * 100 : 2.0;
        $half = $volPct / 2;
        $shift = $score * $half * 0.6; // сдвиг центра диапазона в сторону прогноза
        $rangeLow  = $price * (1 + ($shift - $half) / 100);
        $rangeHigh = $price * (1 + ($shift + $half) / 100);

        // --- риск ---
        $risks = $penalties;
        if ($volPct > 3.5) {
            $risks[] = sprintf('высокая суточная волатильность (%.1f%%) - движение может пробить диапазон', $volPct);
        }
        $risks[] = 'внезапная новость (регуляторика, взлом, макро) сломает любой краткосрочный прогноз';
        $riskText = ucfirst(implode('; ', $risks)) . '.';

        return [
            'direction'      => $direction,
            'score'          => $score,
            'confidence_pct' => $confidence,
            'base_price'     => $price,
            'range_low'      => round($rangeLow, $price < 10 ? 4 : 2),
            'range_high'     => round($rangeHigh, $price < 10 ? 4 : 2),
            'factors'        => $factors,
            'risk_text'      => $riskText,
        ];
    }

    private function clamp(float $v): float
    {
        return max(-1.0, min(1.0, $v));
    }

    private function fmt(float $v): string
    {
        return $v >= 10 ? number_format($v, 0, '.', ' ') : (string)round($v, 4);
    }
}
