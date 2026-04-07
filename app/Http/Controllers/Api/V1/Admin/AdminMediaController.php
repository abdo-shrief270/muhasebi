<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Blog\Models\BlogPost;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\File;

class AdminMediaController extends Controller
{
    /**
     * Upload an image for a blog post cover.
     *
     * POST /admin/blog/posts/{post}/cover
     */
    public function uploadBlogCover(Request $request, BlogPost $post): JsonResponse
    {
        $request->validate([
            'image' => ['required', File::image()->max(5 * 1024)->types(['jpg', 'jpeg', 'png', 'webp'])],
        ]);

        $media = $post->addMediaFromRequest('image')
            ->usingFileName(time() . '_' . $request->file('image')->getClientOriginalName())
            ->toMediaCollection('cover');

        $post->update(['cover_image' => $media->getUrl()]);

        return response()->json([
            'data' => [
                'url' => $media->getUrl(),
                'thumb' => $media->getUrl('thumb'),
                'og' => $media->getUrl('og'),
            ],
        ]);
    }

    /**
     * Generic image upload for rich-text editor content.
     * Returns a URL to embed in HTML.
     *
     * POST /admin/upload/image
     */
    public function uploadEditorImage(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', File::image()->max(3 * 1024)->types(['jpg', 'jpeg', 'png', 'webp', 'gif'])],
        ]);

        $path = $request->file('image')->store('uploads/editor', 'public');

        return response()->json([
            'data' => [
                'url' => asset('storage/' . $path),
            ],
        ]);
    }

    /**
     * Delete a media item.
     *
     * DELETE /admin/media/{mediaId}
     */
    public function destroy(int $mediaId): JsonResponse
    {
        $media = \Spatie\MediaLibrary\MediaCollections\Models\Media::findOrFail($mediaId);
        $media->delete();

        return response()->json(['message' => 'Media deleted.']);
    }
}
