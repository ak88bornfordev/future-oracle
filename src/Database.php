<?php
declare(strict_types=1);

namespace Oracle;

use PDO;

/**
 * Обёртка над SQLite: подключение, миграция схемы, справочники.
 */
final class Database
{
    private PDO $pdo;

    public function __construct(string $path, string $schemaFile)
    {
        $isNew = !file_exists($path);
        @mkdir(dirname($path), 0777, true);
        $this->pdo = new PDO('sqlite:' . $path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec(file_get_contents($schemaFile));
        unset($isNew);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** Регистрирует источники и активы из конфига, возвращает карты code=>id. */
    public function syncDictionaries(array $config): array
    {
        $sourceIds = [];
        foreach ($config['sources'] as $code => $src) {
            $this->pdo->prepare(
                'INSERT INTO sources (code, name, url, type) VALUES (?,?,?,?)
                 ON CONFLICT(code) DO UPDATE SET name=excluded.name, url=excluded.url'
            )->execute([$code, $src['name'], $src['url'], $src['type']]);
            $sourceIds[$code] = (int)$this->pdo
                ->query("SELECT id FROM sources WHERE code = " . $this->pdo->quote($code))
                ->fetchColumn();
        }

        $assetIds = [];
        foreach ($config['assets'] as $code => $asset) {
            $this->pdo->prepare(
                'INSERT INTO assets (code, symbol, name) VALUES (?,?,?)
                 ON CONFLICT(code) DO NOTHING'
            )->execute([$code, $asset['symbol'], $asset['name']]);
            $assetIds[$code] = (int)$this->pdo
                ->query("SELECT id FROM assets WHERE code = " . $this->pdo->quote($code))
                ->fetchColumn();
        }

        return [$sourceIds, $assetIds];
    }
}
