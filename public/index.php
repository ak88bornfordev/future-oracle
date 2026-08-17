<?php
declare(strict_types=1);

/**
 * Future Oracle - интерфейс.
 * Запуск: php -S localhost:8080 -t public
 */

$config = require __DIR__ . '/../config.php';
$pdo = new PDO('sqlite:' . $config['db_path']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$assets = $pdo->query('SELECT * FROM assets ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$lastRun = $pdo->query('SELECT * FROM update_runs ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$runsCount = (int)$pdo->query('SELECT COUNT(*) FROM update_runs')->fetchColumn();
$sources = $pdo->query('SELECT * FROM sources ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

// последние прогнозы по каждому активу
$latest = [];
foreach ($assets as $a) {
    $stmt = $pdo->prepare('SELECT * FROM predictions WHERE asset_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$a['id']]);
    if ($p = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $m = $pdo->prepare('SELECT * FROM metrics WHERE asset_id = ? ORDER BY id DESC LIMIT 1');
        $m->execute([$a['id']]);
        $latest[$a['code']] = ['asset' => $a, 'pred' => $p, 'metric' => $m->fetch(PDO::FETCH_ASSOC)];
    }
}

// точность: оценённые прогнозы
$evalStats = $pdo->query(
    "SELECT COUNT(*) total, SUM(status = 'hit') hits FROM predictions WHERE status IN ('hit','miss')"
)->fetch(PDO::FETCH_ASSOC);
$evaluated = $pdo->query(
    "SELECT p.*, a.symbol FROM predictions p JOIN assets a ON a.id = p.asset_id
     WHERE p.status IN ('hit','miss') ORDER BY p.evaluated_at DESC LIMIT 20"
)->fetchAll(PDO::FETCH_ASSOC);

// новости, повлиявшие на прогнозы (последний запуск)
$newsRows = $pdo->query(
    "SELECT * FROM news_items WHERE matched_assets != '' ORDER BY published_at DESC LIMIT 15"
)->fetchAll(PDO::FETCH_ASSOC);

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES); }
function price(float $v): string { return $v >= 10 ? '$' . number_format($v, 0, '.', ' ') : '$' . rtrim(rtrim(number_format($v, 4, '.', ' '), '0'), '.'); }
function dirLabel(string $d): array {
    return ['up' => ['РОСТ', 'up'], 'down' => ['ПАДЕНИЕ', 'down'], 'flat' => ['БОКОВИК', 'flat']][$d] ?? [$d, 'flat'];
}
$factorNames = [
    'momentum_24h' => 'Импульс 24ч', 'momentum_7d' => 'Тренд 7д',
    'news_sentiment' => 'Новостной фон', 'range_position' => 'Позиция в диапазоне',
];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Future Oracle - крипто-прогнозы</title>
<style>
:root { --bg:#0e1117; --card:#161b26; --card2:#1c2333; --text:#e6e9f0; --muted:#8b93a7;
        --up:#2ecc71; --down:#e74c3c; --flat:#f1c40f; --accent:#6c8cff; --line:#242c3d; }
* { box-sizing:border-box; margin:0; padding:0; }
body { background:var(--bg); color:var(--text); font:15px/1.55 'Segoe UI',system-ui,sans-serif; padding:24px; max-width:1200px; margin:0 auto; }
h1 { font-size:26px; margin-bottom:4px; }
h2 { font-size:18px; margin:32px 0 12px; color:var(--accent); }
.sub { color:var(--muted); margin-bottom:16px; }
.disclaimer { background:#2a1f10; border:1px solid #5a4420; color:#e8c987; padding:10px 14px; border-radius:8px; font-size:13px; margin-bottom:20px; }
.statbar { display:flex; gap:14px; flex-wrap:wrap; margin-bottom:8px; }
.stat { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:10px 18px; }
.stat b { font-size:20px; display:block; }
.stat span { color:var(--muted); font-size:12px; }
.grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(360px,1fr)); gap:16px; }
.card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:16px; }
.card h3 { font-size:17px; display:flex; justify-content:space-between; align-items:center; }
.badge { font-size:12px; font-weight:700; padding:3px 10px; border-radius:20px; }
.badge.up { background:rgba(46,204,113,.15); color:var(--up); }
.badge.down { background:rgba(231,76,60,.15); color:var(--down); }
.badge.flat { background:rgba(241,196,15,.15); color:var(--flat); }
.price { font-size:22px; font-weight:600; margin:6px 0 2px; }
.chg { font-size:13px; }
.chg.pos { color:var(--up); } .chg.neg { color:var(--down); }
.confwrap { margin:10px 0 4px; }
.confbar { height:8px; background:var(--card2); border-radius:4px; overflow:hidden; }
.confbar i { display:block; height:100%; background:linear-gradient(90deg,#4a6cf7,#6c8cff); }
.small { font-size:12px; color:var(--muted); }
table.factors { width:100%; border-collapse:collapse; margin:8px 0; font-size:13px; }
table.factors td { padding:4px 6px; border-top:1px solid var(--line); vertical-align:top; }
table.factors td.num { text-align:right; white-space:nowrap; font-variant-numeric:tabular-nums; }
.pos { color:var(--up); } .neg { color:var(--down); }
.risk { background:var(--card2); border-radius:8px; padding:8px 10px; font-size:13px; color:#d4a05f; margin-top:8px; }
details { margin-top:8px; } summary { cursor:pointer; color:var(--accent); font-size:13px; }
.newslist { list-style:none; }
.newslist li { padding:8px 0; border-top:1px solid var(--line); font-size:14px; }
.newslist .meta { font-size:12px; color:var(--muted); }
.score-pill { font-size:11px; padding:1px 8px; border-radius:10px; margin-left:6px; }
table.wide { width:100%; border-collapse:collapse; background:var(--card); border-radius:12px; overflow:hidden; font-size:14px; }
table.wide th, table.wide td { padding:9px 12px; text-align:left; border-bottom:1px solid var(--line); }
table.wide th { background:var(--card2); color:var(--muted); font-size:12px; text-transform:uppercase; }
.status-hit { color:var(--up); font-weight:700; } .status-miss { color:var(--down); font-weight:700; }
.errnote { font-size:12px; color:var(--muted); }
footer { margin-top:36px; color:var(--muted); font-size:12px; }
</style>
</head>
<body>

<h1>🔮 Future Oracle</h1>
<p class="sub">Прогноз движения криптоактивов на <?= (int)$config['horizon_hours'] ?> часа по данным
<?= count($sources) ?> реальных источников. Обновлений: <?= $runsCount ?>,
последнее: <?= e($lastRun['started_at'] ?? '-') ?> (режим: <?= e($lastRun['mode'] ?? '-') ?>).</p>

<div class="disclaimer">⚠️ Учебный прототип. Не финансовый совет, не торговый сигнал, реальные деньги не подключены,
доходность не обещается. Прогнозы вероятностные и регулярно ошибаются - см. блок «Самопроверка».</div>

<div class="statbar">
    <div class="stat"><b><?= count($latest) ?></b><span>активов под прогнозом</span></div>
    <div class="stat"><b><?= (int)($evalStats['total'] ?? 0) ?></b><span>прогнозов проверено фактом</span></div>
    <div class="stat"><b><?= $evalStats['total'] ? round($evalStats['hits'] / $evalStats['total'] * 100) . '%' : '—' ?></b><span>точность (hit rate)</span></div>
    <div class="stat"><b><?= count($newsRows) ?></b><span>новостей учтено</span></div>
</div>

<h2>Прогнозы</h2>
<div class="grid">
<?php foreach ($latest as $row):
    $a = $row['asset']; $p = $row['pred']; $m = $row['metric'];
    [$dLabel, $dClass] = dirLabel($p['direction']);
    $factors = json_decode($p['arguments_json'], true) ?: [];
    $chg = (float)($m['change_24h_pct'] ?? 0);
?>
    <div class="card">
        <h3><?= e($a['name']) ?> <span class="small">(<?= e($a['symbol']) ?>)</span>
            <span class="badge <?= $dClass ?>"><?= $dLabel ?></span></h3>
        <div class="price"><?= price((float)$p['base_price']) ?>
            <span class="chg <?= $chg >= 0 ? 'pos' : 'neg' ?>"><?= sprintf('%+.2f%%', $chg) ?> за 24ч</span></div>
        <div class="small">Ожидаемый диапазон через <?= (int)$p['horizon_hours'] ?>ч:
            <?= price((float)$p['range_low']) ?> … <?= price((float)$p['range_high']) ?></div>

        <div class="confwrap">
            <div class="small">Уверенность: <b><?= (int)$p['confidence_pct'] ?>%</b> · скор <?= sprintf('%+.3f', $p['score']) ?></div>
            <div class="confbar"><i style="width:<?= (int)$p['confidence_pct'] ?>%"></i></div>
        </div>

        <details open>
            <summary>Аргументы (вклад факторов)</summary>
            <table class="factors">
            <?php foreach ($factors as $key => $f): $c = $f['contribution'] ?? 0; ?>
                <tr>
                    <td><?= e($factorNames[$key] ?? $key) ?><br><span class="small"><?= e($f['note'] ?? '') ?></span></td>
                    <td class="num <?= $c >= 0 ? 'pos' : 'neg' ?>"><?= sprintf('%+.3f', $c) ?><br>
                        <span class="small">вес <?= e((string)$f['weight']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </table>
        </details>

        <div class="risk">⚠ Риск: <?= e($p['risk_text']) ?></div>
    </div>
<?php endforeach; ?>
</div>

<h2>Самопроверка: как оракул понимает, что ошибся</h2>
<?php if ($evaluated): ?>
<table class="wide">
    <tr><th>Актив</th><th>Прогноз</th><th>База → Факт</th><th>Итог</th><th>Разбор ошибки</th></tr>
    <?php foreach ($evaluated as $ev): [$dl] = dirLabel($ev['direction']); ?>
    <tr>
        <td><?= e($ev['symbol']) ?></td>
        <td><?= $dl ?> (<?= (int)$ev['confidence_pct'] ?>%)<br><span class="small"><?= e($ev['created_at']) ?></span></td>
        <td><?= price((float)$ev['base_price']) ?> → <?= price((float)$ev['actual_price']) ?></td>
        <td class="status-<?= e($ev['status']) ?>"><?= $ev['status'] === 'hit' ? 'СБЫЛСЯ' : 'ОШИБКА' ?></td>
        <td class="errnote"><?= e($ev['error_note'] ?? 'Направление угадано в пределах допуска.') ?></td>
    </tr>
    <?php endforeach; ?>
</table>
<?php else: ?>
<p class="small">Пока нет прогнозов с истёкшим горизонтом. Запустите обновление через <?= (int)$config['horizon_hours'] ?>ч -
система сверит каждый открытый прогноз с фактической ценой, пометит hit/miss и объяснит, какой фактор подвёл.</p>
<?php endif; ?>

<h2>Новости, учтённые в прогнозах</h2>
<ul class="newslist">
<?php foreach ($newsRows as $n): $s = (float)$n['sentiment_score']; ?>
    <li>
        <a href="<?= e($n['url']) ?>" style="color:var(--text)" target="_blank" rel="noopener"><?= e($n['title']) ?></a>
        <span class="score-pill <?= $s > 0 ? 'badge up' : ($s < 0 ? 'badge down' : 'badge flat') ?>">
            тональность <?= sprintf('%+.2f', $s) ?></span>
        <div class="meta"><?= e($n['published_at']) ?> · затрагивает: <?= e($n['matched_assets']) ?>
            <?= $n['matched_words'] ? '· слова: ' . e($n['matched_words']) : '' ?></div>
    </li>
<?php endforeach; ?>
</ul>

<h2>Источники данных</h2>
<table class="wide">
    <tr><th>Источник</th><th>Тип</th><th>URL</th></tr>
    <?php foreach ($sources as $s): ?>
    <tr><td><?= e($s['name']) ?></td><td><?= e($s['type']) ?></td><td class="small"><?= e($s['url']) ?></td></tr>
    <?php endforeach; ?>
</table>

<footer>Future Oracle - тестовое задание. Алгоритм: взвешенный скоринг 4 объяснимых факторов
(веса в config.php). SQLite: источники, сырые записи, нормализованные показатели, прогнозы, история обновлений.</footer>
</body>
</html>
