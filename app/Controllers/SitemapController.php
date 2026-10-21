<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Response;
use App\Models\Car;

/** Sitemap อัตโนมัติ (รวมหน้ารถทุกคัน) */
class SitemapController extends Controller
{
    public function index(): Response
    {
        $urls = [
            ['loc' => site_url(''),        'pri' => '1.0', 'freq' => 'weekly'],
            ['loc' => site_url('cars'),    'pri' => '0.9', 'freq' => 'daily'],
            ['loc' => site_url('how-to'),  'pri' => '0.6', 'freq' => 'monthly'],
            ['loc' => site_url('faq'),     'pri' => '0.6', 'freq' => 'monthly'],
            ['loc' => site_url('contact'), 'pri' => '0.6', 'freq' => 'monthly'],
            ['loc' => site_url('terms'),   'pri' => '0.4', 'freq' => 'yearly'],
        ];
        foreach (Car::sitemapRows() as $c) {
            $urls[] = [
                'loc'  => site_url('cars/' . (int) $c['id']),
                'pri'  => '0.8',
                'freq' => 'weekly',
                'mod'  => date('Y-m-d', strtotime((string) $c['updated_at'])),
            ];
        }

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $u) {
            $xml .= "  <url>\n    <loc>" . e($u['loc']) . "</loc>\n";
            if (!empty($u['mod'])) {
                $xml .= '    <lastmod>' . e($u['mod']) . "</lastmod>\n";
            }
            $xml .= '    <changefreq>' . e($u['freq']) . "</changefreq>\n";
            $xml .= '    <priority>' . e($u['pri']) . "</priority>\n  </url>\n";
        }
        $xml .= '</urlset>';

        return Response::text($xml, 200, 'application/xml');
    }
}
