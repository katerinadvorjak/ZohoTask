<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ZohoToken;
use App\Services\ZohoCrmService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ZohoLeadController extends Controller
{
    private function buildOauthUrl(?string $state = null): string
    {
        $accountsDomain = rtrim(config('services.zoho.accounts_domain'), '/');
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => config('services.zoho.client_id'),
            'scope' => config('services.zoho.scope', 'ZohoCRM.modules.ALL'),
            'redirect_uri' => config('services.zoho.redirect_uri'),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state ?? bin2hex(random_bytes(16)),
        ]);

        return "{$accountsDomain}/oauth/v2/auth?{$query}";
    }

    public function connectRedirect(): RedirectResponse
    {
        return redirect()->away($this->buildOauthUrl());
    }

    public function callbackRedirect(Request $request): RedirectResponse
    {
        $code = (string) $request->query('code', '');
        if ($code === '') {
            return redirect('http://katya-dev.duckdns.org:10002/?oauth=missing_code');
        }

        $accountsDomain = rtrim(config('services.zoho.accounts_domain'), '/');
        $response = Http::asForm()->post("{$accountsDomain}/oauth/v2/token", [
            'grant_type' => 'authorization_code',
            'client_id' => config('services.zoho.client_id'),
            'client_secret' => config('services.zoho.client_secret'),
            'redirect_uri' => config('services.zoho.redirect_uri'),
            'code' => $code,
        ]);

        if (! $response->successful()) {
            return redirect('http://katya-dev.duckdns.org:10002/?oauth=failed');
        }

        $json = $response->json();
        $existing = ZohoToken::query()->firstWhere('key', 'default');
        $accessToken = $json['access_token'] ?? '';
        $refreshToken = $json['refresh_token'] ?? ($existing?->refresh_token ?? '');

        if ($accessToken === '' || $refreshToken === '') {
            return redirect('http://katya-dev.duckdns.org:10002/?oauth=missing_token');
        }

        ZohoToken::query()->updateOrCreate(
            ['key' => 'default'],
            [
                'refresh_token' => $refreshToken,
                'access_token' => $accessToken,
                'api_domain' => $json['api_domain'] ?? config('services.zoho.api_domain'),
                'expires_at' => Carbon::now()->addSeconds((int) ($json['expires_in_sec'] ?? 3600)),
            ]
        );

        return redirect('http://katya-dev.duckdns.org:10002/?oauth=ok');
    }

    public function status(Request $request): JsonResponse
    {
        $token = ZohoToken::query()->firstWhere('key', 'default');

        return response()->json([
            'ok' => true,
            'connected' => (bool) ($token && !empty($token->refresh_token)),
            'expires_at' => optional($token?->expires_at)->toIso8601String(),
        ]);
    }

    public function authUrl(Request $request): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'url' => $this->buildOauthUrl(),
            'connected' => ZohoToken::query()->where('key', 'default')->whereNotNull('refresh_token')->exists(),
        ]);
    }

    public function exchangeCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string'],
            'state' => ['nullable', 'string'],
            'redirect_uri' => ['nullable', 'string'],
        ]);

        $accountsDomain = rtrim(config('services.zoho.accounts_domain'), '/');
        $response = Http::asForm()->post("{$accountsDomain}/oauth/v2/token", [
            'grant_type' => 'authorization_code',
            'client_id' => config('services.zoho.client_id'),
            'client_secret' => config('services.zoho.client_secret'),
            'redirect_uri' => $data['redirect_uri'] ?? config('services.zoho.redirect_uri'),
            'code' => $data['code'],
        ]);

        if (! $response->successful()) {
            return response()->json([
                'ok' => false,
                'message' => 'Failed to exchange code for tokens.',
                'details' => $response->json() ?: $response->body(),
            ], 422);
        }

        $json = $response->json();

        $existing = ZohoToken::query()->firstWhere('key', 'default');
        $accessToken = $json['access_token'] ?? '';
        $refreshToken = $json['refresh_token'] ?? ($existing?->refresh_token ?? '');

        if ($accessToken === '' || $refreshToken === '') {
            return response()->json([
                'ok' => false,
                'message' => 'Zoho returned incomplete token set. Use consent prompt and ensure offline access.',
                'details' => $json,
            ], 422);
        }

        $token = ZohoToken::query()->updateOrCreate(
            ['key' => 'default'],
            [
                'refresh_token' => $refreshToken,
                'access_token' => $accessToken,
                'api_domain' => $json['api_domain'] ?? config('services.zoho.api_domain'),
                'expires_at' => Carbon::now()->addSeconds((int) ($json['expires_in_sec'] ?? 3600)),
            ]
        );

        return response()->json([
            'ok' => true,
            'message' => 'Zoho connected successfully.',
            'data' => [
                'id' => $token->id,
                'expires_at' => $token->expires_at,
            ],
        ]);
    }

    public function store(Request $request, ZohoCrmService $zoho): JsonResponse
    {
        $data = $request->validate([
            'deal_name' => ['required', 'string', 'max:255'],
            'deal_stage' => ['required', 'string', 'max:255'],
            'account_name' => ['required', 'string', 'max:255'],
            'account_website' => ['nullable', 'string', 'max:255'],
            'account_phone' => ['nullable', 'string', 'max:50'],
        ]);

        try {
            $result = $zoho->createAccountAndDeal($data);

            return response()->json([
                'ok' => true,
                'message' => 'Account and Deal created successfully.',
                'data' => $result,
            ]);
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
            if (str_contains($message, 'Zoho token not configured') || str_contains($message, 'Zoho refresh token is missing')) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Zoho integration is not connected yet. Admin must run /zoho/connect once.',
                ], 422);
            }

            return response()->json([
                'ok' => false,
                'message' => $message,
            ], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'ok' => false,
                'message' => 'Unexpected server error.',
            ], 500);
        }
    }

    public function upsertToken(Request $request): JsonResponse
    {
        $data = $request->validate([
            'refresh_token' => ['required', 'string'],
            'access_token' => ['nullable', 'string'],
            'api_domain' => ['nullable', 'string'],
            'expires_in_sec' => ['nullable', 'integer', 'min:60'],
        ]);

        $token = ZohoToken::query()->updateOrCreate(
            ['key' => 'default'],
            [
                'refresh_token' => $data['refresh_token'],
                'access_token' => $data['access_token'] ?? '',
                'api_domain' => $data['api_domain'] ?? config('services.zoho.api_domain'),
                'expires_at' => isset($data['expires_in_sec'])
                    ? Carbon::now()->addSeconds((int) $data['expires_in_sec'])
                    : Carbon::now()->subMinute(),
            ]
        );

        return response()->json([
            'ok' => true,
            'message' => 'Token saved.',
            'data' => [
                'id' => $token->id,
                'expires_at' => $token->expires_at,
            ],
        ]);
    }
}
