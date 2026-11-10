<?php
/* Bar pengumuman di atas navbar (mis. "Free Shipping with Threshold  More →").

   Bisa berisi beberapa pengumuman yang bergantian dengan animasi yang dipilih di dashboard.
   Data ada di dua tabel yang DIBUAT / DIMIGRASI OTOMATIS saat pertama dibaca, jadi tidak perlu
   import SQL / phpMyAdmin:
     - announcements          : satu baris per pengumuman
     - announcement_settings  : satu baris (id = 1) berisi pengaturan animasi
   Semua fungsi aman dipanggil dari halaman publik: bila database bermasalah, bar tidak tampil. */

const PPF_ANNOUNCEMENT_SETTINGS_ID = 1;
const PPF_ANNOUNCEMENT_MAX_MESSAGE = 160;
const PPF_ANNOUNCEMENT_MAX_LABEL   = 40;
const PPF_ANNOUNCEMENT_MAX_URL     = 500;
const PPF_ANNOUNCEMENT_MAX_ITEMS   = 12;
const PPF_ANNOUNCEMENT_MAX_SORT    = 9999;

const PPF_ANNOUNCEMENT_MIN_INTERVAL = 2;
const PPF_ANNOUNCEMENT_MAX_INTERVAL = 15;
const PPF_ANNOUNCEMENT_MIN_SPEED    = 1;
const PPF_ANNOUNCEMENT_MAX_SPEED    = 10;

/** Pilihan animasi: kode => label di dashboard. */
const PPF_ANNOUNCEMENT_ANIMATIONS = [
    'slide'   => 'Slide ke kanan + fading',
    'fade'    => 'Fade (pudar bergantian)',
    'blink'   => 'Kedip',
    'marquee' => 'Berjalan ke kanan (marquee)',
    'none'    => 'Diam (tanpa animasi)',
];

const PPF_ANNOUNCEMENT_DEFAULT_ITEM = [
    'message'    => 'Free Shipping with Threshold',
    'link_label' => 'More',
    'link_url'   => 'shipping.php',
    'sort_order' => 0,
    'is_active'  => 1,
];

const PPF_ANNOUNCEMENT_DEFAULT_SETTINGS = [
    'animation'        => 'slide',
    'interval_seconds' => 4,
    'marquee_speed'    => 5,
];

/* ── schema (dibuat / dimigrasi otomatis) ────────────── */

