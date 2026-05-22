<?php

declare(strict_types=1);

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use StepDispatcher\Support\ExceptionParser;

// =============================================================================
// String Error Code Tests (Bitget, Bybit format)
// =============================================================================

test('parses string error codes from API responses', function (): void {
    // Bitget returns error codes as strings like "40808"
    $response = new Response(400, [], json_encode([
        'code' => '40808',
        'msg' => 'Parameter verification exception',
    ]));

    $request = new Request('GET', 'https://api.bitget.com/test');
    $exception = RequestException::create($request, $response);

    $parser = ExceptionParser::with($exception);

    expect($parser->errorCode())->toBe('40808');
    expect($parser->errorMsg())->toBe('Parameter verification exception');
    expect($parser->httpStatusCode())->toBe(400);
});

test('parses integer error codes from API responses', function (): void {
    // Some APIs return integer codes
    $response = new Response(400, [], json_encode([
        'code' => 10001,
        'msg' => 'Invalid parameter',
    ]));

    $request = new Request('GET', 'https://api.example.com/test');
    $exception = RequestException::create($request, $response);

    $parser = ExceptionParser::with($exception);

    expect($parser->errorCode())->toBe(10001);
    expect($parser->errorMsg())->toBe('Invalid parameter');
});

test('handles missing error code gracefully', function (): void {
    $response = new Response(500, [], json_encode([
        'msg' => 'Internal server error',
    ]));

    $request = new Request('GET', 'https://api.example.com/test');
    $exception = RequestException::create($request, $response);

    $parser = ExceptionParser::with($exception);

    expect($parser->errorCode())->toBeNull();
    expect($parser->errorMsg())->toBe('Internal server error');
});

test('handles missing msg gracefully', function (): void {
    $response = new Response(400, [], json_encode([
        'code' => '40001',
    ]));

    $request = new Request('GET', 'https://api.example.com/test');
    $exception = RequestException::create($request, $response);

    $parser = ExceptionParser::with($exception);

    expect($parser->errorCode())->toBe('40001');
    expect($parser->errorMsg())->toBeNull();
});

test('handles empty JSON response gracefully', function (): void {
    $response = new Response(400, [], '{}');

    $request = new Request('GET', 'https://api.example.com/test');
    $exception = RequestException::create($request, $response);

    $parser = ExceptionParser::with($exception);

    expect($parser->errorCode())->toBeNull();
    expect($parser->errorMsg())->toBeNull();
    expect($parser->httpStatusCode())->toBe(400);
});

test('handles non-JSON response gracefully', function (): void {
    $response = new Response(500, [], 'Internal Server Error');

    $request = new Request('GET', 'https://api.example.com/test');
    $exception = RequestException::create($request, $response);

    $parser = ExceptionParser::with($exception);

    expect($parser->errorCode())->toBeNull();
    expect($parser->errorMsg())->toBeNull();
    expect($parser->httpStatusCode())->toBe(500);
});

// =============================================================================
// Friendly Message Tests
// =============================================================================

test('friendlyMessage includes string error code', function (): void {
    $response = new Response(400, [], json_encode([
        'code' => '40808',
        'msg' => 'Parameter verification exception',
    ]));

    $request = new Request('GET', 'https://api.bitget.com/test');
    $exception = RequestException::create($request, $response);

    $parser = ExceptionParser::with($exception);

    expect($parser->friendlyMessage())->toContain('(code 40808)');
    expect($parser->friendlyMessage())->toContain('Parameter verification exception');
});

test('friendlyMessage includes integer error code', function (): void {
    $response = new Response(400, [], json_encode([
        'code' => 10001,
        'msg' => 'Invalid parameter',
    ]));

    $request = new Request('GET', 'https://api.example.com/test');
    $exception = RequestException::create($request, $response);

    $parser = ExceptionParser::with($exception);

    expect($parser->friendlyMessage())->toContain('(code 10001)');
});

