<?php

declare(strict_types=1);

namespace Tests\Support;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

/**
 * ResponseException - Factory for creating stubbed API exceptions for testing.
 *
 * Centralizes exception creation for all API systems (Binance, Bybit, Taapi, CoinMarketCap, AlternativeMe).
 * Each static method returns a properly configured RequestException that the corresponding
 * ExceptionHandler will classify correctly.
 *
 * Usage in tests:
 *   $exception = ResponseException::binanceIpNotWhitelisted();
 *   expect($handler->isIpNotWhitelisted($exception))->toBeTrue();
 *
 * 4 IP/Account Blocking Cases:
 *   Case 1: IP not whitelisted (user forgot to add IP) - User-fixable
 *   Case 2: IP temporarily rate-limited (auto-recovers)
 *   Case 3: IP permanently banned (contact exchange support)
 *   Case 4: Account blocked (API key revoked/invalid)
 */
final class ResponseException
{
    // =========================================================================
    // BINANCE EXCEPTIONS
    // =========================================================================

    /**
     * Binance Case 1: IP not whitelisted by user.
     * HTTP 401 with -2015 and message contains "IP".
     */
    public static function binanceIpNotWhitelisted(): RequestException
    {
        return self::binance(401, -2015, 'Invalid API-key, IP, or permissions for action. IP not whitelisted.');
    }

    /**
     * Binance Case 2: IP temporarily rate-limited.
     * HTTP 418 with Retry-After header.
     *
     * @param  int  $retryAfterSeconds  Seconds until rate limit lifts
     */
    public static function binanceIpRateLimited(int $retryAfterSeconds = 120): RequestException
    {
        return self::binance(418, null, 'IP temporarily banned due to rate limit', [
            'Retry-After' => (string) $retryAfterSeconds,
        ]);
    }

    /**
     * Binance Case 3: IP permanently banned.
     * HTTP 418 without Retry-After header.
     */
    public static function binanceIpBanned(): RequestException
    {
        return self::binance(418, null, 'IP banned');
    }

    /**
     * Binance Case 3 alternative: Very long Retry-After (>3 days = permanent).
     *
     * @param  int  $retryAfterSeconds  Seconds > 259200 (3 days)
     */
    public static function binanceIpBannedLongDuration(int $retryAfterSeconds = 604800): RequestException
    {
        return self::binance(418, null, 'IP banned for extended period', [
            'Retry-After' => (string) $retryAfterSeconds,
        ]);
    }

    /**
     * Binance Case 4: Account blocked (API key revoked/invalid).
     * HTTP 401 with -2015 without "IP" in message.
     */
    public static function binanceAccountBlocked(): RequestException
    {
        return self::binance(401, -2015, 'Invalid API-key, or permissions for action.');
    }

    /**
     * Binance Case 4 alternative: Invalid API key format.
     * HTTP 401 with -2014.
     */
    public static function binanceApiKeyInvalid(): RequestException
    {
        return self::binance(401, -2014, 'API-key format invalid.');
    }

    /**
     * Binance: HTTP 429 rate limit (standard).
     */
    public static function binanceRateLimited(): RequestException
    {
        return self::binance(429, null, 'Rate limit exceeded');
    }

    /**
     * Binance: HTTP 429 with Retry-After.
     *
     * @param  int  $retryAfterSeconds  Seconds until rate limit lifts
     */
    public static function binanceRateLimitedWithRetryAfter(int $retryAfterSeconds = 60): RequestException
    {
        return self::binance(429, null, 'Rate limit exceeded', [
            'Retry-After' => (string) $retryAfterSeconds,
        ]);
    }

    /**
     * Binance: HTTP 400 with -1003 (WAF limit).
     */
    public static function binanceWafLimit(): RequestException
    {
        return self::binance(400, -1003, 'WAF Limit exceeded');
    }

    /**
     * Binance: Ignorable - No need to change margin type.
     * HTTP 400 with -4046.
     */
    public static function binanceIgnorableMarginType(): RequestException
    {
        return self::binance(400, -4046, 'No need to change margin type');
    }

    /**
     * Binance: Ignorable - No need to modify order.
     * HTTP 400 with -5027.
     */
    public static function binanceIgnorableOrderModify(): RequestException
    {
        return self::binance(400, -5027, 'No need to modify the order');
    }

