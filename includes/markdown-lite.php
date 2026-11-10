<?php
/* Format teks sederhana untuk halaman yang diedit di dashboard (About, Contact, Stockist, Shipping, Legal).

   Aturan penulisan:
     ## Judul bagian         -> judul (<h2>); tiap judul memulai bagian baru
     baris kosong            -> paragraf baru
     - butir                 -> daftar (setiap baris diawali "- ")
     **tebal**               -> huruf tebal
     [teks](alamat)          -> tautan (halaman situs, #bagian, mailto:, atau http/https)
     baris baru biasa        -> pindah baris di dalam paragraf

   Semua teks di-escape lebih dulu, jadi HTML yang diketik admin tampil sebagai teks biasa. */

/* ── inline ──────────────────────────────────────────── */

/** Alamat tautan yang boleh dipakai: http(s), mailto:, #anchor, atau halaman situs (mis. shipping.php). */
function ppf_md_safe_url(string $url): bool
{
    return preg_match('#^(?:https?://|mailto:|\#|/|[a-z0-9][a-z0-9_./-]*(?:\?[^\s]*)?$)#i', $url) === 1
        && preg_match('#^(?:javascript|data|vbscript):#i', $url) !== 1;
}

function ppf_md_link(array $match): string
{
    // $match[2] sudah di-escape (&amp; dsb.), jadi aman dipakai langsung di atribut.
    $label = $match[1];
    $url   = htmlspecialchars_decode($match[2], ENT_QUOTES);
    if (!ppf_md_safe_url($url)) {
        return $match[0];
    }
    $external = preg_match('#^https?://#i', $url) === 1;
    return '<a href="' . $match[2] . '"' . ($external ? ' target="_blank" rel="noopener"' : '') . '>' . $label . '</a>';
}

/** Teks satu baris/paragraf -> HTML aman (tebal + tautan). */
function ppf_md_inline(string $text): string
{
    $html = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $html = preg_replace_callback('/\[([^\[\]]+)\]\(([^()\s]+)\)/u', 'ppf_md_link', $html);
    $html = preg_replace('/\*\*(.+?)\*\*/su', '<strong>$1</strong>', $html);
    return nl2br($html, false);
}

/* ── blok ────────────────────────────────────────────── */

/**
 * Pecah teks menjadi blok: ['h2'|'ul'|'p' => isi].
 *
 * @return array<int, array{type:string, text?:string, items?:array<int,string>}>
 */
function ppf_md_parse(string $source): array
{
    $source = str_replace(["\r\n", "\r"], "\n", trim($source));
    if ($source === '') {
        return [];
    }

    $blocks = [];
    foreach (preg_split('/\n{2,}/', $source) as $chunk) {
        $lines = array_values(array_filter(array_map('rtrim', explode("\n", $chunk)), 'strlen'));
        if ($lines === []) {
            continue;
        }

        if (preg_match('/^##\s+(.+)$/', $lines[0], $heading)) {
            $blocks[] = ['type' => 'h2', 'text' => trim($heading[1])];
            $lines = array_slice($lines, 1);
            if ($lines === []) {
                continue;
            }
        }

        $isList = count(array_filter($lines, static fn(string $l): bool => preg_match('/^-\s+/', $l) === 1)) === count($lines);
        if ($isList) {
            $blocks[] = ['type' => 'ul', 'items' => array_map(static fn(string $l): string => trim(substr(ltrim($l), 1)), $lines)];
        } else {
            $blocks[] = ['type' => 'p', 'text' => implode("\n", array_map('trim', $lines))];
        }
    }
    return $blocks;
}

function ppf_md_render_block(array $block): string
{
    switch ($block['type']) {
        case 'h2':
            return '<h2>' . ppf_md_inline($block['text']) . '</h2>';
        case 'ul':
            return '<ul>' . implode('', array_map(static fn(string $i): string => '<li>' . ppf_md_inline($i) . '</li>', $block['items'])) . '</ul>';
        default:
            return '<p>' . ppf_md_inline($block['text']) . '</p>';
    }
}

/** Blok-blok berurutan tanpa pembungkus (untuk About / Contact). */
function ppf_md_blocks(string $source): string
{
    return implode("\n", array_map('ppf_md_render_block', ppf_md_parse($source)));
}

/**
 * Bagian-bagian: tiap "## Judul" memulai <section>. Teks sebelum judul pertama jadi bagian sendiri.
 * Dipakai halaman bergaya dokumen (Shipping, Legal, Stockist).
 */
function ppf_md_sections(string $source, string $sectionClass = 'legal-section'): string
{
    $sections = [];
    foreach (ppf_md_parse($source) as $block) {
        if ($block['type'] === 'h2' || $sections === []) {
            $sections[] = [];
        }
        $sections[count($sections) - 1][] = $block;
    }

    $class = htmlspecialchars($sectionClass, ENT_QUOTES, 'UTF-8');
    return implode("\n", array_map(
        static fn(array $blocks): string => "<section class=\"{$class}\">\n" . implode("\n", array_map('ppf_md_render_block', $blocks)) . "\n</section>",
        $sections
    ));
}
