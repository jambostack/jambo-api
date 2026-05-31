# Charte graphique — Jambo

> **But** : document de référence du design system de **tout le projet Jambo**, destiné à être lu par un humain **ou un agent IA** pour produire des interfaces parfaitement conformes à l'identité Jambo.
> **Source de vérité** : `assets/styles/app.css` (Tailwind v4 + shadcn/ui). Les valeurs ci‑dessous en sont l'extraction fidèle. En cas de doute, ce fichier prime.

---

## 1. Essence

Jambo est un **CMS headless** à l'identité **sobre, technique et organique** : fond presque noir/vert très sombre, **accent émeraude** lumineux, touches **lime acide**, typographie à fort caractère (display géométrique + corps humaniste + mono pour la donnée). Mode **clair et sombre** de premier ordre. Mouvement **discret et fonctionnel** (apparitions douces, aurora, halos), jamais gratuit.

**Mots‑clés** : émeraude · contraste maîtrisé · OKLCH · précision · calme technique.

---

## 2. Typographie

Trois familles principales (+ une serif éditoriale secondaire).

| Rôle | Police | Variable CSS | Usage |
|---|---|---|---|
| **Display / titres** | **Syne** (700) | `--font-display` | `h1`–`h6`, chiffres clés, accroches. `letter-spacing: -0.02em`. |
| **Corps / UI** | **DM Sans** | `--font-sans` | Texte courant, libellés, boutons, formulaires. |
| **Mono / données** | **JetBrains Mono** (fallback Fira Code) | `--font-mono` | Code, slugs, UUID, endpoints, valeurs techniques. |
| **Serif éditoriale** *(secondaire)* | **Newsreader** (fallback Cormorant Garamond) | `--studio-serif` | En‑têtes éditoriaux/raffinés (ex. titres du Studio). À utiliser avec parcimonie. |

**Déclarations exactes :**
```css
--font-sans:   'DM Sans', ui-sans-serif, system-ui, sans-serif,
               'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol', 'Noto Color Emoji';
--font-display:'Syne', ui-sans-serif, system-ui, sans-serif;
--font-mono:   'JetBrains Mono', 'Fira Code', ui-monospace, 'Cascadia Code',
               'Source Code Pro', Menlo, Consolas, monospace;
```

**Import (Google Fonts) :**
```html
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700&family=JetBrains+Mono:wght@400;500;600&family=Newsreader:opsz,wght@6..72,400;6..72,500&display=swap" rel="stylesheet">
```

**Règles d'or :**
- Les titres (`h1`–`h6`) sont **toujours** en `--font-display` (Syne), `font-weight: 700`, `letter-spacing: -0.02em`.
- Le corps active les *character variants* : `font-feature-settings: 'cv02','cv03','cv04','cv11';`.
- Toute donnée technique (slug, id, URL, token, JSON) est en **mono**.
- **Interdits** : Inter, Roboto, Arial, polices système par défaut comme choix principal.

---

## 3. Couleurs

Le système est en **OKLCH** (source de vérité). Des approximations hex sont fournies pour les outils sans OKLCH. Deux thèmes : **clair** (`:root`) et **sombre** (`.dark`).

### Sémantique
| Token | Rôle |
|---|---|
| `background` / `foreground` | fond de page / texte principal |
| `card` / `card-foreground` | surfaces (cartes, popovers) |
| `primary` | **émeraude** — actions, liens, focus, marque |
| `secondary` | surfaces secondaires discrètes |
| `muted` / `muted-foreground` | fonds atténués / texte secondaire |
| `accent` | **lime** — survols, accents éditoriaux |
| `destructive` | rouge — suppression, erreurs |
| `border` / `input` / `ring` | bordures / champs / anneau de focus |
| `chart-1…5` | séries de graphiques |

