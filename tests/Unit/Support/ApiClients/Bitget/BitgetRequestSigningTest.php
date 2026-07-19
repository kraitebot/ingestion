<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Support\ApiClients\REST\BitgetApiClient;
use Kraite\Core\Support\ValueObjects\ApiProperties;
use Kraite\Core\Support\ValueObjects\ApiRequest;

uses()->group('unit', 'bitget', 'api-client', 'signing');

it('sorts signed GET query parameters alphabetically in both URL and signature', function (): void {
    ApiSystem::factory()->exchange()->create([
        'canonical' => 'bitget',
        'name' => 'Bitget Signing Test',
    ]);

    Http::fake([
        '*' => Http::response(['code' => '00000', 'data' => []]),
    ]);

    $client = new BitgetApiClient([
        'url' => 'https://api.bitget.test',
        'api_key' => 'SIGNING_KEY',
        'api_secret' => 'SIGNING_SECRET',
        'passphrase' => 'SIGNING_PASSPHRASE',
    ]);
    $request = ApiRequest::make(
        'GET',
        '/api/v2/mix/order/detail',
        ApiProperties::make([
            'options' => [
                'symbol' => 'BTCPERP',
                'productType' => 'USDC-FUTURES',
                'orderId' => '12345',
            ],
        ])
    );

    $client->signRequest($request);

    /** @var Request $sentRequest */
    $sentRequest = Http::recorded()->first()[0];
    $timestamp = $sentRequest->header('ACCESS-TIMESTAMP')[0];
    $path = '/api/v2/mix/order/detail?orderId=12345&productType=USDC-FUTURES&symbol=BTCPERP';
    $expectedSignature = base64_encode(hash_hmac(
        'sha256',
        $timestamp.'GET'.$path,
        'SIGNING_SECRET',
        true
    ));

    expect($sentRequest->url())->toBe('https://api.bitget.test'.$path)
        ->and($sentRequest->header('ACCESS-SIGN')[0])->toBe($expectedSignature);
});