    /**
     * Binance: Ignorable - Unknown order.
     * HTTP 400 with -2011.
     */
    public static function binanceIgnorableUnknownOrder(): RequestException
    {
        return self::binance(400, -2011, 'Unknown order sent');
    }

    /**
     * Binance: Retryable - Service unavailable.
     * HTTP 503.
     */
    public static function binanceServiceUnavailable(): RequestException
    {
        return self::binance(503, null, 'Service unavailable');
    }

    /**
     * Binance: Retryable - Gateway timeout.
     * HTTP 504.
     */
    public static function binanceGatewayTimeout(): RequestException
    {
        return self::binance(504, null, 'Gateway timeout');
    }

    /**
     * Binance: Retryable - Order does not exist (eventual consistency).
     * HTTP 400 with -2013.
     */
    public static function binanceOrderNotFound(): RequestException
    {
        return self::binance(400, -2013, 'Order does not exist');
    }

    /**
     * Binance: recvWindow mismatch (spot).
     * HTTP 400 with -1021.
     */
    public static function binanceRecvWindowMismatch(): RequestException
    {
        return self::binance(400, -1021, 'Timestamp for this request is outside of the recvWindow');
    }

    /**
     * Binance: recvWindow mismatch (futures).
     * HTTP 400 with -5028.
     */
    public static function binanceRecvWindowMismatchFutures(): RequestException
    {
        return self::binance(400, -5028, 'Timestamp for this request is outside of the recvWindow');
    }

    /**
     * Create a Binance-style RequestException.
     *
     * @param  int  $httpStatus  HTTP status code
     * @param  int|null  $vendorCode  Binance vendor code (e.g., -2015)
     * @param  string  $message  Error message
     * @param  array<string, string>  $headers  Response headers
     */
    public static function binance(
        int $httpStatus,
        ?int $vendorCode,
        string $message,
        array $headers = []
    ): RequestException {
        $body = $vendorCode !== null
            ? ['code' => $vendorCode, 'msg' => $message]
            : ['msg' => $message];

        return self::createRequestException($httpStatus, $body, $message, $headers);
    }

    // =========================================================================
    // BYBIT EXCEPTIONS
    // =========================================================================

    /**
     * Bybit Case 1: IP not whitelisted by user.
     * HTTP 200 with retCode 10010.
     */
    public static function bybitIpNotWhitelisted(): RequestException
    {
        return self::bybit(200, 10010, 'Unmatched IP, please check your API key\'s bound IP addresses');
    }

    /**
     * Bybit Case 2: IP temporarily rate-limited.
     * HTTP 200 with retCode 10018.
     */
    public static function bybitIpRateLimited(): RequestException
    {
        return self::bybit(200, 10018, 'Exceeded the IP Rate Limit');
    }

    /**
     * Bybit Case 2 alternative: HTTP 403 rate limit.
     */
    public static function bybitIpRateLimitedHttp403(): RequestException
    {
        return self::bybit(403, null, 'Forbidden request: IP rate limit breached');
    }

    /**
     * Bybit Case 3: IP permanently banned.
     * HTTP 200 with retCode 10009.
     */
    public static function bybitIpBanned(): RequestException
    {
        return self::bybit(200, 10009, 'IP has been banned');
    }

    /**
     * Bybit Case 3 alternative: HTTP 429 (treated as permanent ban).
     */
    public static function bybitIpBannedHttp429(): RequestException
    {
        return self::bybit(429, null, 'System level frequency protection');
    }

    /**
     * Bybit Case 4: Account blocked - invalid API key.
     * HTTP 200 with retCode 10003.
     */
    public static function bybitAccountBlocked(): RequestException
    {
        return self::bybit(200, 10003, 'API key is invalid or domain mismatch');
    }

    /**
     * Bybit Case 4: Account blocked - invalid signature.
     * HTTP 200 with retCode 10004.
     */
    public static function bybitInvalidSignature(): RequestException
    {
        return self::bybit(200, 10004, 'Invalid signature');
    }

    /**
     * Bybit Case 4: Account blocked - permission denied.
     * HTTP 200 with retCode 10005.
     */
    public static function bybitPermissionDenied(): RequestException
    {
        return self::bybit(200, 10005, 'Permission denied, check API key permissions');
    }

