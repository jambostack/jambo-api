#!/usr/bin/env python3
import sys, json, urllib.request, urllib.error, time
sys.stdout.reconfigure(encoding='utf-8')

PROJECT = "f99cb038-6611-44d3-b1c7-46cf62c1e232"
COLLECTION = "ecosystem_products"
TOKEN = "964122f433fff77c55e01b64bcf447eefa93f2f6ddc8a7a8c17432c39de43f7e"
BASE = f"https://api.jambostack.site/api/projects/{PROJECT}/collections/{COLLECTION}/fields"
HEADERS = {"Authorization": f"Bearer {TOKEN}", "Content-Type": "application/json"}

FIELDS = [
    {"name": "Name",        "slug": "name",         "type": "text",        "is_required": True},
    {"name": "Slug",        "slug": "slug",         "type": "slug",        "is_required": True},
    {"name": "Tagline",     "slug": "tagline",      "type": "text",        "is_required": False},
    {"name": "Description", "slug": "description",  "type": "longtext",    "is_required": False},
    {"name": "Logo",        "slug": "logo",         "type": "media",       "is_required": False},
    {"name": "Color",       "slug": "color",        "type": "color",       "is_required": False},
    {"name": "Status",      "slug": "status",       "type": "enumeration", "is_required": False,
     "settings": {"options": ["available", "beta", "coming_soon"]}},
    {"name": "Status Label","slug": "status_label", "type": "text",        "is_required": False},
    {"name": "Tech Stack",  "slug": "tech_stack",   "type": "text",        "is_required": False},
    {"name": "URL",         "slug": "url",          "type": "text",        "is_required": False},
    {"name": "GitHub URL",  "slug": "github_url",   "type": "text",        "is_required": False},
    {"name": "Order",       "slug": "order",        "type": "number",      "is_required": False},
]

ok = 0
for f in FIELDS:
    body = json.dumps(f).encode()
    req = urllib.request.Request(BASE, data=body, headers=HEADERS, method="POST")
    try:
        with urllib.request.urlopen(req) as r:
            resp = json.load(r)
            print(f"  [OK] {f['slug']} ({f['type']})")
            ok += 1
    except urllib.error.HTTPError as e:
        err = e.read().decode()[:200]
        print(f"  [ERR] {f['slug']}: {e.code} {err}")
    time.sleep(0.1)

print(f"\n{ok}/{len(FIELDS)} champs crees")
