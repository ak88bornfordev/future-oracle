<?php
declare(strict_types=1);

namespace Oracle;

use PDO;

/**
 * Самопроверка системы: как оракул понимает, что ошибся.
 *
 * При каждом обновлении берём открытые прогнозы, чей горизонт истёк,
 * сравниваем фактическую цену с базовой:
 *   up   -> hit, если цена выросла больше чем на hit_tolerance_pct
 *   down -> hit, если упала сильнее чем на -hit_tolerance_pct
 *   flat -> hit, если осталась в пределах ±(hit_tolerance_pct * 3)
 * Иначе miss + текстовое объяснение, какой фактор подвёл
 * (сравниваем знак вклада фактора со знаком фактического движения).
 */
final class Evaluator
{
    public function __construct(private PDO $pdo, private array $config) {}

    /** @param array<string, array> $currentMetrics code => metric */
    public function evaluateDue(array $currentMetrics, array $assetIds, string $nowIso): int
    {
        $tol = (float)$this->config['hit_tolerance_pct'];
        $codeById = array_flip($assetIds);

        $stmt = $this->pdo->query(
            "SELECT * FROM predictions WHERE status = 'open'"
        );
        $evaluated = 0;

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $due = strtotime($p['created_at']) + (int)$p['horizon_hours'] * 3600;
            if (strtotime($nowIso) < $due) {
                continue; // горизонт ещё не истёк
            }
            $code = $codeById[(int)$p['asset_id']] ?? null;
            if ($code === null || !isset($currentMetrics[$code])) {
                continue; // нет свежей цены - оценим в следующий раз
            }

            $actual = (float)$currentMetrics[$code]['price_usd'];
            $movePct = ($actual - (float)$p['base_price']) / (float)$p['base_price'] * 100;

            $hit = match ($p['direction']) {
                'up'   => $movePct > $tol,
                'down' => $movePct < -$tol,
                'flat' => abs($movePct) <= $tol * 3,
                default => false,
            };

            $note = null;
            if (!$hit) {
                $note = $this->explainMiss($p, $movePct);
            }

            $this->pdo->prepare(
                'UPDATE predictions
                 SET status = ?, evaluated_at = ?, actual_price = ?, error_note = ?
                 WHERE id = ?'
            )->execute([$hit ? 'hit' : 'miss', $nowIso, $actual, $note, $p['id']]);
            $evaluated++;
        }

        return $evaluated;
    }

    /** Разбор ошибки: какие факторы указывали не туда. */
    private function explainMiss(array $p, float $movePct): string
    {
        $factors = json_decode($p['arguments_json'], true) ?: [];
        $actualSign = $movePct > 0 ? 1 : ($movePct < 0 ? -1 : 0);

        $wrong = [];
        $names = [
            'momentum_24h' => 'суточный импульс',
            'momentum_7d' => 'недельный тренд',
            'news_sentiment' => 'новостной фон',
            'range_position' => 'положение в диапазоне',
        ];
        foreach ($factors as $key => $f) {
            $c = $f['contribution'] ?? 0;
            if ($actualSign !== 0 && $c != 0 && ($c <=> 0) !== $actualSign && abs($c) > 0.02) {
                $wrong[] = $names[$key] ?? $key;
            }
        }

        $head = sprintf(
            'Прогноз "%s" не сбылся: фактическое движение %+.2f%%.',
            ['up' => 'рост', 'down' => 'падение', 'flat' => 'боковик'][$p['direction']] ?? $p['direction'],
            $movePct
        );
        return $wrong
            ? $head . ' Неверный сигнал дали: ' . implode(', ', $wrong) . '.'
            : $head . ' Все факторы были слабыми - сигнала фактически не было.';
    }
}