test('friendlyMessage omits code when not present', function (): void {
    $response = new Response(400, [], json_encode([
        'msg' => 'Something went wrong',
    ]));

    $request = new Request('GET', 'https://api.example.com/test');
    $exception = RequestException::create($request, $response);

    $parser = ExceptionParser::with($exception);

    expect($parser->friendlyMessage())->not->toContain('(code');
});

// =============================================================================
// Non-RequestException Tests
// =============================================================================

test('handles regular exceptions without HTTP context', function (): void {
    $exception = new RuntimeException('Something went wrong', 123);

    $parser = ExceptionParser::with($exception);

    expect($parser->errorCode())->toBeNull();
    expect($parser->errorMsg())->toBeNull();
    expect($parser->httpStatusCode())->toBeNull();
    expect($parser->errorMessage())->toBe('Something went wrong');
    expect($parser->className())->toBe('RuntimeException');
});

test('captures exception file and line', function (): void {
    $exception = new RuntimeException('Test error');

    $parser = ExceptionParser::with($exception);

    expect($parser->lineNumber())->toBeInt();
    expect($parser->filename())->toBeString();
    expect($parser->stackTrace())->toBeString();
});

// =============================================================================
// Edge Cases for Error Codes
// =============================================================================

test('handles null code value in JSON', function (): void {
    $response = new Response(400, [], json_encode([
        'code' => null,
        'msg' => 'Error message',
    ]));

    $request = new Request('GET', 'https://api.example.com/test');
    $exception = RequestException::create($request, $response);

    $parser = ExceptionParser::with($exception);

    expect($parser->errorCode())->toBeNull();
});

test('handles array code value in JSON (invalid format)', function (): void {
    $response = new Response(400, [], json_encode([
        'code' => ['invalid' => 'format'],
        'msg' => 'Error message',
    ]));

    $request = new Request('GET', 'https://api.example.com/test');
    $exception = RequestException::create($request, $response);

    $parser = ExceptionParser::with($exception);

    // Should gracefully handle non-scalar code values
    expect($parser->errorCode())->toBeNull();
});

test('handles boolean code value in JSON (invalid format)', function (): void {
    $response = new Response(400, [], json_encode([
        'code' => true,
        'msg' => 'Error message',
    ]));

    $request = new Request('GET', 'https://api.example.com/test');
    $exception = RequestException::create($request, $response);

    $parser = ExceptionParser::with($exception);

    // Should gracefully handle non-scalar code values
    expect($parser->errorCode())->toBeNull();
});

// =============================================================================
// Real-world API Response Examples
// =============================================================================

test('parses Bitget 400 Bad Request response correctly', function (): void {
    // Real Bitget error response format
    $response = new Response(400, [], json_encode([
        'code' => '40808',
        'msg' => 'Parameter verification exception',
        'requestTime' => 1704456789000,
    ]));

    $request = new Request('GET', 'https://api.bitget.com/api/v2/mix/market/candles?symbol=BTCUSDT&granularity=4h');
    $exception = RequestException::create($request, $response);

    $parser = ExceptionParser::with($exception);

    expect($parser->errorCode())->toBe('40808');
    expect($parser->errorMsg())->toBe('Parameter verification exception');
    expect($parser->httpStatusCode())->toBe(400);
    expect($parser->friendlyMessage())->toContain('40808');
});

test('parses Binance error response correctly', function (): void {
    // Binance uses integer codes
    $response = new Response(400, [], json_encode([
        'code' => -1121,
        'msg' => 'Invalid symbol.',
    ]));

    $request = new Request('GET', 'https://api.binance.com/api/v3/ticker');
    $exception = RequestException::create($request, $response);

    $parser = ExceptionParser::with($exception);

    expect($parser->errorCode())->toBe(-1121);
    expect($parser->errorMsg())->toBe('Invalid symbol.');
});
