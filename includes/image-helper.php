<?php
/* ─────────────────────────────────────────────────────────────
   Save an uploaded image as WebP (smaller, faster pages).
   Returns the saved file name. Falls back to the original file when
   WebP conversion is not possible (animated GIF, no GD/ImageMagick).

   $name = ppf_save_image_webp($tmpPath, $mime, $dir, 'product_');
───────────────────────────────────────────────────────────── */

const PPF_IMG_MAX_EDGE = 1800; // px, longest side
const PPF_IMG_QUALITY  = 82;

function ppf_save_image_webp(string $tmp, string $mime, string $dir, string $prefix): string
{
    $base = uniqid($prefix, true);
    $ext  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$mime] ?? 'jpg';
    $keepOriginal = static function () use ($tmp, $dir, $base, $ext): string {
        $file = $base . '.' . $ext;
        if (!move_uploaded_file($tmp, $dir . $file) && !@rename($tmp, $dir . $file)) {
            throw new RuntimeException('Failed to move uploaded image.');
        }
        return $file;
    };

    // already WebP, or GIF (may be animated) → keep as is
    if ($mime === 'image/webp' || $mime === 'image/gif') return $keepOriginal();

    $out = $dir . $base . '.webp';

    // 1) PHP GD
    if (function_exists('imagewebp') && ($mime === 'image/jpeg' || $mime === 'image/png')) {
        $im = $mime === 'image/png' ? @imagecreatefrompng($tmp) : @imagecreatefromjpeg($tmp);
        if ($im) {
            if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
                $o = @exif_read_data($tmp)['Orientation'] ?? 1;
                $rot = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
                if ($rot) { $im = imagerotate($im, $rot, 0) ?: $im; }
            }
            $w = imagesx($im); $h = imagesy($im);
            $s = min(1, PPF_IMG_MAX_EDGE / max($w, $h));
            if ($s < 1) { $im = imagescale($im, (int) round($w * $s), (int) round($h * $s)) ?: $im; }
            imagepalettetotruecolor($im);
            imagealphablending($im, false);
            imagesavealpha($im, true);
            $ok = imagewebp($im, $out, PPF_IMG_QUALITY);
            imagedestroy($im);
            if ($ok && is_file($out) && filesize($out) > 0 && filesize($out) < filesize($tmp)) { @unlink($tmp); return $base . '.webp'; }
            @unlink($out);
        }
    }

    // 2) ImageMagick command line
    if (function_exists('exec')) {
        $bin = trim((string) @shell_exec('command -v magick || command -v convert'));
        if ($bin !== '') {
            $cmd = escapeshellarg($bin) . ' ' . escapeshellarg($tmp . '[0]')
                 . ' -auto-orient -resize ' . PPF_IMG_MAX_EDGE . 'x' . PPF_IMG_MAX_EDGE . '\\>'
                 . ' -quality ' . PPF_IMG_QUALITY . ' ' . escapeshellarg('webp:' . $out) . ' 2>&1';
            @exec($cmd);
            if (is_file($out) && filesize($out) > 0 && filesize($out) < filesize($tmp)) { @unlink($tmp); return $base . '.webp'; }
            @unlink($out);
        }
    }

    return $keepOriginal();
}
