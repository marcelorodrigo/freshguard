<?php

declare(strict_types=1);

use App\Support\OpenFoodFactsBarcodeLookup;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use OpenFoodFacts\Api;
use OpenFoodFacts\Exception\BadRequestException;

beforeEach(function (): void {
    Cache::flush();
});

function buildApiWithMockHandler(MockHandler $handler): Api
{
    $client = new Client(['handler' => HandlerStack::create($handler), 'timeout' => 5, 'connect_timeout' => 3]);

    return new Api('food', 'world', null, $client, null);
}

test('lookup returns success with product data', function (): void {
    $handler = new MockHandler([
        new Response(200, [], (string) json_encode(['status' => 1, 'product' => [
            'code' => '3017620422003',
            'product_name' => 'Nutella',
            'generic_name' => 'Hazelnut spread',
        ]])),
    ]);
    $api = buildApiWithMockHandler($handler);

    $result = (new OpenFoodFactsBarcodeLookup($api))->lookup('3017620422003');

    expect($result->isSuccess())->toBeTrue();
    expect($result->getProductData()['product_name'])->toBe('Nutella');
});

test('lookup returns not found when product is missing', function (): void {
    $handler = new MockHandler([
        new Response(200, [], (string) json_encode(['status' => 0])),
    ]);
    $api = buildApiWithMockHandler($handler);

    $result = (new OpenFoodFactsBarcodeLookup($api))->lookup('0000000000000');

    expect($result->isNotFound())->toBeTrue();
});

test('lookup returns upstream failure on api exception without sensitive log payload', function (): void {
    Log::shouldReceive('warning')->once()->withArgs(function (string $message, array $context): bool {
        return ! array_key_exists('barcode', $context)
            && ! array_key_exists('error', $context)
            && array_key_exists('barcode_length', $context)
            && array_key_exists('exception', $context);
    });

    $handler = new MockHandler([
        new Response(503),
        new Response(503),
        new Response(503),
    ]);
    $api = buildApiWithMockHandler($handler);

    $result = (new OpenFoodFactsBarcodeLookup($api))->lookup('3017620422003');

    expect($result->isUpstreamFailure())->toBeTrue();
});

test('lookup returns invalid for non numeric barcode', function (): void {
    $handler = new MockHandler([
        new Response(200, [], (string) json_encode(['status' => 1, 'product' => ['code' => 'x']])),
    ]);
    $api = buildApiWithMockHandler($handler);

    $result = (new OpenFoodFactsBarcodeLookup($api))->lookup('ABC-123');

    expect($result->isInvalid())->toBeTrue();
    expect($handler->count())->toBe(1);
});

test('lookup returns invalid for barcode outside length bounds', function (): void {
    $handler = new MockHandler([
        new Response(200, [], (string) json_encode(['status' => 1, 'product' => ['code' => 'x']])),
    ]);
    $api = buildApiWithMockHandler($handler);

    $result = (new OpenFoodFactsBarcodeLookup($api))->lookup('123');

    expect($result->isInvalid())->toBeTrue();
    expect($handler->count())->toBe(1);
});

test('lookup caches successful results for repeated calls', function (): void {
    $handler = new MockHandler([
        new Response(200, [], (string) json_encode(['status' => 1, 'product' => ['code' => '3017620422003', 'product_name' => 'Nutella']])),
    ]);
    $api = buildApiWithMockHandler($handler);
    $lookup = new OpenFoodFactsBarcodeLookup($api);

    expect($lookup->lookup('3017620422003')->isSuccess())->toBeTrue();
    expect($lookup->lookup('3017620422003')->isSuccess())->toBeTrue();
});

test('lookup caches not found results', function (): void {
    $handler = new MockHandler([
        new Response(200, [], (string) json_encode(['status' => 0])),
    ]);
    $api = buildApiWithMockHandler($handler);
    $lookup = new OpenFoodFactsBarcodeLookup($api);

    expect($lookup->lookup('0000000000000')->isNotFound())->toBeTrue();
    expect($lookup->lookup('0000000000000')->isNotFound())->toBeTrue();
});

test('build client configures explicit timeouts', function (): void {
    $client = OpenFoodFactsBarcodeLookup::buildClient();

    expect($client->getConfig('timeout'))->toBe(5);
    expect($client->getConfig('connect_timeout'))->toBe(3);
});

test('retryable server error is retried then surfaces as upstream failure', function (): void {
    $handler = new MockHandler([
        new Response(503),
        new Response(503),
        new Response(503),
    ]);
    $api = buildApiWithMockHandler($handler);

    $result = (new OpenFoodFactsBarcodeLookup($api))->lookup('1111111111111');

    expect($result->isUpstreamFailure())->toBeTrue();
    expect($handler->count())->toBe(0);
});

test('retryable server error recovers after transient failures', function (): void {
    $handler = new MockHandler([
        new Response(503),
        new Response(503),
        new Response(200, [], (string) json_encode(['status' => 1, 'product' => ['code' => '2222222222222', 'product_name' => 'Nutella']])),
    ]);
    $api = buildApiWithMockHandler($handler);

    $result = (new OpenFoodFactsBarcodeLookup($api))->lookup('2222222222222');

    expect($result->isSuccess())->toBeTrue();
    expect($handler->count())->toBe(0);
});

test('non retryable client error surfaces immediately as upstream failure', function (): void {
    $handler = new MockHandler([
        new Response(404),
    ]);
    $api = buildApiWithMockHandler($handler);

    $result = (new OpenFoodFactsBarcodeLookup($api))->lookup('3333333333333');

    expect($result->isUpstreamFailure())->toBeTrue();
});

test('connect exception is retried and recovers', function (): void {
    $handler = new MockHandler([
        new ConnectException('timeout', new Request('GET', 'https://world.openfoodfacts.org')),
        new ConnectException('timeout', new Request('GET', 'https://world.openfoodfacts.org')),
        new Response(200, [], (string) json_encode(['status' => 1, 'product' => ['code' => '4444444444444', 'product_name' => 'Nutella']])),
    ]);
    $api = buildApiWithMockHandler($handler);

    $result = (new OpenFoodFactsBarcodeLookup($api))->lookup('4444444444444');

    expect($result->isSuccess())->toBeTrue();
});

test('shouldRetry retries connection exceptions', function (): void {
    $lookup = new OpenFoodFactsBarcodeLookup(buildApiWithMockHandler(new MockHandler));

    $retries = $lookup->shouldRetry(
        new BadRequestException('timeout', 0, new ConnectException('timeout', new Request('GET', 'https://example.com'))),
    );

    expect($retries)->toBeTrue();
});

test('shouldRetry retries server errors but not client errors', function (): void {
    $lookup = new OpenFoodFactsBarcodeLookup(buildApiWithMockHandler(new MockHandler));

    $retryServer = $lookup->shouldRetry(
        new BadRequestException('Server error', 0, new ServerException('boom', new Request('GET', 'https://example.com'), new Response(503))),
    );

    $noRetryClient = $lookup->shouldRetry(
        new BadRequestException('Client error', 0, new ClientException('boom', new Request('GET', 'https://example.com'), new Response(404))),
    );

    expect($retryServer)->toBeTrue();
    expect($noRetryClient)->toBeFalse();
});

test('shouldRetry does not retry unrelated exceptions', function (): void {
    $lookup = new OpenFoodFactsBarcodeLookup(buildApiWithMockHandler(new MockHandler));

    expect($lookup->shouldRetry(new RuntimeException('boom')))->toBeFalse();
});
