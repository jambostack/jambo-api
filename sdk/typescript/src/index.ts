/**
 * JamboApi CMS — TypeScript SDK
 *
 * Client typé avec cache intelligent, retry automatique et pagination.
 *
 * Usage:
 *   import { JamboApiClient } from '@jamboapi/sdk';
 *   const api = new JamboApiClient({ baseUrl: 'https://...', apiKey: '...' });
 *   const posts = await api.list('posts', { locale: 'fr', limit: 10 });
 */

export interface JamboApiConfig {
  baseUrl: string;
  apiKey?: string;
  timeout?: number;
  retries?: number;
  cache?: boolean;
  cacheTTL?: number;
}

interface CacheEntry {
  data: any;
  expiresAt: number;
}

interface RequestOptions extends RequestInit {
  params?: Record<string, string | number | undefined>;
  cache?: boolean;
}

export class JamboApiClient {
  private baseUrl: string;
  private apiKey?: string;
  private timeout: number;
  private retries: number;
  private cacheEnabled: boolean;
  private cacheTTL: number;
  private cache: Map<string, CacheEntry> = new Map();

  constructor(config: JamboApiConfig) {
    this.baseUrl = config.baseUrl.replace(/\/$/, '');
    this.apiKey = config.apiKey;
    this.timeout = config.timeout ?? 15000;
    this.retries = config.retries ?? 2;
    this.cacheEnabled = config.cache ?? false;
    this.cacheTTL = config.cacheTTL ?? 60000;
  }

  /** Liste les entrées d'une collection avec filtres et pagination. */
  async list<T = Record<string, any>>(
    collection: string,
    options?: { locale?: string; status?: string; limit?: number; offset?: number },
  ): Promise<{ entries: T[]; total: number }> {
    const params: Record<string, string | number | undefined> = {
      locale: options?.locale,
      status: options?.status,
      limit: options?.limit ?? 50,
      offset: options?.offset ?? 0,
    };
    return this.get(`/api/collections/${collection}`, { params });
  }

  /** Obtient une entrée par son UUID. */
  async getEntry<T = Record<string, any>>(collection: string, uuid: string): Promise<T | null> {
    return this.get(`/api/collections/${collection}/${uuid}`);
  }

  /** Crée une entrée. */
  async create<T = Record<string, any>>(collection: string, data: Record<string, any>): Promise<T> {
    return this.post(`/api/collections/${collection}`, data);
  }

  /** Met à jour une entrée. */
  async update<T = Record<string, any>>(collection: string, uuid: string, data: Record<string, any>): Promise<T> {
    return this.put(`/api/collections/${collection}/${uuid}`, data);
  }

  /** Supprime (soft-delete) une entrée. */
  async delete(collection: string, uuid: string): Promise<boolean> {
    const result = await this.del(`/api/collections/${collection}/${uuid}`);
    return result?.deleted === true;
  }

  /** Recherche full-text. */
  async search(query: string, options?: { collection?: string; locale?: string; limit?: number }): Promise<any> {
    const params: Record<string, string | number | undefined> = {
      q: query,
      collection: options?.collection,
      locale: options?.locale,
      limit: options?.limit ?? 20,
    };
    return this.get('/api/search', { params });
  }

  /** Liste les médias. */
  async listMedia(options?: { search?: string; limit?: number; offset?: number }): Promise<any> {
    return this.get('/api/media', { params: options as any });
  }

  /** URL d'un média avec transformations. */
  mediaUrl(uuid: string, transforms?: { w?: number; h?: number; fit?: string; fmt?: string; q?: number }): string {
    const params = new URLSearchParams();
    if (transforms) {
      Object.entries(transforms).forEach(([k, v]) => { if (v !== undefined) params.set(k, String(v)); });
    }
    const qs = params.toString();
    return `${this.baseUrl}/cdn/media/${uuid}${qs ? '?' + qs : ''}`;
  }

  /** Vide le cache. */
  clearCache(): void { this.cache.clear(); }

  // ===== HTTP Core =====

  private async request<T>(path: string, options: RequestOptions = {}): Promise<T> {
    const url = this.buildUrl(path, options.params);
    const cacheKey = options.method === 'GET' ? url : null;

    if (cacheKey && this.cacheEnabled) {
      const cached = this.cache.get(cacheKey);
      if (cached && cached.expiresAt > Date.now()) return cached.data;
    }

    let lastError: Error | null = null;
    for (let attempt = 0; attempt <= this.retries; attempt++) {
      try {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), this.timeout);

        const res = await fetch(url, {
          ...options,
          signal: controller.signal,
          headers: {
            'Content-Type': 'application/json',
            ...(this.apiKey ? { Authorization: `Bearer ${this.apiKey}` } : {}),
            ...options.headers,
          },
        });

        clearTimeout(timer);
        if (!res.ok) throw new JamboApiError(`HTTP ${res.status}: ${res.statusText}`, res.status);
        const data = await res.json();

        if (cacheKey && this.cacheEnabled) {
          this.cache.set(cacheKey, { data, expiresAt: Date.now() + this.cacheTTL });
        }

        return data;
      } catch (e: any) {
        lastError = e;
        if (e instanceof JamboApiError) throw e;
        if (attempt < this.retries) await this.sleep(attempt);
      }
    }

    throw lastError ?? new Error('Request failed');
  }

  private get<T>(path: string, options?: RequestOptions): Promise<T> {
    return this.request<T>(path, { ...options, method: 'GET' });
  }

  private post<T>(path: string, body: any): Promise<T> {
    return this.request<T>(path, { method: 'POST', body: JSON.stringify(body) });
  }

  private put<T>(path: string, body: any): Promise<T> {
    return this.request<T>(path, { method: 'PUT', body: JSON.stringify(body) });
  }

  private del<T>(path: string): Promise<T> {
    return this.request<T>(path, { method: 'DELETE' });
  }

  private buildUrl(path: string, params?: Record<string, string | number | undefined>): string {
    const url = new URL(path, this.baseUrl);
    if (params) {
      Object.entries(params).forEach(([k, v]) => {
        if (v !== undefined && v !== null) url.searchParams.set(k, String(v));
      });
    }
    return url.toString();
  }

  private sleep(attempt: number): Promise<void> {
    return new Promise(r => setTimeout(r, Math.min(1000 * (2 ** attempt), 8000)));
  }
}

export class JamboApiError extends Error {
  constructor(message: string, public statusCode: number) {
    super(message);
    this.name = 'JamboApiError';
  }
}

export default JamboApiClient;
