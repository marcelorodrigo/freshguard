<?php

declare(strict_types=1);

namespace App\Support;

use App\Contracts\BarcodeLookup;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use OpenFoodFacts\Api;
use OpenFoodFacts\Document;
use OpenFoodFacts\Exception\BadRequestException;
use OpenFoodFacts\Exception\ProductNotFoundException;
use Throwable;

final class OpenFoodFactsBarcodeLookup implements BarcodeLookup
{
    private const int CONNECT_TIMEOUT_SECONDS = 3;

    private const int RESPONSE_TIMEOUT_SECONDS = 5;

    private const int MAX_ATTEMPTS = 3;

    private const int SUCCESS_TTL_SECONDS = 86400;

    private const int NOT_FOUND_TTL_SECONDS = 3600;

    private const int MIN_BARCODE_LENGTH = 8;

    private const int MAX_BARCODE_LENGTH = 14;

    private const int BASE_RETRY_DELAY_MS = 100;

    public function __construct(
        private readonly Api $api,
        private readonly int $maxAttempts = self::MAX_ATTEMPTS,
    ) {}

    public function lookup(string $barcode): BarcodeLookupResult
    {
        if (! $this->isValidBarcode($barcode)) {
            return BarcodeLookupResult::invalid();
        }

        $cacheKey = $this->cacheKey($barcode);

        if (Cache::has($cacheKey)) {
            /** @var BarcodeLookupResult $cached */
            $cached = Cache::get($cacheKey);

            return $cached;
        }

        try {
            $document = retry(
                $this->maxAttempts,
                fn (): Document => $this->api->getProduct($barcode),
                $this->retryDelay(),
                fn (Throwable $e): bool => $this->shouldRetry($e),
            );
        } catch (ProductNotFoundException) {
            $result = BarcodeLookupResult::notFound();
            Cache::put($cacheKey, $result, self::NOT_FOUND_TTL_SECONDS);

            return $result;
        } catch (Throwable $e) {
            $this->reportUpstreamFailure($barcode, $e);

            return BarcodeLookupResult::upstreamFailure();
        }

        $result = BarcodeLookupResult::success($document->getData());
        Cache::put($cacheKey, $result, self::SUCCESS_TTL_SECONDS);

        return $result;
    }

    public static function buildClient(
        int $connectTimeout = self::CONNECT_TIMEOUT_SECONDS,
        int $responseTimeout = self::RESPONSE_TIMEOUT_SECONDS,
    ): ClientInterface {
        /** @var string $appName */
        $appName = is_string($name = config('app.name')) ? $name : 'FreshGuard';

        return new Client([
            'connect_timeout' => $connectTimeout,
            'timeout' => $responseTimeout,
            'headers' => [
                'User-Agent' => $appName,
            ],
        ]);
    }

    public function shouldRetry(Throwable $e): bool
    {
        $reason = $e;

        if ($e instanceof BadRequestException && $e->getPrevious() instanceof Throwable) {
            $reason = $e->getPrevious();
        }

        return $reason instanceof ConnectException || $reason instanceof ServerException;
    }

    private function retryDelay(): callable
    {
        return fn (int $attempts): int => self::BASE_RETRY_DELAY_MS * (2 ** max(0, $attempts - 1));
    }

    private function isValidBarcode(string $barcode): bool
    {
        return ctype_digit($barcode)
            && strlen($barcode) >= self::MIN_BARCODE_LENGTH
            && strlen($barcode) <= self::MAX_BARCODE_LENGTH;
    }

    private function cacheKey(string $barcode): string
    {
        return 'barcode_lookup:'.$barcode;
    }

    private function reportUpstreamFailure(string $barcode, Throwable $e): void
    {
        Log::warning('OpenFoodFacts barcode lookup failed', [
            'barcode_length' => strlen($barcode),
            'exception' => get_class($e),
        ]);
    }
}