    /**
     * Bybit Case 4: Account blocked - authentication failed.
     * HTTP 200 with retCode 10007.
     */
    public static function bybitAuthenticationFailed(): RequestException
    {
        return self::bybit(200, 10007, 'User authentication failed');
    }

    /**
     * Bybit Case 4 alternative: HTTP 401.
     */
    public static function bybitAccountBlockedHttp401(): RequestException
    {
        return self::bybit(401, null, 'Invalid request. Need correct key');
    }

    /**
     * Bybit: Rate limit - Too many visits (per-UID).
     * HTTP 200 with retCode 10006.
     */
    public static function bybitRateLimitedPerUid(): RequestException
    {
        return self::bybit(200, 10006, 'Too many visits');
    }

    /**
     * Bybit: Ignorable - Duplicate request ID.
     * HTTP 200 with retCode 20006.
     */
    public static function bybitIgnorableDuplicateRequest(): RequestException
    {
        return self::bybit(200, 20006, 'Duplicate request ID');
    }

    /**
     * Bybit: Ignorable - TP/SL already set.
     * HTTP 200 with retCode 34040.
     */
    public static function bybitIgnorableTpSlSet(): RequestException
    {
        return self::bybit(200, 34040, 'Already set this TP/SL value');
    }

    /**
     * Bybit: Ignorable - Position mode not modified.
     * HTTP 200 with retCode 110025.
     */
    public static function bybitIgnorablePositionMode(): RequestException
    {
        return self::bybit(200, 110025, 'Position mode not modified');
    }

    /**
     * Bybit: Ignorable - Leverage not modified.
     * HTTP 200 with retCode 110043.
     */
    public static function bybitIgnorableLeverage(): RequestException
    {
        return self::bybit(200, 110043, 'Set leverage not modified');
    }

    /**
     * Bybit: Retryable - Server timeout.
     * HTTP 200 with retCode 10000.
     */
    public static function bybitServerTimeout(): RequestException
    {
        return self::bybit(200, 10000, 'Server timeout');
    }

    /**
     * Bybit: Retryable - Service restarting.
     * HTTP 200 with retCode 10019.
     */
    public static function bybitServiceRestarting(): RequestException
    {
        return self::bybit(200, 10019, 'WS trade service restarting');
    }

    /**
     * Bybit: Retryable - Backend timeout.
     * HTTP 200 with retCode 170007.
     */
    public static function bybitBackendTimeout(): RequestException
    {
        return self::bybit(200, 170007, 'Timeout waiting for response from backend server');
    }

    /**
     * Bybit: Retryable - HTTP 503.
     */
    public static function bybitServiceUnavailable(): RequestException
    {
        return self::bybit(503, null, 'Service unavailable');
    }

    /**
     * Bybit: recvWindow mismatch.
     * HTTP 200 with retCode 10002.
     */
    public static function bybitRecvWindowMismatch(): RequestException
    {
        return self::bybit(200, 10002, 'Invalid request, please check your timestamp or recv_window param');
    }

    /**
     * Create a Bybit-style RequestException.
     * Bybit uses retCode/retMsg format.
     *
     * @param  int  $httpStatus  HTTP status code
     * @param  int|null  $retCode  Bybit return code
     * @param  string  $message  Error message
     * @param  array<string, string>  $headers  Response headers
     */
    public static function bybit(
        int $httpStatus,
        ?int $retCode,
        string $message,
        array $headers = []
    ): RequestException {
        $body = $retCode !== null
            ? ['retCode' => $retCode, 'retMsg' => $message]
            : ['retMsg' => $message];

        return self::createRequestException($httpStatus, $body, $message, $headers);
    }

    // =========================================================================
    // TAAPI EXCEPTIONS
    // =========================================================================

    /**
     * Taapi Case 4: Account blocked - unauthorized.
     * HTTP 401.
     */
    public static function taapiAccountBlocked(): RequestException
    {
        return self::taapi(401, 'Unauthorized: Invalid API key');
    }

    /**
     * Taapi Case 4: Account blocked - payment required.
     * HTTP 402.
     */
    public static function taapiPaymentRequired(): RequestException
    {
        return self::taapi(402, 'Payment required: Subscription expired');
    }

