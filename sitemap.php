<?php
/** Sitemap อัตโนมัติ (รวมหน้ารถทุกคัน) */
require_once __DIR__ . '/includes/functions.php';
header('Content-Type: application/xml; charset=utf-8');

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base   = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . BASE_URL;

$urls = [
    ['loc' => $base . '/index.php',   'pri' => '1.0', 'freq' => 'weekly'],
    ['loc' => $base . '/cars.php',    'pri' => '0.9', 'freq' => 'daily'],
    ['loc' => $base . '/contact.php', 'pri' => '0.6', 'freq' => 'monthly'],
    ['loc' => $base . '/terms.php',   'pri' => '0.4', 'freq' => 'yearly'],
];
foreach (db_all("SELECT id, updated_at FROM cars WHERE status <> 'inactive' ORDER BY sort_order") as $c) {
    $urls[] = ['loc' => $base . '/car.php?id=' . (int) $c['id'], 'pri' => '0.8',
               'freq' => 'weekly', 'mod' => date('Y-m-d', strtotime($c['updated_at']))];
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $u) {
    echo "  <url>\n    <loc>" . e($u['loc']) . "</loc>\n";
    if (!empty($u['mod'])) echo '    <lastmod>' . e($u['mod']) . "</lastmod>\n";
    echo '    <changefreq>' . e($u['freq']) . "</changefreq>\n";
    echo '    <priority>' . e($u['pri']) . "</priority>\n  </url>\n";
}
echo '</urlset>';
