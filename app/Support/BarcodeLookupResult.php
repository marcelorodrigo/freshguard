<?php

declare(strict_types=1);

namespace App\Support;

final class BarcodeLookupResult
{
    private const string STATUS_SUCCESS = 'success';

    private const string STATUS_NOT_FOUND = 'not_found';

    private const string STATUS_UPSTREAM_FAILURE = 'upstream_failure';

    private const string STATUS_INVALID = 'invalid';

    /**
     * @param  array<int|string, mixed>  $productData
     */
    private function __construct(
        private readonly string $status,
        private readonly array $productData = [],
    ) {}

    /**
     * @param  array<int|string, mixed>  $productData
     */
    public static function success(array $productData): self
    {
        return new self(self::STATUS_SUCCESS, $productData);
    }

    public static function notFound(): self
    {
        return new self(self::STATUS_NOT_FOUND);
    }

    public static function upstreamFailure(): self
    {
        return new self(self::STATUS_UPSTREAM_FAILURE);
    }

    public static function invalid(): self
    {
        return new self(self::STATUS_INVALID);
    }

    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    public function isNotFound(): bool
    {
        return $this->status === self::STATUS_NOT_FOUND;
    }

    public function isUpstreamFailure(): bool
    {
        return $this->status === self::STATUS_UPSTREAM_FAILURE;
    }

    public function isInvalid(): bool
    {
        return $this->status === self::STATUS_INVALID;
    }

    /**
     * @return array<int|string, mixed>
     */
    public function getProductData(): array
    {
        return $this->productData;
    }
}
