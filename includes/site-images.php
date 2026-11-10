<?php
/* Editable page photos (About, Contact …). Admin changes them in
   admin/dashboard-pages.php; pages fall back to the bundled default. */

function ppf_site_images_ready(PDO $pdo): bool
{
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS site_images (
                slug VARCHAR(40) NOT NULL PRIMARY KEY,
                path VARCHAR(255) NOT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        return true;
    } catch (Throwable $e) { return false; }
}

function ppf_site_image(PDO $pdo, string $slug, string $default): string
{
    try {
        if (!ppf_site_images_ready($pdo)) return $default;
        $st = $pdo->prepare('SELECT path FROM site_images WHERE slug = :s');
        $st->execute(['s' => $slug]);
        $path = (string) $st->fetchColumn();
        if ($path !== '' && is_file(__DIR__ . '/../' . $path)) {
            return $path . '?v=' . filemtime(__DIR__ . '/../' . $path);
        }
    } catch (Throwable $e) {}
    return $default;
}
