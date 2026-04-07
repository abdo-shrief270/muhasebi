<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tax\Models\WhtCertificate;
use App\Domain\Tax\Services\WhtCertificateService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tax\GenerateWhtCertificateRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhtCertificateController extends Controller
{
    public function __construct(
        private readonly WhtCertificateService $whtCertificateService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $certificates = $this->whtCertificateService->list([
            'vendor_id' => $request->query('vendor_id'),
            'status' => $request->query('status'),
            'period_from' => $request->query('period_from'),
            'period_to' => $request->query('period_to'),
            'per_page' => min((int) ($request->query('per_page', 15)), 100),
        ]);

        return $this->success($certificates);
    }

    public function generate(GenerateWhtCertificateRequest $request): JsonResponse
    {
        $certificate = $this->whtCertificateService->generate($request->validated());

        return $this->created($certificate->load('vendor'));
    }

    public function show(WhtCertificate $certificate): JsonResponse
    {
        $certificate->load(['vendor', 'lines']);

        return $this->success($certificate);
    }

    public function issue(WhtCertificate $certificate): JsonResponse
    {
        $certificate = $this->whtCertificateService->issue($certificate);

        return $this->success($certificate);
    }

    public function submit(WhtCertificate $certificate): JsonResponse
    {
        $certificate = $this->whtCertificateService->submit($certificate);

        return $this->success($certificate);
    }
}
