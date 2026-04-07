<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Portal;

use App\Domain\ClientPortal\Services\ClientPortalService;
use App\Domain\Document\Models\Document;
use App\Domain\Document\Services\DocumentService;
use App\Http\Controllers\Controller;
use App\Http\Requests\ClientPortal\UploadDocumentRequest;
use App\Http\Resources\PortalDocumentResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClientPortalDocumentController extends Controller
{
    public function __construct(
        private readonly ClientPortalService $portalService,
        private readonly DocumentService $documentService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return PortalDocumentResource::collection(
            $this->portalService->listDocuments(app('portal.client'), [
                'search' => $request->query('search'),
                'category' => $request->query('category'),
                'per_page' => $request->query('per_page', 15),
            ]),
        );
    }

    public function store(UploadDocumentRequest $request): PortalDocumentResource
    {
        $document = $this->documentService->upload($request->file('file'), [
            ...$request->validated(),
            'client_id' => app('portal.client')->id,
        ]);

        return new PortalDocumentResource($document);
    }

    public function download(Document $document): StreamedResponse
    {
        abort_unless($document->client_id === app('portal.client')->id, 403, 'This document does not belong to your account.');

        return $this->documentService->download($document);
    }
}
