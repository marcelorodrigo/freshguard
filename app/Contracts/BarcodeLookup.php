<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Support\BarcodeLookupResult;

interface BarcodeLookup
{
    public function lookup(string $barcode): BarcodeLookupResult;
}
