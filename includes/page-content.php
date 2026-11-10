<?php
/* Teks halaman statis yang diedit di dashboard (menu Page text):
   Shipping & Returns, About, Contact, Stockist, Legal.

   - Teks bawaan ada di includes/page-content-defaults.php (dibuat dari isi halaman asli).
   - Teks yang diubah admin disimpan di tabel `site_content` (dibuat OTOMATIS saat dashboard dibuka,
     tidak perlu phpMyAdmin). Bila belum ada / database bermasalah, halaman memakai teks bawaan.
   - Fungsi ppf_page_render() dipakai halaman publik DAN preview dashboard, jadi tampilannya selalu sama. */

require_once __DIR__ . '/markdown-lite.php';

const PPF_PAGE_MAX_LENGTH = 60000;

/**
 * Halaman yang bisa diedit: slug => label, url publik, kelas <main>, dan field-nya.
 * Tipe field: textarea (format markdown-lite), email, handle (akun Instagram).
 */
const PPF_PAGES = [
    'shipping' => [
        'label' => 'Shipping & Returns', 'url' => 'shipping.php', 'main_class' => 'legal-wrapper',
        'fields' => [
            'body'    => ['label' => 'Isi halaman', 'type' => 'textarea', 'rows' => 24, 'required' => true],
            'version' => ['label' => 'Catatan versi (baris kecil di bawah halaman)', 'type' => 'textarea', 'rows' => 2, 'required' => false],
        ],
    ],
    'about' => [
        'label' => 'About', 'url' => 'about.php', 'main_class' => '',
        'fields' => [
            'body' => ['label' => 'Isi halaman', 'type' => 'textarea', 'rows' => 12, 'required' => true],
        ],
    ],
    'contact' => [
        'label' => 'Contact', 'url' => 'contact.php', 'main_class' => 'contact-layout',
        'fields' => [
            'intro'     => ['label' => 'Teks pengantar', 'type' => 'textarea', 'rows' => 6, 'required' => true],
            'email'     => ['label' => 'Email', 'type' => 'email', 'required' => true],
            'instagram' => ['label' => 'Instagram (mis. @pastpresentfurther)', 'type' => 'handle', 'required' => true],
        ],
    ],
    'stockist' => [
        'label' => 'Stockist', 'url' => 'stockist.php', 'main_class' => 'legal-wrapper',
        'fields' => [
            'body' => ['label' => 'Daftar stockist', 'type' => 'textarea', 'rows' => 14, 'required' => true],
        ],
    ],
    'legal' => [
        'label' => 'Legal', 'url' => 'legal.php', 'main_class' => 'legal-wrapper',
        'fields' => [
            'body'    => ['label' => 'Isi halaman', 'type' => 'textarea', 'rows' => 24, 'required' => true],
            'version' => ['label' => 'Catatan versi (baris kecil di bawah halaman)', 'type' => 'textarea', 'rows' => 2, 'required' => false],
        ],
    ],
];

/* ── data ────────────────────────────────────────────── */

function ppf_page_exists(string $slug): bool
{
    return isset(PPF_PAGES[$slug]);
}

/** @return array<string,string> teks bawaan halaman */
function ppf_page_defaults(string $slug): array
{
    static $all = null;
    $all ??= require __DIR__ . '/page-content-defaults.php';
    return $all[$slug] ?? [];
}

