import { resolveApiUrl } from './api-url';

export const environment = {
  production: false,
  /** Local ng serve → XAMPP API (http://<host>:8080/api); other domains → live GMP API */
  apiUrl: resolveApiUrl('https://pp.gmpserver.com/api'),
  appName: 'PaperLess Pharmacy-AI Advanced Pharmacy',
};
