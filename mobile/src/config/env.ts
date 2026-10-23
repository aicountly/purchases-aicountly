/**
 * Build-time settings. Metro inlines every EXPO_PUBLIC_* value, so all of this is public.
 *
 * The rules are pure (`resolveEnvironment`) so the tests can hold them: a release build that
 * names no environment is production, and a production build never talks to the sandbox API.
 */

export type AppEnv = 'local' | 'sandbox' | 'production';

export interface Environment {
  appEnv: AppEnv;
  /** Purchases API root; routes live under /v1. */
  apiBase: string;
  /** The portal page Google sign-in opens in the browser. */
  portalLogin: string;
}

const DEFAULTS: Record<AppEnv, { apiBase: string; portalLogin: string }> = {
  production: { apiBase: 'https://purchase.aicountly.com/api', portalLogin: 'https://my.aicountly.com' },
  sandbox: { apiBase: 'https://purchase.gh.aicountly.com/api', portalLogin: 'https://sandbox.aicountly.com' },
  local: { apiBase: 'https://purchase.gh.aicountly.com/api', portalLogin: 'https://sandbox.aicountly.com' },
};

const trimSlash = (value: string) => value.trim().replace(/\/+$/, '');

export function resolveEnvironment(
  raw: { appEnv?: string; apiBase?: string; portalLogin?: string },
  isRelease: boolean,
): Environment {
  const named = (raw.appEnv ?? '').trim().toLowerCase();
  const appEnv: AppEnv =
    named === 'production' || named === 'sandbox' || named === 'local' ? named : isRelease ? 'production' : 'sandbox';
  const fallback = DEFAULTS[appEnv];
  let apiBase = raw.apiBase && /^https:\/\//.test(raw.apiBase.trim()) ? trimSlash(raw.apiBase) : fallback.apiBase;
  // A production build pointed at a sandbox host is a mistake in the build profile, not a choice.
  if (appEnv === 'production' && /\.gh\.aicountly\.com/.test(apiBase)) apiBase = fallback.apiBase;
  const portalLogin =
    raw.portalLogin && /^https:\/\//.test(raw.portalLogin.trim()) ? trimSlash(raw.portalLogin) : fallback.portalLogin;
  return { appEnv, apiBase, portalLogin };
}

declare const __DEV__: boolean;

export const ENV: Environment = resolveEnvironment(
  {
    appEnv: process.env.EXPO_PUBLIC_APP_ENV,
    apiBase: process.env.EXPO_PUBLIC_API_BASE_URL,
    portalLogin: process.env.EXPO_PUBLIC_PORTAL_LOGIN_URL,
  },
  typeof __DEV__ === 'undefined' ? false : !__DEV__,
);

/**
 * The AICOUNTLY identity service: password and Apple sign-in, the session-key mint, the
 * one-time code exchange and account closure. The same host in every environment.
 */
export const IDENTITY_ORIGIN = 'https://my.aicountly.com';

/** This product's key with the identity service and Manage's company registry. */
export const PRODUCT_KEY = 'purchases';

/** URL scheme registered by app.json; the browser sign-in returns here. */
export const APP_SCHEME = 'aicountlypurchases';

/** The audience Apple stamps on a native identity token: this app's bundle id. */
export const APPLE_AUDIENCE = 'com.aicountly.purchases';

/** Sent as X-Source-App so the API can tell phone traffic apart in its logs. */
export const SOURCE_APP = 'purchases-mobile';

/** The public page that explains account deletion, used only if the API cannot do it. */
export const ACCOUNT_DELETION_PAGE = 'https://aicountly.com/delete-account';

export const PRIVACY_URL = 'https://aicountly.com/privacy-policy';
export const TERMS_URL = 'https://aicountly.com/terms-and-conditions';
export const SUPPORT_EMAIL = 'support@aicountly.com';

/** The web app, for the work that stays on a desk. */
export function webAppUrl(env: Environment = ENV): string {
  return env.apiBase.replace(/\/api$/, '');
}
