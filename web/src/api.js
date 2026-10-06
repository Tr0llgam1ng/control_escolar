// The web app ONLY talks to the NestJS API Gateway. It never knows the Laravel services' URLs.
export const API_URL = (import.meta.env.VITE_API_URL ?? 'http://localhost:3000/api').replace(/\/$/, '');

const STORAGE_KEY = 'ce_session';

export function loadSession() {
  try {
    return JSON.parse(localStorage.getItem(STORAGE_KEY) ?? 'null');
  } catch {
    return null;
  }
}

export function saveSession(session) {
  try {
    if (session) localStorage.setItem(STORAGE_KEY, JSON.stringify(session));
    else localStorage.removeItem(STORAGE_KEY);
  } catch {
    /* storage unavailable: session lives only in memory */
  }
}

export class ApiError extends Error {
  constructor(status, payload) {
    super(payload?.message ?? `Error ${status}`);
    this.status = status;
    this.errors = payload?.errors ?? {};
    this.service = payload?.service;
  }
}

let onUnauthorized = () => {};
export function setUnauthorizedHandler(fn) {
  onUnauthorized = fn;
}

export async function api(path, { method = 'GET', body, query } = {}) {
  const url = new URL(API_URL + path);
  Object.entries(query ?? {}).forEach(([k, v]) => {
    if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, v);
  });

  const session = loadSession();
  let response;
  try {
    response = await fetch(url, {
      method,
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        ...(session?.token ? { Authorization: `Bearer ${session.token}` } : {}),
      },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
  } catch {
    throw new ApiError(0, { message: 'No se pudo conectar con el API Gateway. ¿Está corriendo en ' + API_URL + '?' });
  }

  if (response.status === 204) return null;
  const payload = await response.json().catch(() => null);

  if (!response.ok) {
    if (response.status === 401 && path !== '/auth/login') onUnauthorized();
    throw new ApiError(response.status, payload);
  }
  return payload;
}

/** First validation message of a field, if any. */
export const fieldError = (error, field) => error?.errors?.[field]?.[0];