    /**
     * Taapi Case 4: Account blocked - forbidden.
     * HTTP 403.
     */
    public static function taapiForbidden(): RequestException
    {
        return self::taapi(403, 'Forbidden: Insufficient permissions');
    }

    /**
     * Taapi: Rate limited.
     * HTTP 429.
     */
    public static function taapiRateLimited(): RequestException
    {
        return self::taapi(429, 'Too many requests');
    }

    /**
     * Taapi: Rate limited with Retry-After.
     *
     * @param  int  $retryAfterSeconds  Seconds until rate limit lifts
     */
    public static function taapiRateLimitedWithRetryAfter(int $retryAfterSeconds = 3): RequestException
    {
        return self::taapi(429, 'Too many requests', [
            'Retry-After' => (string) $retryAfterSeconds,
        ]);
    }

    /**
     * Taapi: Ignorable - bad request.
     * HTTP 400.
     */
    public static function taapiIgnorableBadRequest(): RequestException
    {
        return self::taapi(400, 'Bad request: Invalid symbol');
    }

    /**
     * Taapi: Non-ignorable 400 - plan limit exceeded.
     * HTTP 400 with "constructs than your plan allows" in message.
     */
    public static function taapiPlanLimitExceeded(): RequestException
    {
        return self::taapi(400, 'You have requested more constructs than your plan allows');
    }

    /**
     * Taapi: Non-ignorable 400 - calculations limit exceeded.
     * HTTP 400 with "calculations than your plan allows" in message.
     */
    public static function taapiCalculationsLimitExceeded(): RequestException
    {
        return self::taapi(400, 'You have requested more calculations than your plan allows');
    }

    /**
     * Taapi: Retryable - server error.
     * HTTP 500.
     */
    public static function taapiServerError(): RequestException
    {
        return self::taapi(500, 'Internal server error');
    }

    /**
     * Taapi: Retryable - service unavailable.
     * HTTP 503.
     */
    public static function taapiServiceUnavailable(): RequestException
    {
        return self::taapi(503, 'Service unavailable');
    }

    /**
     * Create a Taapi-style RequestException.
     * Taapi uses simple msg format.
     *
     * @param  int  $httpStatus  HTTP status code
     * @param  string  $message  Error message
     * @param  array<string, string>  $headers  Response headers
     */
    public static function taapi(
        int $httpStatus,
        string $message,
        array $headers = []
    ): RequestException {
        $body = ['msg' => $message];

        return self::createRequestException($httpStatus, $body, $message, $headers);
    }

    // =========================================================================
    // COINMARKETCAP EXCEPTIONS
    // =========================================================================

    /**
     * CoinMarketCap Case 4: Account blocked - invalid API key.
     * HTTP 401 with error_code 1001.
     */
    public static function cmcAccountBlocked(): RequestException
    {
        return self::coinmarketcap(401, 1001, 'API key is invalid');
    }

    /**
     * CoinMarketCap Case 4: Account blocked - missing API key.
     * HTTP 401 with error_code 1002.
     */
    public static function cmcMissingApiKey(): RequestException
    {
        return self::coinmarketcap(401, 1002, 'API key is missing');
    }

    /**
     * CoinMarketCap Case 4: Account blocked - plan requires payment.
     * HTTP 402 with error_code 1003.
     */
    public static function cmcPaymentRequired(): RequestException
    {
        return self::coinmarketcap(402, 1003, 'Your plan requires payment');
    }

    /**
     * CoinMarketCap Case 4: Account blocked - payment expired.
     * HTTP 402 with error_code 1004.
     */
    public static function cmcPaymentExpired(): RequestException
    {
        return self::coinmarketcap(402, 1004, 'Payment has expired');
    }

    /**
     * CoinMarketCap Case 4: Account blocked - API key required.
     * HTTP 403 with error_code 1005.
     */
    public static function cmcApiKeyRequired(): RequestException
    {
        return self::coinmarketcap(403, 1005, 'API key required');
    }

    /**
     * CoinMarketCap Case 4: Account blocked - plan not authorized.
     * HTTP 403 with error_code 1006.
     */
    public static function cmcPlanNotAuthorized(): RequestException
    {
        return self::coinmarketcap(403, 1006, 'Your plan is not authorized');
    }