function ppf_page_ensure_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS site_content (
            page       VARCHAR(40) NOT NULL,
            field      VARCHAR(40) NOT NULL,
            value      MEDIUMTEXT NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (page, field)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

/**
 * Teks yang sudah diubah admin (field => nilai). Bila tabel belum ada, dibuat otomatis.
 * Mengembalikan null kalau database tidak bisa dipakai.
 *
 * @return array<string,string>|null
 */
function ppf_page_saved(PDO $pdo, string $slug): ?array
{
    $read = static function () use ($pdo, $slug): array {
        $stmt = $pdo->prepare('SELECT field, value FROM site_content WHERE page = :page');
        $stmt->execute(['page' => $slug]);
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    };

    try {
        return $read();
    } catch (PDOException $e) {
        try {
            ppf_page_ensure_table($pdo);
            return $read();
        } catch (Throwable $e) {
            return null;
        }
    }
}

/** Teks halaman untuk ditampilkan: bawaan, ditimpa teks tersimpan. Tidak pernah gagal. */
function ppf_page_load(PDO $pdo, string $slug): array
{
    $values = ppf_page_defaults($slug);
    foreach (ppf_page_saved($pdo, $slug) ?? [] as $field => $value) {
        if (isset(PPF_PAGES[$slug]['fields'][$field])) {
            $values[$field] = $value;
        }
    }
    return $values;
}

/* ── validasi ────────────────────────────────────────── */

/**
 * Bersihkan + validasi input form satu halaman. Melempar InvalidArgumentException bila salah.
 *
 * @return array<string,string>
 */
function ppf_page_clean(string $slug, array $input): array
{
    if (!ppf_page_exists($slug)) {
        throw new InvalidArgumentException('Halaman tidak dikenal.');
    }

    $clean = [];
    foreach (PPF_PAGES[$slug]['fields'] as $field => $spec) {
        $value = str_replace(["\r\n", "\r"], "\n", trim((string) ($input[$field] ?? '')));

        if ($value === '' && $spec['required']) {
            throw new InvalidArgumentException('Kolom "' . $spec['label'] . '" tidak boleh kosong.');
        }
        if (mb_strlen($value) > PPF_PAGE_MAX_LENGTH) {
            throw new InvalidArgumentException('Kolom "' . $spec['label'] . '" terlalu panjang (maks. ' . PPF_PAGE_MAX_LENGTH . ' karakter).');
        }
        if ($spec['type'] === 'email' && $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Format email tidak valid.');
        }
        if ($spec['type'] === 'handle') {
            $value = '@' . ltrim($value, '@ ');
            if (preg_match('/^@[A-Za-z0-9._]{1,30}$/', $value) !== 1) {
                throw new InvalidArgumentException('Akun Instagram hanya boleh huruf, angka, titik, dan garis bawah (mis. @pastpresentfurther).');
            }
        }
        $clean[$field] = $value;
    }
    return $clean;
}

/* ── tulis ───────────────────────────────────────────── */

function ppf_page_save(PDO $pdo, string $slug, array $input): void
{
    $clean = ppf_page_clean($slug, $input);
    ppf_page_ensure_table($pdo);

    $stmt = $pdo->prepare(
        'INSERT INTO site_content (page, field, value) VALUES (:page, :field, :value)
         ON DUPLICATE KEY UPDATE value = VALUES(value)'
    );
    foreach ($clean as $field => $value) {
        $stmt->execute(['page' => $slug, 'field' => $field, 'value' => $value]);
    }
}

/** Hapus teks tersimpan; halaman kembali memakai teks bawaan. */
function ppf_page_reset(PDO $pdo, string $slug): void
{
    if (!ppf_page_exists($slug)) {
        throw new InvalidArgumentException('Halaman tidak dikenal.');
    }
    ppf_page_ensure_table($pdo);
    $pdo->prepare('DELETE FROM site_content WHERE page = :page')->execute(['page' => $slug]);
}

/* ── tampilan ────────────────────────────────────────── */

function ppf_page_render_contact(array $values): string
{
    $email     = htmlspecialchars($values['email'] ?? '', ENT_QUOTES, 'UTF-8');
    $instagram = htmlspecialchars($values['instagram'] ?? '', ENT_QUOTES, 'UTF-8');
    $handle    = htmlspecialchars(ltrim($values['instagram'] ?? '', '@'), ENT_QUOTES, 'UTF-8');

    return '<section class="contact-copy">'
        . ppf_md_blocks($values['intro'] ?? '')
        . '<div class="contact-meta">'
        . '<div><span class="label">Email</span><a href="mailto:' . $email . '">' . $email . '</a></div>'
        . '<div><span class="label">Instagram</span><a href="https://instagram.com/' . $handle . '" target="_blank" rel="noopener">' . $instagram . '</a></div>'
        . '</div></section>';
}

function ppf_page_render_version(array $values): string
{
    $version = trim($values['version'] ?? '');
    return $version === '' ? '' : '<p class="legal-version">' . ppf_md_inline($version) . '</p>';
}

/**
 * Isi halaman (bagian dalam <main>) untuk $slug dari nilai-nilai field.
 * Dipakai halaman publik dan preview dashboard.
 */
function ppf_page_render(string $slug, array $values): string
{
    switch ($slug) {
        case 'shipping':
        case 'legal':
            return ppf_md_sections($values['body'] ?? '') . "\n" . ppf_page_render_version($values);
        case 'stockist':
            return ppf_md_sections($values['body'] ?? '', 'legal-section stockist');
        case 'about':
            return '<section class="page-text">' . ppf_md_blocks($values['body'] ?? '') . '</section>';
        case 'contact':
            return ppf_page_render_contact($values);
    }
    return '';
}
