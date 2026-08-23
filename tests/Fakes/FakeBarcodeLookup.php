<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Contracts\BarcodeLookup;
use App\Support\BarcodeLookupResult;

final class FakeBarcodeLookup implements BarcodeLookup
{
    private ?BarcodeLookupResult $result = null;

    public function setResult(BarcodeLookupResult $result): void
    {
        $this->result = $result;
    }

    public function lookup(string $barcode): BarcodeLookupResult
    {
        if ($this->result === null) {
            return BarcodeLookupResult::notFound();
        }

        return $this->result;
    }
}
