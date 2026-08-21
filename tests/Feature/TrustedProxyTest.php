<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware('web')->get('/testing/trusted-proxy', function (Request $request) {
        return response()->json([
            'is_secure' => $request->isSecure(),
            'scheme' => $request->getScheme(),
            'generated_url' => url('/dashboard'),
        ]);
    });
});

test('https request behind trusted loopback proxy is detected as secure', function () {
    $response = $this->withServerVariables([
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_FORWARDED_HOST' => 'example.test',
    ])->getJson('http://localhost/testing/trusted-proxy');

    $response->assertOk()
        ->assertJson([
            'is_secure' => true,
            'scheme' => 'https',
        ]);

    expect($response->json('generated_url'))->toStartWith('https://');
});

test('plain http request without proxy headers is not secure', function () {
    $response = $this->withServerVariables([
        'REMOTE_ADDR' => '127.0.0.1',
    ])->getJson('http://localhost/testing/trusted-proxy');

    $response->assertOk()
        ->assertJson([
            'is_secure' => false,
            'scheme' => 'http',
        ]);

    expect($response->json('generated_url'))->toStartWith('http://');
});

test('https forwarded proto from untrusted remote address is ignored', function () {
    $response = $this->withServerVariables([
        'REMOTE_ADDR' => '10.0.0.50',
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_FORWARDED_HOST' => 'example.test',
    ])->getJson('http://localhost/testing/trusted-proxy');

    $response->assertOk()
        ->assertJson([
            'is_secure' => false,
            'scheme' => 'http',
        ]);
});

test('generated route url follows detected request scheme', function () {
    Route::middleware('web')->get('/testing/trusted-proxy-route', function () {
        return response()->json([
            'route_url' => route('home'),
        ]);
    });

    $response = $this->withServerVariables([
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_FORWARDED_HOST' => 'example.test',
    ])->getJson('http://localhost/testing/trusted-proxy-route');

    $response->assertOk();

    expect($response->json('route_url'))->toStartWith('https://');
});