    /**
     * CoinMarketCap Case 4: Account blocked - key disabled.
     * HTTP 403 with error_code 1007.
     */
    public static function cmcKeyDisabled(): RequestException
    {
        return self::coinmarketcap(403, 1007, 'API key has been disabled');
    }

    /**
     * CoinMarketCap: Rate limited - minute limit.
     * HTTP 429 with error_code 1008.
     */
    public static function cmcRateLimitedMinute(): RequestException
    {
        return self::coinmarketcap(429, 1008, 'Minute rate limit reached');
    }

    /**
     * CoinMarketCap: Rate limited - daily limit.
     * HTTP 429 with error_code 1009.
     */
    public static function cmcRateLimitedDaily(): RequestException
    {
        return self::coinmarketcap(429, 1009, 'Daily rate limit reached');
    }

    /**
     * CoinMarketCap: Rate limited - monthly limit.
     * HTTP 429 with error_code 1010.
     */
    public static function cmcRateLimitedMonthly(): RequestException
    {
        return self::coinmarketcap(429, 1010, 'Monthly rate limit reached');
    }

    /**
     * CoinMarketCap: Rate limited - IP rate limit.
     * HTTP 429 with error_code 1011.
     */
    public static function cmcRateLimitedIp(): RequestException
    {
        return self::coinmarketcap(429, 1011, 'IP rate limit reached');
    }

    /**
     * CoinMarketCap: Rate limited with Retry-After.
     *
     * @param  int  $retryAfterSeconds  Seconds until rate limit lifts
     */
    public static function cmcRateLimitedWithRetryAfter(int $retryAfterSeconds = 60): RequestException
    {
        return self::coinmarketcap(429, 1008, 'Rate limit reached', [
            'Retry-After' => (string) $retryAfterSeconds,
        ]);
    }

    /**
     * CoinMarketCap: Rate limited with Date header for boundary calculation.
     *
     * @param  string  $serverDate  RFC 2822 date string
     * @param  int  $errorCode  Error code (1008=minute, 1009=daily, 1010=monthly, 1011=IP)
     */
    public static function cmcRateLimitedWithServerDate(string $serverDate, int $errorCode = 1008): RequestException
    {
        return self::coinmarketcap(429, $errorCode, 'Rate limit reached', [
            'Date' => $serverDate,
        ]);
    }

    /**
     * CoinMarketCap: Ignorable - bad request.
     * HTTP 400.
     */
    public static function cmcIgnorableBadRequest(): RequestException
    {
        return self::coinmarketcap(400, null, 'Bad request: Invalid symbol');
    }

    /**
     * CoinMarketCap: Retryable - server error.
     * HTTP 500.
     */
    public static function cmcServerError(): RequestException
    {
        return self::coinmarketcap(500, null, 'Internal server error');
    }

    /**
     * CoinMarketCap: Retryable - service unavailable.
     * HTTP 503.
     */
    public static function cmcServiceUnavailable(): RequestException
    {
        return self::coinmarketcap(503, null, 'Service unavailable');
    }

    /**
     * Create a CoinMarketCap-style RequestException.
     * CMC uses nested status object format.
     *
     * @param  int  $httpStatus  HTTP status code
     * @param  int|null  $errorCode  CMC error code
     * @param  string  $message  Error message
     * @param  array<string, string>  $headers  Response headers
     */
    public static function coinmarketcap(
        int $httpStatus,
        ?int $errorCode,
        string $message,
        array $headers = []
    ): RequestException {
        $body = [
            'status' => $errorCode !== null
                ? ['error_code' => $errorCode, 'error_message' => $message]
                : ['error_message' => $message],
        ];

        return self::createRequestException($httpStatus, $body, $message, $headers);
    }

    // =========================================================================
    // ALTERNATIVEME EXCEPTIONS
    // =========================================================================

    /**
     * AlternativeMe Case 4: Account blocked - unauthorized.
     * HTTP 401.
     */
    public static function alternativemeAccountBlocked(): RequestException
    {
        return self::alternativeme(401, 'Unauthorized');
    }

