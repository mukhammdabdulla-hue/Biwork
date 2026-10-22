/**
 * Thin fetch client for the BIWORK API.
 *
 * - The access token lives only in memory; the refresh token is an HttpOnly cookie the browser
 *   sends to /api/v1/auth/* (docs/architecture.md §8).
 * - Every request carries the selected company (X-Company-Id).
 * - Errors are RFC 9457 problem+json and surface as ApiError.
 */

export interface Problem {
  type?: string;
  title?: string;
  status: number;
  detail?: string;
  code?: string;
  request_id?: string;
  errors?: { loc: string[]; message: string }[];
  [key: string]: unknown;
}

export class ApiError extends Error {
  status: number;
  code: string;
  problem: Problem;

  constructor(problem: Problem) {
    super(problem.detail || problem.title || `Request failed (${problem.status})`);
    this.status = problem.status;
    this.code = problem.code ?? "error";
    this.problem = problem;
  }
}

type RefreshResult = { access_token: string; user: unknown } | null;

let accessToken: string | null = null;
let companyId: string | null = null;
let refreshing: Promise<RefreshResult> | null = null;
let onSessionLost: (() => void) | null = null;

export const session = {
  setToken(token: string | null) {
    accessToken = token;
  },
  getToken: () => accessToken,
  setCompany(id: string | null) {
    companyId = id;
  },
  getCompany: () => companyId,
  onSessionLost(handler: () => void) {
    onSessionLost = handler;
  },
};

export async function refreshSession(): Promise<RefreshResult> {
  if (!refreshing) {
    refreshing = fetch("/api/v1/auth/refresh", {
      method: "POST",
      credentials: "same-origin",
      headers: { "X-Requested-With": "biwork" },
    })
      .then(async (r) => (r.ok ? ((await r.json()) as RefreshResult) : null))
      .catch(() => null)
      .then((data) => {
        accessToken = data?.access_token ?? null;
        return data;
      })
      .finally(() => {
        refreshing = null;
      });
  }
  return refreshing;
}

export type Query = Record<string, string | number | boolean | null | undefined>;

interface RequestOptions {
  query?: Query;
  body?: unknown;
  form?: FormData;
  headers?: Record<string, string>;
  signal?: AbortSignal;
  raw?: boolean;
  retry?: boolean;
}

function buildUrl(path: string, query?: Query): string {
  const url = new URL(`/api/v1${path}`, window.location.origin);
  for (const [k, v] of Object.entries(query ?? {})) {
    if (v !== undefined && v !== null && v !== "") url.searchParams.set(k, String(v));
  }
  return url.pathname + url.search;
}

async function toProblem(response: Response): Promise<Problem> {
  try {
    const body = await response.json();
    return { status: response.status, ...body };
  } catch {
    return { status: response.status, title: response.statusText };
  }
}

export async function request<T>(method: string, path: string, opts: RequestOptions = {}): Promise<T> {
  const headers: Record<string, string> = { "X-Requested-With": "biwork", ...(opts.headers ?? {}) };
  if (accessToken) headers.Authorization = `Bearer ${accessToken}`;
  if (companyId && !headers["X-Company-Id"]) headers["X-Company-Id"] = companyId;
  let body: BodyInit | undefined;
  if (opts.form) body = opts.form;
  else if (opts.body !== undefined) {
    headers["Content-Type"] = "application/json";
    body = JSON.stringify(opts.body);
  }
  const response = await fetch(buildUrl(path, opts.query), { method, headers, body, signal: opts.signal, credentials: "same-origin" });
  if (response.status === 401 && opts.retry !== false && !path.startsWith("/auth/")) {
    const problem = await toProblem(response);
    if (["token_expired", "invalid_token", "unauthorized"].includes(problem.code ?? "")) {
      const refreshed = await refreshSession();
      if (refreshed) return request<T>(method, path, { ...opts, retry: false });
      onSessionLost?.();
    }
    throw new ApiError(problem);
  }
  if (!response.ok) throw new ApiError(await toProblem(response));
  if (opts.raw) return response as unknown as T;
  if (response.status === 204) return undefined as T;
  const type = response.headers.get("content-type") ?? "";
  return (type.includes("json") ? await response.json() : await response.text()) as T;
}

export const api = {
  get: <T>(path: string, query?: Query, signal?: AbortSignal) => request<T>("GET", path, { query, signal }),
  post: <T>(path: string, body?: unknown, headers?: Record<string, string>) => request<T>("POST", path, { body, headers }),
  put: <T>(path: string, body?: unknown, headers?: Record<string, string>) => request<T>("PUT", path, { body, headers }),
  patch: <T>(path: string, body?: unknown, headers?: Record<string, string>) => request<T>("PATCH", path, { body, headers }),
  delete: <T>(path: string) => request<T>("DELETE", path),
  upload: <T>(path: string, form: FormData, headers?: Record<string, string>) => request<T>("POST", path, { form, headers }),
  /** GET a resource and keep its ETag for a later If-Match. */
  async getWithEtag<T>(path: string): Promise<{ data: T; etag: string | null }> {
    const response = await request<Response>("GET", path, { raw: true });
    return { data: (await response.json()) as T, etag: response.headers.get("etag") };
  },
  async download(path: string, filename: string): Promise<void> {
    const response = await request<Response>("GET", path, { raw: true });
    const blob = await response.blob();
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = filename;
    a.click();
    setTimeout(() => URL.revokeObjectURL(url), 2000);
  },
  /** Fetch a file as an object URL (for previews of PDFs and images). */
  async objectUrl(path: string): Promise<string> {
    const response = await request<Response>("GET", path, { raw: true });
    return URL.createObjectURL(await response.blob());
  },
};

export function newIdempotencyKey(): string {
  return crypto.randomUUID();
}
