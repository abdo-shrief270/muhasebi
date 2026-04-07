<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Blog\Models\BlogPost;
use Illuminate\Console\Command;

class PublishScheduledPostsCommand extends Command
{
    protected $signature = 'blog:publish-scheduled';

    protected $description = 'Publish blog posts whose published_at date has passed';

    public function handle(): int
    {
        $count = BlogPost::where('is_published', false)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->update(['is_published' => true]);

        if ($count > 0) {
            $this->info("Published {$count} scheduled post(s).");
        }

        return self::SUCCESS;
    }
}