    /**
     * AlternativeMe Case 4: Account blocked - payment required.
     * HTTP 402.
     */
    public static function alternativemePaymentRequired(): RequestException
    {
        return self::alternativeme(402, 'Payment required');
    }

    /**
     * AlternativeMe Case 4: Account blocked - forbidden.
     * HTTP 403.
     */
    public static function alternativemeForbidden(): RequestException
    {
        return self::alternativeme(403, 'Forbidden');
    }

    /**
     * AlternativeMe: Rate limited.
     * HTTP 429.
     */
    public static function alternativemeRateLimited(): RequestException
    {
        return self::alternativeme(429, 'Too many requests');
    }

    /**
     * AlternativeMe: Ignorable - bad request.
     * HTTP 400.
     */
    public static function alternativemeIgnorableBadRequest(): RequestException
    {
        return self::alternativeme(400, 'Bad request');
    }

    /**
     * AlternativeMe: Retryable - request timeout.
     * HTTP 408.
     */
    public static function alternativemeRequestTimeout(): RequestException
    {
        return self::alternativeme(408, 'Request timeout');
    }

    /**
     * AlternativeMe: Retryable - server error.
     * HTTP 500.
     */
    public static function alternativemeServerError(): RequestException
    {
        return self::alternativeme(500, 'Internal server error');
    }

    /**
     * AlternativeMe: Retryable - bad gateway.
     * HTTP 502.
     */
    public static function alternativemeBadGateway(): RequestException
    {
        return self::alternativeme(502, 'Bad gateway');
    }

    /**
     * AlternativeMe: Retryable - service unavailable.
     * HTTP 503.
     */
    public static function alternativemeServiceUnavailable(): RequestException
    {
        return self::alternativeme(503, 'Service unavailable');
    }

    /**
     * AlternativeMe: Retryable - gateway timeout.
     * HTTP 504.
     */
    public static function alternativemeGatewayTimeout(): RequestException
    {
        return self::alternativeme(504, 'Gateway timeout');
    }

    /**
     * Create an AlternativeMe-style RequestException.
     * AlternativeMe uses simple msg format.
     *
     * @param  int  $httpStatus  HTTP status code
     * @param  string  $message  Error message
     * @param  array<string, string>  $headers  Response headers
     */
    public static function alternativeme(
        int $httpStatus,
        string $message,
        array $headers = []
    ): RequestException {
        $body = ['msg' => $message];

        return self::createRequestException($httpStatus, $body, $message, $headers);
    }

    // =========================================================================
    // KUCOIN EXCEPTIONS
    // =========================================================================

    /**
     * KuCoin Case 2: IP temporarily rate-limited.
     * HTTP 429 - Too many requests.
     */
    public static function kucoinIpRateLimited(): RequestException
    {
        return self::kucoin(429, null, 'Too many requests');
    }

    /**
     * KuCoin Case 2: Rate limited with KuCoin-specific code 429000.
     */
    public static function kucoinRateLimited429000(): RequestException
    {
        return self::kucoin(200, '429000', 'Too many requests');
    }

    /**
     * KuCoin Case 2: Rate limited with Retry-After header.
     *
     * @param  int  $retryAfterSeconds  Seconds until rate limit lifts
     */
    public static function kucoinRateLimitedWithRetryAfter(int $retryAfterSeconds = 5): RequestException
    {
        return self::kucoin(429, null, 'Too many requests', [
            'Retry-After' => (string) $retryAfterSeconds,
        ]);
    }

    /**
     * KuCoin Case 4: Account blocked - authentication failed.
     * HTTP 401 - Authentication failed.
     */
    public static function kucoinAccountBlocked(): RequestException
    {
        return self::kucoin(401, null, 'Authentication failed');
    }

    /**
     * KuCoin Case 4: Account blocked - invalid API key.
     * KuCoin code 400100.
     */
    public static function kucoinApiKeyInvalid(): RequestException
    {
        return self::kucoin(200, '400100', 'Invalid API-Key');
    }

    /**
     * KuCoin Case 4: User is frozen.
     * KuCoin code 411100.
     */
    public static function kucoinUserFrozen(): RequestException
    {
        return self::kucoin(200, '411100', 'User is frozen');
    }

