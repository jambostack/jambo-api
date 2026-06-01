#!/usr/bin/env python3
import sys, json, urllib.request, urllib.error, time
sys.stdout.reconfigure(encoding='utf-8')

PROJECT_UUID = "f99cb038-6611-44d3-b1c7-46cf62c1e232"
TOKEN = "964122f433fff77c55e01b64bcf447eefa93f2f6ddc8a7a8c17432c39de43f7e"
BASE = f"https://api.jambostack.site/api/{PROJECT_UUID}/ecosystem_products"
HEADERS = {"Authorization": f"Bearer {TOKEN}", "Content-Type": "application/json"}

PRODUCTS = {
    "en": [
        {
            "name": "Jambo API",
            "slug": "jambo-api-en",
            "tagline": "Open-source headless CMS",
            "description": "A production-ready headless CMS built on Symfony 8 and PHP 8.4. Multi-project, multi-locale, with REST, GraphQL, MCP server, AI Schema Studio, end-user auth, full-text search, webhooks, and more.",
            "color": "#2fcf8f",
            "status": "available",
            "status_label": "Available",
            "tech_stack": "Symfony 8 · PHP 8.4",
            "url": "https://api.jambostack.site",
            "github_url": "https://github.com/jambostack/jambo-api",
            "order": 1,
            "locale": "en",
            "status_entry": "published",
        },
        {
            "name": "Jambo Workbench",
            "slug": "jambo-workbench-en",
            "tagline": "AI-powered site builder",
            "description": "A visual site builder powered by AI. Design pages, connect to any headless CMS, and ship fast — without writing a single line of code.",
            "color": "#7c3aed",
            "status": "coming_soon",
            "status_label": "Coming soon",
            "tech_stack": "React · TypeScript · Node.js",
            "url": "https://jambostack.site",
            "github_url": "",
            "order": 2,
            "locale": "en",
            "status_entry": "published",
        },
    ],
    "fr": [
        {
            "name": "Jambo API",
            "slug": "jambo-api-fr",
            "tagline": "CMS headless open-source",
            "description": "Un CMS headless prêt pour la production, construit sur Symfony 8 et PHP 8.4. Multi-projets, multi-locales, avec REST, GraphQL, serveur MCP, Studio IA, authentification front-end, recherche plein texte, webhooks, et plus encore.",
            "color": "#2fcf8f",
            "status": "available",
            "status_label": "Disponible",
            "tech_stack": "Symfony 8 · PHP 8.4",
            "url": "https://api.jambostack.site",
            "github_url": "https://github.com/jambostack/jambo-api",
            "order": 1,
            "locale": "fr",
            "status_entry": "published",
        },
        {
            "name": "Jambo Workbench",
            "slug": "jambo-workbench-fr",
            "tagline": "Builder de sites propulsé par l'IA",
            "description": "Un builder visuel de sites web alimenté par l'IA. Concevez des pages, connectez-vous à n'importe quel CMS headless et publiez rapidement — sans écrire une seule ligne de code.",
            "color": "#7c3aed",
            "status": "coming_soon",
            "status_label": "Bientôt disponible",
            "tech_stack": "React · TypeScript · Node.js",
            "url": "https://jambostack.site",
            "github_url": "",
            "order": 2,
            "locale": "fr",
            "status_entry": "published",
        },
    ],
    "es": [
        {
            "name": "Jambo API",
            "slug": "jambo-api-es",
            "tagline": "CMS headless de código abierto",
            "description": "Un CMS headless listo para producción, construido sobre Symfony 8 y PHP 8.4. Multi-proyecto, multi-idioma, con REST, GraphQL, servidor MCP, Studio IA, autenticación frontend, búsqueda de texto completo, webhooks y más.",
            "color": "#2fcf8f",
            "status": "available",
            "status_label": "Disponible",
            "tech_stack": "Symfony 8 · PHP 8.4",
            "url": "https://api.jambostack.site",
            "github_url": "https://github.com/jambostack/jambo-api",
            "order": 1,
            "locale": "es",
            "status_entry": "published",
        },
        {
            "name": "Jambo Workbench",
            "slug": "jambo-workbench-es",
            "tagline": "Constructor de sitios con IA",
            "description": "Un constructor visual de sitios web impulsado por IA. Diseña páginas, conéctate a cualquier CMS headless y publica rápido — sin escribir una sola línea de código.",
            "color": "#7c3aed",
            "status": "coming_soon",
            "status_label": "Próximamente",
            "tech_stack": "React · TypeScript · Node.js",
            "url": "https://jambostack.site",
            "github_url": "",
            "order": 2,
            "locale": "es",
            "status_entry": "published",
        },
    ],
    "ar": [
        {
            "name": "Jambo API",
            "slug": "jambo-api-ar",
            "tagline": "نظام إدارة محتوى بدون رأس مفتوح المصدر",
            "description": "نظام إدارة محتوى بدون رأس جاهز للإنتاج، مبني على Symfony 8 و PHP 8.4. متعدد المشاريع، متعدد اللغات، مع REST وGraphQL وخادم MCP واستوديو ذكاء اصطناعي ومصادقة المستخدمين وبحث نصي كامل وخطافات ويب والمزيد.",
            "color": "#2fcf8f",
            "status": "available",
            "status_label": "متاح",
            "tech_stack": "Symfony 8 · PHP 8.4",
            "url": "https://api.jambostack.site",
            "github_url": "https://github.com/jambostack/jambo-api",
            "order": 1,
            "locale": "ar",
            "status_entry": "published",
        },
        {
            "name": "Jambo Workbench",
            "slug": "jambo-workbench-ar",
            "tagline": "منشئ مواقع مدعوم بالذكاء الاصطناعي",
            "description": "منشئ مواقع مرئي مدعوم بالذكاء الاصطناعي. صمّم الصفحات، تواصل مع أي نظام CMS بدون رأس وانشر بسرعة — دون كتابة سطر واحد من الكود.",
            "color": "#7c3aed",
            "status": "coming_soon",
            "status_label": "قريباً",
            "tech_stack": "React · TypeScript · Node.js",
            "url": "https://jambostack.site",
            "github_url": "",
            "order": 2,
            "locale": "ar",
            "status_entry": "published",
        },
    ],
}

ok = fail = 0
for locale, products in PRODUCTS.items():
    print(f"\n=== {locale.upper()} ===")
    for p in products:
        payload = {k: v for k, v in p.items() if k not in ("locale", "status_entry")}
        payload["locale"] = p["locale"]
        body = json.dumps(payload).encode()
        req = urllib.request.Request(BASE, data=body, headers=HEADERS, method="POST")
        try:
            with urllib.request.urlopen(req) as r:
                d = json.load(r)
                uuid = d.get("data", d).get("uuid", "?")
                # publier l'entrée
                pub_url = f"{BASE}/{uuid}"
                pub_body = json.dumps({"status": "published"}).encode()
                pub_req = urllib.request.Request(pub_url, data=pub_body, headers=HEADERS, method="PATCH")
                with urllib.request.urlopen(pub_req):
                    pass
                print(f"  [OK] {p['name']} [{uuid[:8]}]")
                ok += 1
        except urllib.error.HTTPError as e:
            err = e.read().decode()[:300]
            print(f"  [ERR] {p['name']}: {e.code} {err}")
            fail += 1
        time.sleep(0.1)

print(f"\nResultat : {ok} OK, {fail} erreurs")
