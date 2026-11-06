import { resolveApiUrl } from './api-url';

export const environment = {
  production: true,
  /** On pp.gmpserver.com: same-origin `/api`. Build opened locally: XAMPP API on :8080 */
  apiUrl: resolveApiUrl('/api'),
  appName: 'PaperLess Pharmacy-AI Advanced Pharmacy',
};