    /**
     * KuCoin: Forbidden - may be IP ban or permission issue.
     * HTTP 403.
     */
    public static function kucoinForbidden(): RequestException
    {
        return self::kucoin(403, null, 'Forbidden');
    }

    /**
     * KuCoin: Retryable - internal error.
     * KuCoin code 300000.
     */
    public static function kucoinInternalError(): RequestException
    {
        return self::kucoin(200, '300000', 'Internal error');
    }

    /**
     * KuCoin: Retryable - request timeout.
     * HTTP 408.
     */
    public static function kucoinRequestTimeout(): RequestException
    {
        return self::kucoin(408, null, 'Request timeout');
    }

    /**
     * KuCoin: Retryable - server error.
     * HTTP 500.
     */
    public static function kucoinServerError(): RequestException
    {
        return self::kucoin(500, null, 'Internal server error');
    }

    /**
     * KuCoin: Retryable - bad gateway.
     * HTTP 502.
     */
    public static function kucoinBadGateway(): RequestException
    {
        return self::kucoin(502, null, 'Bad gateway');
    }

    /**
     * KuCoin: Retryable - service unavailable.
     * HTTP 503.
     */
    public static function kucoinServiceUnavailable(): RequestException
    {
        return self::kucoin(503, null, 'Service unavailable');
    }

    /**
     * KuCoin: Retryable - gateway timeout.
     * HTTP 504.
     */
    public static function kucoinGatewayTimeout(): RequestException
    {
        return self::kucoin(504, null, 'Gateway timeout');
    }

    /**
     * KuCoin: Order not exist.
     * KuCoin code 200004.
     */
    public static function kucoinOrderNotExist(): RequestException
    {
        return self::kucoin(200, '200004', 'Order not exist');
    }

    /**
     * KuCoin: Insufficient balance.
     * KuCoin code 200003.
     */
    public static function kucoinInsufficientBalance(): RequestException
    {
        return self::kucoin(200, '200003', 'Insufficient balance');
    }

    /**
     * KuCoin: Invalid parameter.
     * KuCoin code 400001.
     */
    public static function kucoinInvalidParameter(): RequestException
    {
        return self::kucoin(200, '400001', 'Invalid parameter');
    }

    /**
     * Create a KuCoin-style RequestException.
     * KuCoin uses {"code": "200004", "msg": "Order not exist"} format.
     *
     * @param  int  $httpStatus  HTTP status code
     * @param  string|null  $code  KuCoin error code (e.g., '200004', '429000')
     * @param  string  $message  Error message
     * @param  array<string, string>  $headers  Response headers
     */
    public static function kucoin(
        int $httpStatus,
        ?string $code,
        string $message,
        array $headers = []
    ): RequestException {
        $body = $code !== null
            ? ['code' => $code, 'msg' => $message]
            : ['msg' => $message];

        return self::createRequestException($httpStatus, $body, $message, $headers);
    }

    // =========================================================================
    // BITGET EXCEPTIONS
    // =========================================================================

    /**
     * BitGet Case 2: IP temporarily rate-limited.
     * HTTP 429 - Too many requests.
     */
    public static function bitgetIpRateLimited(): RequestException
    {
        return self::bitget(429, null, 'Too many requests');
    }

    /**
     * BitGet Case 2: Rate limited with Retry-After header.
     *
     * @param  int  $retryAfterSeconds  Seconds until rate limit lifts
     */
    public static function bitgetRateLimitedWithRetryAfter(int $retryAfterSeconds = 5): RequestException
    {
        return self::bitget(429, null, 'Too many requests', [
            'Retry-After' => (string) $retryAfterSeconds,
        ]);
    }

    /**
     * BitGet Case 4: Account blocked - authentication failed.
     * HTTP 401 - Authentication failed.
     */
    public static function bitgetAccountBlocked(): RequestException
    {
        return self::bitget(401, null, 'Authentication failed');
    }

    /**
     * BitGet Case 4: Account blocked - invalid API key.
     * BitGet code 40014.
     */
    public static function bitgetApiKeyInvalid(): RequestException
    {
        return self::bitget(200, '40014', 'Invalid API-Key');
    }

    /**
     * BitGet Case 4: Account blocked - not a trader.
     * BitGet code 40017.
     */
    public static function bitgetNotATrader(): RequestException
    {
        return self::bitget(200, '40017', 'Parameter verification failed or not a trader');
    }

