<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;

class TaxReturnController extends Controller
{
    public function index(): void {}

    public function show(): void {}

    public function calculateCorporateTax(): void {}

    public function calculateVatReturn(): void {}

    public function file(): void {}

    public function recordPayment(): void {}

    public function adjustments(): void {}

    public function storeAdjustment(): void {}

    public function destroyAdjustment(): void {}
}
