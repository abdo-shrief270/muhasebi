<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Blog\Models\BlogPost;
use App\Domain\Cms\Models\CmsPage;
use Illuminate\Console\Command;

class GenerateSitemapCommand extends Command
{
    protected $signature = 'sitemap:generate';

    protected $description = 'Generate sitemap.xml from published pages and blog posts';

    public function handle(): int
    {
        $frontendUrl = rtrim(config('app.frontend_url', config('app.url')), '/');

        $urls = collect();

        // Static pages
        $urls->push(['loc' => $frontendUrl, 'priority' => '1.0', 'changefreq' => 'weekly']);
        $urls->push(['loc' => $frontendUrl.'/blog', 'priority' => '0.8', 'changefreq' => 'daily']);
        $urls->push(['loc' => $frontendUrl.'/contact', 'priority' => '0.6', 'changefreq' => 'monthly']);
        $urls->push(['loc' => $frontendUrl.'/changelog', 'priority' => '0.5', 'changefreq' => 'weekly']);

        // CMS pages
        CmsPage::published()->get()->each(function ($page) use ($urls, $frontendUrl) {
            $urls->push([
                'loc' => $frontendUrl.'/'.$page->slug,
                'lastmod' => $page->updated_at->toW3cString(),
                'priority' => '0.6',
                'changefreq' => 'monthly',
            ]);
        });

        // Blog posts
        BlogPost::published()->latest('published_at')->get()->each(function ($post) use ($urls, $frontendUrl) {
            $urls->push([
                'loc' => $frontendUrl.'/blog/'.$post->slug,
                'lastmod' => $post->updated_at->toW3cString(),
                'priority' => '0.7',
                'changefreq' => 'weekly',
            ]);
        });

        // Generate XML
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($urls as $url) {
            $xml .= "  <url>\n";
            $xml .= "    <loc>{$url['loc']}</loc>\n";
            if (isset($url['lastmod'])) {
                $xml .= "    <lastmod>{$url['lastmod']}</lastmod>\n";
            }
            $xml .= "    <changefreq>{$url['changefreq']}</changefreq>\n";
            $xml .= "    <priority>{$url['priority']}</priority>\n";
            $xml .= "  </url>\n";
        }

        $xml .= '</urlset>';

        $path = public_path('sitemap.xml');
        file_put_contents($path, $xml);

        $this->info("Sitemap generated with {$urls->count()} URLs at {$path}");

        return self::SUCCESS;
    }
}
