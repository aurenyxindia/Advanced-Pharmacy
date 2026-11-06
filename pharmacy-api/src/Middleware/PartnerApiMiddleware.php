<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Support\Env;
use Closure;

/**
 * Authenticates external partners (e.g. EyeFlow EMR) via API key.
 * Maps each key to exactly one pharmacy client_id (never trusts caller-supplied client_id).
 *
 * Auth (any one):
 *   X-Api-Key: <key>
 *   Authorization: Bearer <key>
 *   ?api_key=<key>   (for opening JSON directly in a browser)
 */
final class PartnerApiMiddleware implements Middleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $this->extractKey($request);
        if ($apiKey === null || $apiKey === '') {
            throw HttpException::unauthorized(
                'Partner API key missing. Send X-Api-Key, Authorization: Bearer <key>, or ?api_key=<key>.'
            );
        }

        $partner = $this->resolvePartner($apiKey);
        if ($partner === null) {
            throw HttpException::unauthorized('Invalid partner API key.');
        }

        $request->setAttribute('partner', $partner);

        return $next($request);
    }

    private function extractKey(Request $request): ?string
    {
        $header = trim((string) $request->header('x-api-key', ''));
        if ($header !== '') {
            return $header;
        }

        $bearer = $request->bearerToken();
        if ($bearer !== null && $bearer !== '') {
            return $bearer;
        }

        // Browser-friendly: paste URL with ?api_key=...
        $queryKey = trim((string) $request->query('api_key', $request->query('key', '')));
        return $queryKey !== '' ? $queryKey : null;
    }

    /**
     * @return array{client_id:int,partner:string,branch_ids:list<int>}|null
     */
    private function resolvePartner(string $apiKey): ?array
    {
        $json = trim((string) Env::get('PARTNER_API_KEYS', ''));
        if ($json !== '') {
            $map = json_decode($json, true);
            if (is_array($map)) {
                foreach ($map as $configuredKey => $cfg) {
                    if (!is_string($configuredKey) || !is_array($cfg)) {
                        continue;
                    }
                    if (!hash_equals($configuredKey, $apiKey)) {
                        continue;
                    }
                    $clientId = (int) ($cfg['client_id'] ?? 0);
                    if ($clientId <= 0) {
                        return null;
                    }
                    $branchIds = [];
                    if (!empty($cfg['branch_ids']) && is_array($cfg['branch_ids'])) {
                        foreach ($cfg['branch_ids'] as $bid) {
                            $bid = (int) $bid;
                            if ($bid > 0) {
                                $branchIds[] = $bid;
                            }
                        }
                    }

                    return [
                        'client_id'  => $clientId,
                        'partner'    => (string) ($cfg['partner'] ?? $cfg['name'] ?? 'partner'),
                        'branch_ids' => $branchIds,
                    ];
                }
            }
        }

        // Simple single-key fallback for one EyeFlow ↔ one pharmacy client.
        $single = trim((string) Env::get('PARTNER_API_KEY', ''));
        $clientId = (int) Env::get('PARTNER_CLIENT_ID', 0);
        if ($single !== '' && $clientId > 0 && hash_equals($single, $apiKey)) {
            $branchIds = [];
            $rawBranches = trim((string) Env::get('PARTNER_BRANCH_IDS', ''));
            if ($rawBranches !== '') {
                foreach (explode(',', $rawBranches) as $part) {
                    $bid = (int) trim($part);
                    if ($bid > 0) {
                        $branchIds[] = $bid;
                    }
                }
            }

            return [
                'client_id'  => $clientId,
                'partner'    => (string) Env::get('PARTNER_NAME', 'EyeFlow'),
                'branch_ids' => $branchIds,
            ];
        }

        return null;
    }
}
