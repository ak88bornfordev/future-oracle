-- Future Oracle: схема базы данных (SQLite)

-- Источники данных
CREATE TABLE IF NOT EXISTS sources (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    code       TEXT NOT NULL UNIQUE,          -- coingecko | cointelegraph
    name       TEXT NOT NULL,
    url        TEXT NOT NULL,
    type       TEXT NOT NULL                  -- prices | news
);

-- Объекты прогноза (криптоактивы)
CREATE TABLE IF NOT EXISTS assets (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    code       TEXT NOT NULL UNIQUE,          -- bitcoin, ethereum...
    symbol     TEXT NOT NULL,                 -- BTC, ETH...
    name       TEXT NOT NULL
);

-- История запусков обновления (история обновлений)
CREATE TABLE IF NOT EXISTS update_runs (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    started_at  TEXT NOT NULL,
    finished_at TEXT,
    mode        TEXT NOT NULL,                -- live | fixtures
    notes       TEXT
);

-- Сырые записи: ответы источников как есть
CREATE TABLE IF NOT EXISTS raw_records (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    run_id     INTEGER NOT NULL REFERENCES update_runs(id),
    source_id  INTEGER NOT NULL REFERENCES sources(id),
    fetched_at TEXT NOT NULL,
    payload    TEXT NOT NULL
);

-- Нормализованные рыночные показатели
CREATE TABLE IF NOT EXISTS metrics (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    run_id        INTEGER NOT NULL REFERENCES update_runs(id),
    asset_id      INTEGER NOT NULL REFERENCES assets(id),
    captured_at   TEXT NOT NULL,
    price_usd     REAL NOT NULL,
    change_24h_pct REAL,
    change_7d_pct  REAL,
    high_24h      REAL,
    low_24h       REAL,
    volume_24h    REAL,
    market_cap    REAL
);

-- Нормализованные новости
CREATE TABLE IF NOT EXISTS news_items (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    run_id          INTEGER NOT NULL REFERENCES update_runs(id),
    source_id       INTEGER NOT NULL REFERENCES sources(id),
    guid            TEXT NOT NULL UNIQUE,
    title           TEXT NOT NULL,
    summary         TEXT,
    url             TEXT,
    published_at    TEXT,
    sentiment_score REAL NOT NULL DEFAULT 0,  -- [-1..1]
    matched_assets  TEXT NOT NULL DEFAULT '', -- csv кодов активов или 'market'
    matched_words   TEXT NOT NULL DEFAULT ''  -- какие слова лексикона сработали
);

-- Прогнозы
CREATE TABLE IF NOT EXISTS predictions (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    run_id         INTEGER NOT NULL REFERENCES update_runs(id),
    asset_id       INTEGER NOT NULL REFERENCES assets(id),
    created_at     TEXT NOT NULL,
    horizon_hours  INTEGER NOT NULL DEFAULT 24,
    direction      TEXT NOT NULL,             -- up | down | flat
    score          REAL NOT NULL,             -- итоговый скор [-1..1]
    confidence_pct INTEGER NOT NULL,          -- 35..90, никогда не 100
    base_price     REAL NOT NULL,
    range_low      REAL NOT NULL,
    range_high     REAL NOT NULL,
    arguments_json TEXT NOT NULL,             -- вклад каждого фактора
    risk_text      TEXT NOT NULL,
    status         TEXT NOT NULL DEFAULT 'open', -- open | hit | miss
    evaluated_at   TEXT,
    actual_price   REAL,
    error_note     TEXT                       -- объяснение ошибки, если miss
);
