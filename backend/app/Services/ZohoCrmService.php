<?php

namespace App\Services;

use App\Models\ZohoToken;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ZohoCrmService
{
    private function normalizeWebsite(?string $website): ?string
    {
        if (! $website) {
            return null;
        }

        $website = trim($website);
        if (in_array($website, ['http://', 'https://'], true)) {
            return null;
        }

        if (! preg_match('#^https?://#i', $website)) {
            $website = 'https://'.$website;
        }

        if (! filter_var($website, FILTER_VALIDATE_URL)) {
            return null;
        }

        $host = parse_url($website, PHP_URL_HOST);
        if (! $host || ! str_contains((string) $host, '.')) {
            return null;
        }

        return $website;
    }

    public function createAccountAndDeal(array $payload): array
    {
        $tokenModel = ZohoToken::query()->firstWhere('key', 'default');
        if (! $tokenModel) {
            throw new RuntimeException('Zoho token not configured.');
        }

        $token = $this->getValidAccessToken();
        $apiDomain = rtrim($tokenModel->api_domain ?: config('services.zoho.api_domain', 'https://www.zohoapis.com'), '/');

        $normalizedWebsite = $this->normalizeWebsite($payload['account_website'] ?? null);

        $accountData = [
            'Account_Name' => $payload['account_name'],
            'Phone' => $payload['account_phone'] ?? null,
        ];
        if ($normalizedWebsite !== null) {
            $accountData['Website'] = $normalizedWebsite;
        }

        $accountResp = Http::withToken($token)
            ->post("{$apiDomain}/crm/v2/Accounts", [
                'data' => [$accountData],
            ]);

        if (! $accountResp->successful()) {
            $errCode = Arr::get($accountResp->json(), 'code') ?? Arr::get($accountResp->json(), 'data.0.code');
            if ($errCode === 'INVALID_TOKEN') {
                $tokenModel = $this->refreshToken($tokenModel);
                $token = $tokenModel->access_token;
                $apiDomain = rtrim($tokenModel->api_domain ?: $apiDomain, '/');
                $retryAccountData = [
                    'Account_Name' => $payload['account_name'],
                    'Phone' => $payload['account_phone'] ?? null,
                ];
                if ($normalizedWebsite !== null) {
                    $retryAccountData['Website'] = $normalizedWebsite;
                }

                $accountResp = Http::withToken($token)
                    ->post("{$apiDomain}/crm/v2/Accounts", [
                        'data' => [$retryAccountData],
                    ]);
            }
        }

        if (! $accountResp->successful()) {
            throw new RuntimeException('Failed to create account: '.$accountResp->body());
        }

        $accountJson = $accountResp->json();
        $accountId =
            Arr::get($accountJson, 'data.0.details.id')
            ?? Arr::get($accountJson, 'data.0.details.Id')
            ?? Arr::get($accountJson, 'data.0.id')
            ?? Arr::get($accountJson, 'details.id');

        if (! $accountId) {
            // Fallback: find account by exact name (helps when Zoho returns non-standard create payload)
            $searchResp = Http::withToken($token)
                ->get("{$apiDomain}/crm/v2/Accounts/search", [
                    'criteria' => "(Account_Name:equals:{$payload['account_name']})",
                ]);

            if ($searchResp->successful()) {
                $accountId = Arr::get($searchResp->json(), 'data.0.id');
            }
        }

        if (! $accountId) {
            throw new RuntimeException('Account created but ID missing. Raw response: '.json_encode($accountJson));
        }

        $dealResp = Http::withToken($token)
            ->post("{$apiDomain}/crm/v2/Deals", [
                'data' => [[
                    'Deal_Name' => $payload['deal_name'],
                    'Stage' => $payload['deal_stage'],
                    'Account_Name' => [
                        'id' => $accountId,
                    ],
                ]],
            ]);

        if (! $dealResp->successful()) {
            $errCode = Arr::get($dealResp->json(), 'code') ?? Arr::get($dealResp->json(), 'data.0.code');
            if ($errCode === 'INVALID_TOKEN') {
                $tokenModel = $this->refreshToken($tokenModel);
                $token = $tokenModel->access_token;
                $apiDomain = rtrim($tokenModel->api_domain ?: $apiDomain, '/');
                $dealResp = Http::withToken($token)
                    ->post("{$apiDomain}/crm/v2/Deals", [
                        'data' => [[
                            'Deal_Name' => $payload['deal_name'],
                            'Stage' => $payload['deal_stage'],
                            'Account_Name' => [
                                'id' => $accountId,
                            ],
                        ]],
                    ]);
            }
        }

        if (! $dealResp->successful()) {
            throw new RuntimeException('Failed to create deal: '.$dealResp->body());
        }

        $dealId = Arr::get($dealResp->json(), 'data.0.details.id');

        return [
            'account_id' => $accountId,
            'deal_id' => $dealId,
            'account_response' => $accountResp->json(),
            'deal_response' => $dealResp->json(),
        ];
    }

    public function getValidAccessToken(): string
    {
        $token = ZohoToken::query()->firstWhere('key', 'default');
        if (! $token) {
            throw new RuntimeException('Zoho token not configured.');
        }

        if (empty($token->refresh_token)) {
            throw new RuntimeException('Zoho refresh token is missing. Complete OAuth authorization once.');
        }

        $mustRefresh =
            empty($token->access_token)
            || ! $token->expires_at
            || Carbon::now()->addMinutes(2)->gte($token->expires_at);

        if ($mustRefresh) {
            $token = $this->refreshToken($token);
        }

        if (empty($token->access_token)) {
            throw new RuntimeException('Zoho access token is empty after refresh.');
        }

        return $token->access_token;
    }

    public function refreshToken(ZohoToken $token): ZohoToken
    {
        $accountsDomain = rtrim(config('services.zoho.accounts_domain', 'https://accounts.zoho.com'), '/');
        $response = Http::asForm()->post("{$accountsDomain}/oauth/v2/token", [
            'refresh_token' => $token->refresh_token,
            'client_id' => config('services.zoho.client_id'),
            'client_secret' => config('services.zoho.client_secret'),
            'grant_type' => 'refresh_token',
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Unable to refresh Zoho token: '.$response->body());
        }

        $json = $response->json();
        $token->access_token = $json['access_token'] ?? $token->access_token;
        $token->api_domain = $json['api_domain'] ?? $token->api_domain;

        $expiresIn = (int) ($json['expires_in_sec'] ?? $json['expires_in'] ?? 3600);
        $token->expires_at = Carbon::now()->addSeconds($expiresIn);
        $token->save();

        return $token;
    }
}
