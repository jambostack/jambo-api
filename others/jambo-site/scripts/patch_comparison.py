#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Patch toutes les comparison_features dans les 4 locales avec les vraies valeurs."""
import json, urllib.request, urllib.error, time, sys
sys.stdout.reconfigure(encoding='utf-8')

BASE = "https://api.jambostack.site/api/f99cb038-6611-44d3-b1c7-46cf62c1e232"
TOKEN = "964122f433fff77c55e01b64bcf447eefa93f2f6ddc8a7a8c17432c39de43f7e"
HEADERS = {
    "Authorization": f"Bearer {TOKEN}",
    "Content-Type": "application/json",
}

# Valeurs par slug (invariables selon la locale)
VALUES = {
    "multi-project":   {"jambo": "yes", "strapi": "no",      "directus": "no",      "payload": "no"},
    "ai-studio":       {"jambo": "yes", "strapi": "no",      "directus": "no",      "payload": "no"},
    "mcp-server":      {"jambo": "yes", "strapi": "no",      "directus": "partial", "payload": "no"},
    "end-users":       {"jambo": "yes", "strapi": "no",      "directus": "no",      "payload": "no"},
    "versioning":      {"jambo": "yes", "strapi": "partial", "directus": "yes",     "payload": "yes"},
    "multi-locale":    {"jambo": "yes", "strapi": "yes",     "directus": "yes",     "payload": "yes"},
    "graphql":         {"jambo": "yes", "strapi": "yes",     "directus": "yes",     "payload": "yes"},
    "search":          {"jambo": "yes", "strapi": "yes",     "directus": "yes",     "payload": "partial"},
    "webhooks":        {"jambo": "yes", "strapi": "yes",     "directus": "yes",     "payload": "yes"},
    "audit-logs":      {"jambo": "yes", "strapi": "no",      "directus": "yes",     "payload": "no"},
    "pdf-export":      {"jambo": "yes", "strapi": "no",      "directus": "no",      "payload": "no"},
    "backend-stack":   {"jambo": "yes", "strapi": "no",      "directus": "no",      "payload": "no"},
}

def slug_to_key(slug: str) -> str:
    """Convertit un slug comme 'multi-project-cmp-fr' en 'multi-project'."""
    key = slug.replace("-cmp-fr", "").replace("-cmp-es", "").replace("-cmp-ar", "").replace("-cmp", "")
    return key

def fetch(url: str) -> dict:
    req = urllib.request.Request(url, headers=HEADERS)
    with urllib.request.urlopen(req) as r:
        return json.load(r)

def patch(uuid: str, data: dict) -> bool:
    url = f"{BASE}/comparison_features/{uuid}"
    body = json.dumps(data).encode()
    req = urllib.request.Request(url, data=body, headers=HEADERS, method="PATCH")
    try:
        with urllib.request.urlopen(req) as r:
            r.read()
            return True
    except urllib.error.HTTPError as e:
        print(f"  ERROR {e.code}: {e.read().decode()[:200]}")
        return False

ok = 0
fail = 0

for locale in ("en", "fr", "es", "ar"):
    print(f"\n=== {locale.upper()} ===")
    d = fetch(f"{BASE}/comparison_features?locale={locale}&limit=20")
    for entry in sorted(d["data"], key=lambda x: x["order"]):
        key = slug_to_key(entry["slug"])
        vals = VALUES.get(key)
        if vals is None:
            print(f"  ⚠ slug inconnu: {entry['slug']} → key={key}")
            continue
        uuid = entry["uuid"]
        success = patch(uuid, {"status": "published", **vals})
        icon = "✓" if success else "✗"
        print(f"  [{icon}] {entry['slug']} [{uuid[:8]}] J={vals['jambo']} S={vals['strapi']} D={vals['directus']} P={vals['payload']}")
        if success:
            ok += 1
        else:
            fail += 1
        time.sleep(0.05)  # petit délai pour ne pas saturer l'API

print(f"\nRésultat : {ok} OK, {fail} erreurs")
