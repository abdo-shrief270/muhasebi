<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Blog\Models\BlogPost;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

class RssFeedController extends Controller
{
    public function __invoke(): Response
    {
        $posts = BlogPost::published()
            ->with('category')
            ->latest('published_at')
            ->limit(20)
            ->get();

        $frontendUrl = rtrim(config('app.frontend_url', config('app.url')), '/');
        $buildDate = $posts->first()?->published_at?->toRfc2822String() ?? now()->toRfc2822String();

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">'."\n";
        $xml .= "<channel>\n";
        $xml .= "  <title>Muhasebi Blog - مدونة محاسبي</title>\n";
        $xml .= "  <link>{$frontendUrl}/blog</link>\n";
        $xml .= "  <description>Accounting tips, tax updates, and news for Egyptian accounting firms.</description>\n";
        $xml .= "  <language>ar</language>\n";
        $xml .= "  <lastBuildDate>{$buildDate}</lastBuildDate>\n";
        $xml .= '  <atom:link href="'.url('/api/v1/blog/rss').'" rel="self" type="application/rss+xml"/>'."\n";

        foreach ($posts as $post) {
            $title = htmlspecialchars($post->title_ar.' | '.$post->title_en, ENT_XML1, 'UTF-8');
            $desc = htmlspecialchars($post->excerpt_en ?: strip_tags(mb_substr($post->content_en, 0, 300)), ENT_XML1, 'UTF-8');
            $link = "{$frontendUrl}/blog/{$post->slug}";
            $pubDate = $post->published_at->toRfc2822String();
            $category = $post->category ? htmlspecialchars($post->category->name_en, ENT_XML1, 'UTF-8') : '';

            $xml .= "  <item>\n";
            $xml .= "    <title>{$title}</title>\n";
            $xml .= "    <link>{$link}</link>\n";
            $xml .= "    <guid isPermaLink=\"true\">{$link}</guid>\n";
            $xml .= "    <description>{$desc}</description>\n";
            $xml .= "    <pubDate>{$pubDate}</pubDate>\n";
            if ($category) {
                $xml .= "    <category>{$category}</category>\n";
            }
            $xml .= "  </item>\n";
        }

        $xml .= "</channel>\n</rss>";

        return response($xml, 200, [
            'Content-Type' => 'application/rss+xml; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
