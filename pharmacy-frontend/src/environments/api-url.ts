/**
 * Picks the API by the domain the app is opened from — no file edits between local and live.
 *  - localhost / 127.x / LAN IP (192.168.x, 10.x, 172.16-31.x) → local XAMPP API on port 8080
 *  - any other domain (e.g. pp.gmpserver.com)                   → `liveApi`
 */
export function resolveApiUrl(liveApi: string): string {
  const host = typeof window !== 'undefined' ? window.location.hostname : '';
  const isLocal =
    host === 'localhost' ||
    /^127\./.test(host) ||
    /^10\./.test(host) ||
    /^192\.168\./.test(host) ||
    /^172\.(1[6-9]|2\d|3[01])\./.test(host);
  return isLocal ? `http://${host}:8080/api` : liveApi;
}
