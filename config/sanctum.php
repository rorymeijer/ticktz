<?php

declare(strict_types=1);
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful domains
    |--------------------------------------------------------------------------
    |
    | Deliberately empty. The Ticktz UI is served by Laravel itself and uses
    | the ordinary session guard with CSRF protection; Sanctum is here only for
    | the public REST API, which is token-only. Leaving this list empty means
    | a cookie can never authenticate an API request, so the API has no CSRF
    | surface at all.
    |
    */

    'stateful' => [],

    /*
    |--------------------------------------------------------------------------
    | Guards consulted before falling back to a token
    |--------------------------------------------------------------------------
    |
    | Also empty, for the same reason: `auth:sanctum` on an API route must mean
    | "present a token", never "you happen to have a session cookie".
    |
    */

    'guard' => [],

    /*
    |--------------------------------------------------------------------------
    | Expiration
    |--------------------------------------------------------------------------
    |
    | Instance-wide ceiling in minutes for tokens that were issued without
    | their own expiry. Null means non-expiring tokens stay valid until they
    | are revoked; set TICKTZ_API_TOKEN_MINUTES on an instance where every
    | integration credential should age out.
    |
    */

    'expiration' => env('TICKTZ_API_TOKEN_MINUTES') !== null
        ? (int) env('TICKTZ_API_TOKEN_MINUTES')
        : null,

    /*
    |--------------------------------------------------------------------------
    | Token prefix
    |--------------------------------------------------------------------------
    |
    | Prefixing the plaintext makes a leaked token recognisable — secret
    | scanners key off exactly this, and so does a human staring at a pasted
    | config file.
    |
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', 'ticktz_'),

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    */

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
