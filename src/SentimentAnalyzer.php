<?php
declare(strict_types=1);

namespace Oracle;

/**
 * Простой словарный анализатор тональности новостей.
 *
 * Никакой магии: два списка слов (позитив/негатив) с весами.
 * Оценка = (sum(pos) - sum(neg)) / (sum(pos) + sum(neg)), диапазон [-1..1].
 * Плюс привязка новости к активам по ключевым словам.
 * Такой подход слабее ML-модели, но полностью объясним: видно,
 * какие именно слова дали оценку (сохраняем их в matched_words).
 */
final class SentimentAnalyzer
{
    /** @var array<string, float> слово => вес */
    private const POSITIVE = [
        'surge' => 1.0, 'surges' => 1.0, 'surged' => 1.0, 'rally' => 1.0, 'rallies' => 1.0,
        'gains' => 0.8, 'gain' => 0.6, 'rises' => 0.8, 'rise' => 0.6, 'rose' => 0.8,
        'record' => 0.6, 'high' => 0.4, 'highs' => 0.5, 'adoption' => 0.8, 'approval' => 0.8,
        'approved' => 0.8, 'inflow' => 0.9, 'inflows' => 0.9, 'buying' => 0.7, 'buys' => 0.6,
        'partnership' => 0.6, 'launch' => 0.4, 'launches' => 0.4, 'bullish' => 1.0,
        'rebound' => 0.8, 'rebounds' => 0.8, 'growth' => 0.6, 'grows' => 0.6, 'strength' => 0.7,
        'passes' => 0.5, 'hits' => 0.4, 'upgrade' => 0.5, 'staking' => 0.3, 'rewards' => 0.4,
        'doubles' => 0.7, 'double' => 0.5,
    ];

    /** @var array<string, float> */
    private const NEGATIVE = [
        'hack' => 1.0, 'hacked' => 1.0, 'exploit' => 1.0, 'leak' => 0.9, 'leaked' => 0.9,
        'fine' => 0.7, 'fined' => 0.8, 'penalty' => 0.7, 'ban' => 0.8, 'banned' => 0.8,
        'lawsuit' => 0.7, 'sues' => 0.7, 'sued' => 0.7, 'drop' => 0.7, 'drops' => 0.8,
        'dropped' => 0.7, 'falls' => 0.8, 'fall' => 0.6, 'fell' => 0.8, 'losses' => 0.8,
        'loss' => 0.6, 'bear' => 0.7, 'bearish' => 1.0, 'warns' => 0.6, 'warning' => 0.6,
        'crash' => 1.0, 'rollback' => 0.7, 'phishing' => 0.8, 'fraud' => 0.9, 'scam' => 0.9,
        'dump' => 0.8, 'outflow' => 0.8, 'outflows' => 0.8, 'delay' => 0.4, 'delayed' => 0.5,
        'impossible' => 0.6, 'downturn' => 0.8, 'downside' => 0.8, 'probe' => 0.5,
        'investigation' => 0.5, 'dead' => 0.7, 'stop' => 0.4, 'risk' => 0.3, 'wobbled' => 0.4,
    ];

    /** Общерыночные маркеры: новость без явного актива, но про крипторынок. */
    private const MARKET_WORDS = ['crypto', 'cryptocurrency', 'blockchain', 'defi', 'stablecoin', 'web3', 'token'];

    /**
     * @param array<string, string[]> $assetKeywords code => keywords
     * @return array{score: float, assets: string[], words: string[]}
     */
    public function analyze(string $title, string $summary, array $assetKeywords): array
    {
        $text = mb_strtolower($title . ' ' . $summary);

        $pos = 0.0; $neg = 0.0; $hits = [];
        // Ищем только целые слова (\b), иначе "deadline" матчился бы как "dead".
        foreach (self::POSITIVE as $word => $w) {
            $n = $this->countWord($text, $word);
            if ($n > 0) { $pos += $w * min($n, 2); $hits[] = "+$word"; }
        }
        foreach (self::NEGATIVE as $word => $w) {
            $n = $this->countWord($text, $word);
            if ($n > 0) { $neg += $w * min($n, 2); $hits[] = "-$word"; }
        }
        $total = $pos + $neg;
        $score = $total > 0 ? round(($pos - $neg) / $total, 3) : 0.0;

        $assets = [];
        foreach ($assetKeywords as $code => $keywords) {
            foreach ($keywords as $kw) {
                if ($this->countWord($text, trim(mb_strtolower($kw))) > 0) { $assets[] = $code; break; }
            }
        }
        if ($assets === []) {
            foreach (self::MARKET_WORDS as $kw) {
                if ($this->countWord($text, $kw) > 0) { $assets[] = 'market'; break; }
            }
        }

        return ['score' => $score, 'assets' => array_values(array_unique($assets)), 'words' => $hits];
    }

    /** Количество вхождений слова целиком (по границам слов). */
    private function countWord(string $text, string $word): int
    {
        return preg_match_all('/\b' . preg_quote($word, '/') . '\b/u', $text);
    }
}
