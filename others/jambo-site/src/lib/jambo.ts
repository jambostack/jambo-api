const BASE = import.meta.env.JAMBO_API_URL as string;
const TOKEN = import.meta.env.JAMBO_API_TOKEN as string;

const headers = {
  Authorization: `Bearer ${TOKEN}`,
  'Content-Type': 'application/json',
};

export interface JamboEntry {
  uuid: string;
  locale: string;
  status: 'draft' | 'published';
  created_at: string;
  updated_at: string;
  [key: string]: unknown;
}

async function get<T = JamboEntry>(path: string): Promise<T | null> {
  try {
    const res = await fetch(`${BASE}/${path}`, { headers });
    if (!res.ok) return null;
    const data = await res.json();
    return (data.data ?? data) as T;
  } catch {
    return null;
  }
}

export async function getCollection(slug: string, locale = 'en', limit = 100): Promise<JamboEntry[]> {
  const data = await get<{ data: JamboEntry[] }>(
    `${slug}?locale=${locale}&limit=${limit}&status=published`
  );
  return data?.data ?? [];
}

export async function getSingleton(slug: string, locale = 'en'): Promise<JamboEntry | null> {
  const list = await getCollection(slug, locale, 1);
  return list[0] ?? null;
}

// Typed helpers
export interface HeroEntry extends JamboEntry {
  headline: string;
  tagline?: string;
  badge?: string;
  cta_primary_label?: string;
  cta_primary_url?: string;
  cta_secondary_label?: string;
  cta_secondary_url?: string;
  snippet?: string;
}

export interface ConfigEntry extends JamboEntry {
  site_name: string;
  site_description?: string;
  github_url?: string;
  contact_email?: string;
  logo_light?: { url: string };
  logo_dark?: { url: string };
  og_image?: { url: string };
}

export interface FeatureEntry extends JamboEntry {
  title: string;
  icon?: string;
  description?: string;
  category?: string;
  order?: number;
}

export interface StatEntry extends JamboEntry {
  title: string;
  value: string;
  icon?: string;
  order?: number;
}

export interface ComparisonFeatureEntry extends JamboEntry {
  title: string;
  jambo?: string;
  strapi?: string;
  directus?: string;
  payload?: string;
  order?: number;
}

export interface EcosystemProductEntry extends JamboEntry {
  name: string;
  slug: string;
  tagline?: string;
  description?: string;
  logo?: { url: string; alt?: string };
  color?: string;
  field_status?: 'available' | 'beta' | 'coming_soon';
  status_label?: string;
  tech_stack?: string;
  url?: string;
  github_url?: string;
  order?: number;
}