function ppf_announcement_create_tables(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS announcements (
            id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            message    VARCHAR(' . PPF_ANNOUNCEMENT_MAX_MESSAGE . ') NOT NULL DEFAULT \'\',
            link_label VARCHAR(' . PPF_ANNOUNCEMENT_MAX_LABEL . ') DEFAULT NULL,
            link_url   VARCHAR(' . PPF_ANNOUNCEMENT_MAX_URL . ') DEFAULT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active  TINYINT(1) NOT NULL DEFAULT 1,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_active_sort (is_active, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS announcement_settings (
            id               TINYINT UNSIGNED NOT NULL,
            animation        VARCHAR(20) NOT NULL DEFAULT \'slide\',
            interval_seconds TINYINT UNSIGNED NOT NULL DEFAULT 4,
            marquee_speed    TINYINT UNSIGNED NOT NULL DEFAULT 5,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

/** Tabel versi lama (satu baris, tanpa sort_order / auto-increment) dinaikkan ke skema baru; datanya tetap. */
function ppf_announcement_migrate_legacy(PDO $pdo): void
{
    $hasSortOrder = $pdo->query("SHOW COLUMNS FROM announcements LIKE 'sort_order'")->fetchColumn();
    if ($hasSortOrder) {
        return;
    }
    $pdo->exec(
        'ALTER TABLE announcements
            MODIFY id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER link_url,
            ADD KEY idx_active_sort (is_active, sort_order)'
    );
}

function ppf_announcement_seed_defaults(PDO $pdo): void
{
    $isEmpty = (int) $pdo->query('SELECT COUNT(*) FROM announcements')->fetchColumn() === 0;
    if ($isEmpty) {
        ppf_announcement_insert($pdo, PPF_ANNOUNCEMENT_DEFAULT_ITEM);
    }

    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO announcement_settings (id, animation, interval_seconds, marquee_speed)
         VALUES (:id, :animation, :interval_seconds, :marquee_speed)'
    );
    $stmt->execute(['id' => PPF_ANNOUNCEMENT_SETTINGS_ID] + PPF_ANNOUNCEMENT_DEFAULT_SETTINGS);
}

function ppf_announcement_ensure_schema(PDO $pdo): void
{
    ppf_announcement_create_tables($pdo);
    ppf_announcement_migrate_legacy($pdo);
    ppf_announcement_seed_defaults($pdo);
}

/* ── read ────────────────────────────────────────────── */

function ppf_announcement_select_items(PDO $pdo): array
{
    return $pdo->query(
        'SELECT id, message, link_label, link_url, sort_order, is_active
           FROM announcements ORDER BY sort_order ASC, id ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
}

function ppf_announcement_select_settings(PDO $pdo): array
{
    $stmt = $pdo->prepare(
        'SELECT animation, interval_seconds, marquee_speed FROM announcement_settings WHERE id = :id'
    );
    $stmt->execute(['id' => PPF_ANNOUNCEMENT_SETTINGS_ID]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return PPF_ANNOUNCEMENT_DEFAULT_SETTINGS;
    }
    if (!isset(PPF_ANNOUNCEMENT_ANIMATIONS[$row['animation']])) {
        $row['animation'] = PPF_ANNOUNCEMENT_DEFAULT_SETTINGS['animation'];
    }
    return [
        'animation'        => $row['animation'],
        'interval_seconds' => (int) $row['interval_seconds'],
        'marquee_speed'    => (int) $row['marquee_speed'],
    ];
}

/**
 * Semua pengumuman + pengaturan animasi. Tabel dibuat/dimigrasi otomatis bila perlu.
 * Mengembalikan null bila database tidak bisa dipakai (mis. user tanpa hak CREATE/ALTER).
 *
 * @return array{items: array<int, array<string, mixed>>, settings: array<string, int|string>}|null
 */
function ppf_announcement_load(PDO $pdo): ?array
{
    $read = static fn(): array => [
        'items'    => ppf_announcement_select_items($pdo),
        'settings' => ppf_announcement_select_settings($pdo),
    ];

    try {
        return $read();
    } catch (PDOException $e) {
        try {
            ppf_announcement_ensure_schema($pdo);
            return $read();
        } catch (Throwable $e) {
            return null;
        }
    }
}

/**
 * Data untuk bar di storefront: hanya pengumuman aktif. Null bila tidak ada yang tampil.
 *
 * @return array{items: array<int, array<string, mixed>>, settings: array<string, int|string>, version: string}|null
 */
function ppf_announcement_active(PDO $pdo): ?array
{
    $data = ppf_announcement_load($pdo);
    if ($data === null) {
        return null;
    }

    $items = array_values(array_filter(
        $data['items'],
        static fn(array $item): bool => (int) $item['is_active'] === 1 && trim((string) $item['message']) !== ''
    ));
    if ($items === []) {
        return null;
    }

    // Versi berubah saat isi / animasi diedit, sehingga bar yang sudah ditutup pengunjung muncul lagi.
    $version = substr(md5(json_encode([$items, $data['settings']])), 0, 12);

    return ['items' => $items, 'settings' => $data['settings'], 'version' => $version];
}

/* ── validasi ────────────────────────────────────────── */

/** Hanya izinkan path relatif (shop.php, #id) atau http(s). Selain itu (mis. javascript:) ditolak. */
function ppf_announcement_clean_url(string $url): ?string
{
    $url = trim($url);
    if ($url === '') {
        return null;
    }
    $hasScheme = preg_match('#^(?:[a-z][a-z0-9+.-]*:|//)#i', $url) === 1;
    if ($hasScheme && preg_match('#^https?://#i', $url) !== 1) {
        throw new InvalidArgumentException('Link URL harus berupa halaman situs (mis. shipping.php) atau diawali http:// / https://');
    }
    return mb_substr($url, 0, PPF_ANNOUNCEMENT_MAX_URL);
}

/**
 * Bersihkan + validasi satu pengumuman dari form. Melempar InvalidArgumentException bila tidak valid.
 *
 * @return array{message:string,link_label:?string,link_url:?string,sort_order:int,is_active:int}
 */
function ppf_announcement_clean_item(array $input): array
{
    $message = trim((string) ($input['message'] ?? ''));
    $label   = trim((string) ($input['link_label'] ?? ''));
    $url     = ppf_announcement_clean_url((string) ($input['link_url'] ?? ''));
    $sort    = (int) ($input['sort_order'] ?? 0);

    if ($message === '') {
        throw new InvalidArgumentException('Teks pengumuman tidak boleh kosong.');
    }
    if (mb_strlen($message) > PPF_ANNOUNCEMENT_MAX_MESSAGE) {
        throw new InvalidArgumentException('Teks pengumuman maksimal ' . PPF_ANNOUNCEMENT_MAX_MESSAGE . ' karakter.');
    }
    if (mb_strlen($label) > PPF_ANNOUNCEMENT_MAX_LABEL) {
        throw new InvalidArgumentException('Label link maksimal ' . PPF_ANNOUNCEMENT_MAX_LABEL . ' karakter.');
    }

    return [
        'message'    => $message,
        'link_label' => $url !== null ? ($label !== '' ? $label : 'More') : null,
        'link_url'   => $url,
        'sort_order' => max(0, min(PPF_ANNOUNCEMENT_MAX_SORT, $sort)),
        'is_active'  => empty($input['is_active']) ? 0 : 1,
    ];
}

/** @return array{animation:string,interval_seconds:int,marquee_speed:int} */
function ppf_announcement_clean_settings(array $input): array
{
    $animation = (string) ($input['animation'] ?? '');
    if (!isset(PPF_ANNOUNCEMENT_ANIMATIONS[$animation])) {
        throw new InvalidArgumentException('Pilihan animasi tidak dikenal.');
    }

    return [
        'animation'        => $animation,
        'interval_seconds' => max(PPF_ANNOUNCEMENT_MIN_INTERVAL, min(PPF_ANNOUNCEMENT_MAX_INTERVAL, (int) ($input['interval_seconds'] ?? 0))),
        'marquee_speed'    => max(PPF_ANNOUNCEMENT_MIN_SPEED, min(PPF_ANNOUNCEMENT_MAX_SPEED, (int) ($input['marquee_speed'] ?? 0))),
    ];
}

/* ── write ───────────────────────────────────────────── */

/** @param array{message:string,link_label:?string,link_url:?string,sort_order:int,is_active:int} $item sudah bersih */
function ppf_announcement_insert(PDO $pdo, array $item): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO announcements (message, link_label, link_url, sort_order, is_active)
         VALUES (:message, :link_label, :link_url, :sort_order, :is_active)'
    );
    $stmt->execute($item);
    return (int) $pdo->lastInsertId();
}

function ppf_announcement_create(PDO $pdo, array $input): int
{
    $item = ppf_announcement_clean_item($input);

    $data = ppf_announcement_load($pdo); // memastikan tabel ada
    if ($data !== null && count($data['items']) >= PPF_ANNOUNCEMENT_MAX_ITEMS) {
        throw new InvalidArgumentException('Maksimal ' . PPF_ANNOUNCEMENT_MAX_ITEMS . ' pengumuman. Hapus yang tidak dipakai dulu.');
    }
    return ppf_announcement_insert($pdo, $item);
}

function ppf_announcement_update(PDO $pdo, int $id, array $input): void
{
    $item = ppf_announcement_clean_item($input);

    $stmt = $pdo->prepare(
        'UPDATE announcements
            SET message = :message, link_label = :link_label, link_url = :link_url,
                sort_order = :sort_order, is_active = :is_active
          WHERE id = :id'
    );
    $stmt->execute($item + ['id' => $id]);
}

function ppf_announcement_delete(PDO $pdo, int $id): void
{
    $pdo->prepare('DELETE FROM announcements WHERE id = :id')->execute(['id' => $id]);
}

function ppf_announcement_save_settings(PDO $pdo, array $input): void
{
    $settings = ppf_announcement_clean_settings($input);
    ppf_announcement_load($pdo); // memastikan tabel ada

    $stmt = $pdo->prepare(
        'INSERT INTO announcement_settings (id, animation, interval_seconds, marquee_speed)
         VALUES (:id, :animation, :interval_seconds, :marquee_speed)
         ON DUPLICATE KEY UPDATE animation = VALUES(animation),
                                 interval_seconds = VALUES(interval_seconds),
                                 marquee_speed = VALUES(marquee_speed)'
    );
    $stmt->execute(['id' => PPF_ANNOUNCEMENT_SETTINGS_ID] + $settings);
}
