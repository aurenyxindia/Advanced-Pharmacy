<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use Closure;

/**
 * Per-IP fixed-window limits for the unauthenticated /api/public routes (customer app + web shop).
 * File counters under storage/ratelimit — no Redis/APCu on shared hosting.
 * Limits are generous because mobile carriers put many customers behind one IP (CGNAT).
 */
final class PublicRateLimitMiddleware implements Middleware
{
    /** [bucket, max requests, window seconds] — first match wins. */
    private const RULES = [
        ['POST', '#/public/(orders|agt/orders)$#', 'place', 30, 600],
        ['POST', '#/public/agt-users/(login|register)$#', 'auth', 20, 600],
        ['POST', '#/public/retail/orders$#', 'place', 30, 600],
        ['*', '#/public/(orders/track|agt/live|retail/orders)#', 'track', 240, 60],
        ['*', '#/public/#', 'general', 400, 60],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $method = strtoupper($request->method());
        $path = $request->path();
        foreach (self::RULES as [$ruleMethod, $pattern, $bucket, $max, $window]) {
            if (($ruleMethod === '*' || $ruleMethod === $method) && preg_match($pattern, $path)) {
                $this->hit($bucket, (int) $max, (int) $window);
                break;
            }
        }
        return $next($request);
    }

    private function hit(string $bucket, int $max, int $window): void
    {
        // REMOTE_ADDR, not X-Forwarded-For: the forwarded header is client-controlled.
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $slot = intdiv(time(), $window);
        $dir = dirname(__DIR__, 2) . '/storage/ratelimit';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        $file = $dir . '/' . sha1($bucket . '|' . $ip . '|' . $slot) . '.cnt';
        $fh = @fopen($file, 'c+');
        if ($fh === false) {
            return;
        }
        try {
            flock($fh, LOCK_EX);
            $count = (int) stream_get_contents($fh) + 1;
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, (string) $count);
            fflush($fh);
            flock($fh, LOCK_UN);
        } finally {
            fclose($fh);
        }

        if (mt_rand(1, 200) === 1) {
            $this->sweep($dir);
        }

        if ($count > $max) {
            $retry = $window - (time() % $window);
            throw HttpException::tooManyRequests(
                "Too many requests. Please wait {$retry} seconds and try again."
            );
        }
    }

    private function sweep(string $dir): void
    {
        $cutoff = time() - 1800;
        foreach (glob($dir . '/*.cnt') ?: [] as $f) {
            if (@filemtime($f) < $cutoff) {
                @unlink($f);
            }
        }
    }
}