### Mode clair (`:root`)
```css
--radius: 0.625rem;            /* 10px */

--background: oklch(0.99 0.004 155);   /* ~#fbfdfc  blanc verdâtre */
--foreground: oklch(0.12 0.015 170);   /* ~#0c1512  presque noir */
--card:       oklch(1 0 0);            /* #ffffff */
--card-foreground: oklch(0.12 0.015 170);
--popover:    oklch(1 0 0);
--popover-foreground: oklch(0.12 0.015 170);

--primary: oklch(0.55 0.18 158);            /* ~#0f9d6b  émeraude */
--primary-foreground: oklch(0.99 0 0);
--secondary: oklch(0.95 0.007 155);
--secondary-foreground: oklch(0.25 0.015 170);
--muted: oklch(0.95 0.007 155);
--muted-foreground: oklch(0.52 0.012 170);  /* ~#6b7a72 */
--accent: oklch(0.93 0.018 130);            /* lime doux */
--accent-foreground: oklch(0.28 0.1 145);
--destructive: oklch(0.577 0.245 27.325);   /* ~#dc2626 rouge */
--destructive-foreground: oklch(0.99 0 0);

--border: oklch(0.89 0.008 155);            /* ~#dfe5e2 */
--input:  oklch(0.89 0.008 155);
--ring:   oklch(0.55 0.18 158);             /* émeraude */

--chart-1: oklch(0.62 0.19 158); /* émeraude */
--chart-2: oklch(0.68 0.15 195); /* cyan */
--chart-3: oklch(0.75 0.20 130); /* lime */
--chart-4: oklch(0.65 0.16 220); /* bleu */
--chart-5: oklch(0.70 0.18 115); /* vert‑jaune */
```

### Mode sombre (`.dark`) — thème par défaut de l'identité
```css
--background: oklch(0.13 0.012 165);   /* ~#0b0f0d  vert très sombre */
--foreground: oklch(0.95 0.005 155);   /* ~#eef2f0 */
--card:       oklch(0.17 0.014 165);   /* ~#111714 */
--card-foreground: oklch(0.95 0.005 155);
--popover:    oklch(0.17 0.014 165);
--popover-foreground: oklch(0.95 0.005 155);

--primary: oklch(0.68 0.20 158);            /* ~#2fcf8f  émeraude lumineuse */
--primary-foreground: oklch(0.08 0 0);      /* ~#0a0a0a (texte sur émeraude) */
--secondary: oklch(0.22 0.016 165);
--secondary-foreground: oklch(0.92 0.005 155);
--muted: oklch(0.22 0.016 165);             /* ~#171d19 */
--muted-foreground: oklch(0.64 0.012 165);
--accent: oklch(0.24 0.06 140);
--accent-foreground: oklch(0.82 0.20 130);  /* ~#bef264  lime acide */
--destructive: oklch(0.704 0.191 22.216);   /* ~#f87171 */
--destructive-foreground: oklch(0.1 0 0);

--border: oklch(1 0 0 / 9%);                /* blanc à 9% */
--input:  oklch(1 0 0 / 14%);
--ring:   oklch(0.68 0.20 158);

--chart-1: oklch(0.68 0.20 158);
--chart-2: oklch(0.70 0.16 195);
--chart-3: oklch(0.80 0.22 130);
--chart-4: oklch(0.66 0.16 220);
--chart-5: oklch(0.74 0.20 115);
```