    /**
     * BitGet Case 4: Account blocked - invalid passphrase.
     * BitGet code 40018.
     */
    public static function bitgetInvalidPassphrase(): RequestException
    {
        return self::bitget(200, '40018', 'Invalid passphrase');
    }

    /**
     * BitGet: Forbidden - may be IP ban or permission issue.
     * HTTP 403.
     */
    public static function bitgetForbidden(): RequestException
    {
        return self::bitget(403, null, 'Forbidden');
    }

    /**
     * BitGet: Retryable - system maintenance.
     * BitGet code 45001.
     */
    public static function bitgetSystemMaintenance(): RequestException
    {
        return self::bitget(200, '45001', 'System maintenance');
    }

    /**
     * BitGet: Retryable - system release error.
     * BitGet code 40725.
     */
    public static function bitgetSystemReleaseError(): RequestException
    {
        return self::bitget(200, '40725', 'System release error');
    }

    /**
     * BitGet: Retryable - system release error (alternative).
     * BitGet code 40015.
     */
    public static function bitgetSystemReleaseError40015(): RequestException
    {
        return self::bitget(200, '40015', 'System release error');
    }

    /**
     * BitGet: Retryable - request timeout.
     * HTTP 408.
     */
    public static function bitgetRequestTimeout(): RequestException
    {
        return self::bitget(408, null, 'Request timeout');
    }

    /**
     * BitGet: Retryable - server error.
     * HTTP 500.
     */
    public static function bitgetServerError(): RequestException
    {
        return self::bitget(500, null, 'Internal server error');
    }

    /**
     * BitGet: Retryable - bad gateway.
     * HTTP 502.
     */
    public static function bitgetBadGateway(): RequestException
    {
        return self::bitget(502, null, 'Bad gateway');
    }

    /**
     * BitGet: Retryable - service unavailable.
     * HTTP 503.
     */
    public static function bitgetServiceUnavailable(): RequestException
    {
        return self::bitget(503, null, 'Service unavailable');
    }

    /**
     * BitGet: Retryable - gateway timeout.
     * HTTP 504.
     */
    public static function bitgetGatewayTimeout(): RequestException
    {
        return self::bitget(504, null, 'Gateway timeout');
    }

    /**
     * BitGet: Parameter verification exception.
     * BitGet code 40808.
     */
    public static function bitgetParameterVerificationException(): RequestException
    {
        return self::bitget(200, '40808', 'Parameter verification exception');
    }

    /**
     * Create a BitGet-style RequestException.
     * BitGet uses {"code": "40808", "msg": "Parameter verification exception", "requestTime": ...} format.
     *
     * @param  int  $httpStatus  HTTP status code
     * @param  string|null  $code  BitGet error code (e.g., '40014', '45001')
     * @param  string  $message  Error message
     * @param  array<string, string>  $headers  Response headers
     */
    public static function bitget(
        int $httpStatus,
        ?string $code,
        string $message,
        array $headers = []
    ): RequestException {
        $body = $code !== null
            ? ['code' => $code, 'msg' => $message, 'requestTime' => time() * 1000]
            : ['msg' => $message];

        return self::createRequestException($httpStatus, $body, $message, $headers);
    }

    // =========================================================================
    // GENERIC EXCEPTIONS
    // =========================================================================

    /**
     * Create a ConnectException for network failures.
     *
     * @param  string  $message  Error message
     */
    public static function connectException(string $message = 'Connection timeout'): ConnectException
    {
        return new ConnectException(
            $message,
            new Request('GET', '/test')
        );
    }

    /**
     * Create a generic RequestException with custom parameters.
     *
     * @param  int  $httpStatus  HTTP status code
     * @param  array<string, mixed>  $body  Response body
     * @param  string  $message  Error message
     * @param  array<string, string>  $headers  Response headers
     */
    public static function createRequestException(
        int $httpStatus,
        array $body,
        string $message,
        array $headers = []
    ): RequestException {
        $response = new Response(
            $httpStatus,
            $headers,
            json_encode($body)
        );

        return new RequestException(
            $message,
            new Request('GET', '/test'),
            $response
        );
    }
}