### Couleurs d'appoint
- **Ambre / warning** : `#f7b955` (états d'attention, console).
- **Émeraude “signature” (hex)** : `#2fcf8f` — utile comme repère hors OKLCH.

### Règles d'usage
- **L'émeraude (`primary`) est l'unique accent d'action.** Liens, boutons primaires, focus, sélection, marque.
- Le **lime (`accent`)** sert aux **survols** et accents éditoriaux, jamais aux actions critiques.
- **Dominante neutre + accent franc** : grandes surfaces en `background`/`card`, l'émeraude par petites touches.
- **Jamais** de dégradé violet‑sur‑blanc générique. L'identité est verte/émeraude.
- Texte secondaire en `muted-foreground` ; ne jamais descendre sous un contraste AA (4.5:1) pour le texte courant.

---

## 4. Formes & élévation

```css
--radius: 0.625rem;                 /* 10px — base */
--radius-lg: var(--radius);         /* 10px */
--radius-md: calc(var(--radius) - 2px); /* 8px */
--radius-sm: calc(var(--radius) - 4px); /* 6px */
```
- **Rayons** : cartes/boutons `--radius-md`/`lg` ; petits éléments (badges, inputs) `--radius-sm`.
- **Bordures** : fines, `1px solid var(--border)` (translucides en sombre : blanc 9 %).
- **Élévation** : préférer **bordure + fond `card`** aux ombres lourdes. Ombres douces et basses uniquement pour les overlays (popovers, dialogs).
- **Focus** : anneau `--ring` (émeraude), `outline-ring/50`.

---

## 5. Mouvement

Sobre, court, fonctionnel. Keyframes officielles (déjà dans `app.css`) :

```css
/* Apparition échelonnée (fade + slide up) */
@keyframes jambo-rise { from{opacity:0;transform:translateY(8px)} to{opacity:1;transform:translateY(0)} }
.jambo-rise { animation: jambo-rise .45s cubic-bezier(.16,1,.3,1) both; }

/* Dégradé animé — identité IA */
@keyframes jambo-aurora { 0%,100%{background-position:0% 50%} 50%{background-position:100% 50%} }
.jambo-aurora { background-size:200% 200%; animation: jambo-aurora 6s ease infinite; }

/* Halo pulsant (puce de statut) */
@keyframes jambo-pulse-ring { 0%{box-shadow:0 0 0 0 var(--jambo-ring)} 70%{box-shadow:0 0 0 6px transparent} 100%{box-shadow:0 0 0 0 transparent} }
.jambo-pulse-ring { animation: jambo-pulse-ring 2.4s ease-out infinite; }

/* Ligne de console (fade + slide + glow) */
.console-rise { animation: console-rise .3s cubic-bezier(.16,1,.3,1) both; }
```
- **Easing signature** : `cubic-bezier(.16, 1, .3, 1)` (sortie douce).
- **Durées** : micro‑interactions 120–300 ms ; apparitions 300–450 ms ; ambiances (aurora) 6 s.
- Utiliser `.jambo-rise` (avec `animation-delay` croissant) pour révéler une liste/section au chargement.
- Respecter `prefers-reduced-motion` : désactiver les boucles (aurora, pulse) si réduit.

---

## 6. Composants (conventions)

Base : **shadcn/ui sur Tailwind** (Radix UI). Les classes utilitaires consomment les tokens (`bg-background`, `text-foreground`, `border-border`, `bg-primary`, `text-muted-foreground`, `rounded-md`…).

- **Bouton primaire** : `bg-primary text-primary-foreground` (émeraude), rayon `md`, états hover/active subtils.
- **Bouton secondaire/outline** : `border-border bg-card`, texte `foreground`, hover → bordure `ring`/`primary`.
- **Carte** : `bg-card border border-border rounded-lg` ; titres en display, contenu en sans.
- **Badge** : `rounded-sm`, petite taille, `secondary`/`muted` ; états en `primary`/`destructive`.
- **Champ** : `bg-input/—`, `border-input`, focus anneau `ring`.
- **Données techniques** : toujours en `--font-mono`, souvent dans un fond `muted` arrondi.
- **Icônes** : `lucide-react`, trait fin, taille 14–16px alignée au texte.

---

## 7. Snippet `@theme` prêt à coller (Tailwind v4)

Pour amorcer un nouveau front conforme, reprendre l'ossature de `app.css` :
```css
@import 'tailwindcss';
@plugin 'tailwindcss-animate';
@custom-variant dark (&:is(.dark *));

@theme {
  --font-sans:    'DM Sans', ui-sans-serif, system-ui, sans-serif;
  --font-display: 'Syne', ui-sans-serif, system-ui, sans-serif;
  --font-mono:    'JetBrains Mono', 'Fira Code', ui-monospace, monospace;
  --radius-lg: var(--radius);
  --radius-md: calc(var(--radius) - 2px);
  --radius-sm: calc(var(--radius) - 4px);
  /* mapper --color-* sur les --* sémantiques (voir app.css) */
}

:root  { /* … tokens du mode clair (section 3) … */ }
.dark  { /* … tokens du mode sombre (section 3) … */ }

@layer base {
  h1,h2,h3,h4,h5,h6 { font-family: var(--font-display); font-weight: 700; letter-spacing: -0.02em; }
  body { @apply bg-background text-foreground; font-feature-settings:'cv02','cv03','cv04','cv11'; }
}
```

---

## 8. À faire / À éviter

**À faire**
- Mode sombre par défaut, émeraude par touches, données en mono.
- Syne pour les titres, DM Sans pour le corps.
- Contraste AA minimum ; espace négatif généreux.
- Mouvement court avec l'easing signature.

**À éviter**
- Inter/Roboto/Arial comme police principale.
- Dégradés violets sur blanc, accents multicolores dispersés.
- Ombres lourdes ; préférer bordure + surface.
- Couleurs en dur hors tokens (toujours passer par les variables CSS).

---

*Charte extraite de `assets/styles/app.css`. Toute évolution doit y être répercutée pour rester la source de vérité unique.*
