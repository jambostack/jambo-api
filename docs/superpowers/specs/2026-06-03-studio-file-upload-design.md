# Studio Chat — Téléversement de fichiers

**Date :** 2026-06-03
**Statut :** Approuvé

---

## Objectif

Permettre à l'utilisateur de joindre des fichiers dans le chat Jambo Studio afin que l'IA puisse les analyser et agir en conséquence. Quatre cas d'usage sont couverts en une seule livraison.

---

## Cas d'usage

| # | Type | Exemple |
|---|------|---------|
| 1 | **Vision / Image** | Envoyer un screenshot → l'IA génère le schéma correspondant |
| 2 | **Import de données** | Envoyer un CSV/JSON → l'IA crée les entrées de collection |
| 3 | **Document texte** | Envoyer un PDF/TXT → l'IA extrait le contenu en entrées |
| 4 | **Médiathèque** | Sélectionner un fichier déjà uploadé → l'IA le référence dans sa réponse |

---

## UX — Interface

### Zone de saisie

La barre de saisie du chat (`scp-input-row`) reçoit un bouton 📎 à gauche du textarea.

```
[ 📎 ] [ textarea                    ] [ ↑ ]
```

Un clic sur 📎 ouvre le **picker unifié** (voir ci-dessous). Aucun second bouton — tout passe par le picker.

### Picker unifié

Petit popover au-dessus de la barre de saisie, deux onglets :

- **Depuis l'ordinateur** — zone de drag & drop + bouton « Parcourir ». Formats acceptés : `image/*`, `.csv`, `.json`, `.xlsx`, `.pdf`, `.txt`, `.md`. Taille max : **10 MB**.
- **Médiathèque** — grille des fichiers du projet (réutilise le composant `MediaFieldSelectModal` existant, mode sélection unique).

### Preview strip

Dès qu'un fichier est sélectionné (avant envoi), une bande apparaît entre les messages et la zone de saisie :

```
[ 🖼️ ] design_v2.png  142 KB  [ × ]
```

Le × supprime la pièce jointe. Un seul fichier à la fois.

### Bulle de message

Le fichier joint s'affiche dans la bulle utilisateur sous forme de **pill compact** précédant le texte :

```
[ 🖼️ wireframe.png ]  Crée le schéma de ce design
```

Le rôle et le contenu des messages IA existants ne changent pas.

---

## Architecture

### Frontend — `SchemaBuilder.tsx`

**Nouveaux états :**
```ts
const [attachment, setAttachment] = useState<AttachmentFile | null>(null);
const [pickerOpen, setPickerOpen] = useState(false);
```

**Nouveau type :**
```ts
interface AttachmentFile {
  name: string;           // nom original
  mimeType: string;       // MIME type
  size: number;           // octets
  source: 'upload' | 'media';
  // si 'upload' :
  base64?: string;        // contenu encodé base64 (images + docs)
  text?: string;          // contenu texte (CSV, JSON, TXT) pré-extrait côté client
  // si 'media' :
  mediaUuid?: string;     // UUID dans la médiathèque du projet
}
```

**Interface `ChatMessage` étendue :**
```ts
interface ChatMessage {
  // … champs existants …
  attachment?: { name: string; mimeType: string; size: number };
}
```

**Modifications de `send()` :**
- Inclure l'attachment dans le body JSON envoyé à `ai-chat`
- Après envoi : `setAttachment(null)`

**Nouveau composant inline `AttachmentPicker` :**
- Popover (Radix `Popover`) ancré sur le bouton 📎
- Onglet 1 : `<input type="file">` caché + zone drop
- Onglet 2 : `MediaFieldSelectModal` en mode sélection unique

### Backend — `StudioController.php` — `aiChat()`

**Nouvelle entrée attendue dans le body JSON :**
```json
{
  "prompt": "...",
  "command": "schema",
  "context": "...",
  "history": [...],
  "attachment": {
    "name": "wireframe.png",
    "mimeType": "image/png",
    "base64": "iVBORw0KGgo...",
    "text": null,
    "mediaUuid": null
  }
}
```

**Logique de traitement par type :**

| Type MIME | Traitement |
|-----------|-----------|
| `image/*` | Injecté comme partie `image_url` dans le message utilisateur (format vision OpenAI/Claude/Gemini). Si le provider ne supporte pas la vision, une note textuelle est ajoutée : *"[Image jointe : nom.png — analyse visuelle non disponible avec ce provider]"* |
| `text/csv`, `application/json`, `text/plain`, `text/markdown` | Le champ `text` (pré-extrait côté client) est injecté dans un bloc système : *"Fichier joint : [nom]\n[contenu tronqué à 8 000 chars]"* |
| `application/pdf` | Le texte est extrait côté client via `pdfjs-dist` (JS) et envoyé dans `text`. Même traitement que texte. |
| Médiathèque (`mediaUuid`) | Le serveur résout l'UUID → `full_url`. Si image : traité comme `image/*`. Sinon : URL injectée dans le contexte. |

### Gestion de la vision par provider

| Provider | Vision supportée | Format |
|----------|-----------------|--------|
| OpenAI (GPT-4o, gpt-4-turbo) | ✅ | `{ type: "image_url", image_url: { url: "data:image/png;base64,..." } }` |
| Anthropic (Claude 3+) | ✅ | `{ type: "image", source: { type: "base64", media_type: "image/png", data: "..." } }` |
| Google Gemini | ✅ | `{ inlineData: { mimeType: "image/png", data: "..." } }` |
| Ollama / DeepSeek / Mistral / autres | ❌ | Fallback texte |

La méthode `callAiApi()` existante est étendue pour accepter un paramètre optionnel `?array $attachmentPart` et le brancher selon le provider.

---

## Flux de données complet

```
Utilisateur sélectionne fichier
  └─ AttachmentPicker → setAttachment(file)
     └─ Preview strip affichée

Utilisateur clique ↑ (send)
  └─ send() construit le payload JSON :
       { prompt, command, context, history, attachment }
  └─ POST /api/projects/{uuid}/studio/ai-chat
       └─ aiChat() PHP :
            ├─ image/* → buildVisionPart() → injecté dans message user
            ├─ text/csv|json → texte dans bloc système
            ├─ mediaUuid → resolve URL → traitement image ou URL
            └─ callAiApi() avec parts enrichis
       └─ Réponse IA → reply JSON
  └─ Frontend : message user affiché (avec pill attachment)
     Frontend : message assistant affiché
     attachment effacé
```

---

## Fichiers modifiés

| Fichier | Nature |
|---------|--------|
| `assets/js/pages/Projects/Settings/Studio/SchemaBuilder.tsx` | UI picker, preview strip, send(), ChatMessage |
| `src/Controller/StudioController.php` | `aiChat()` + `callAiApi()` étendus |
| `translations/messages.*.php` (×4) | Clés i18n pour picker et erreurs |

---

## Contraintes et limites

- **Taille max fichier :** 10 MB (vérifiée côté client avant envoi)
- **Un seul fichier à la fois** dans un message
- **PDF :** extraction texte côté client via `pdfjs-dist` (déjà dans node_modules ou à ajouter)
- **CSV/JSON :** pré-extrait et tronqué à 8 000 caractères côté client avant envoi
- **XLSX :** converti en CSV côté client via `xlsx` lib (à ajouter si non présente)
- **Vision non supportée :** fallback textuel discret, pas d'erreur bloquante

---

## Clés i18n à ajouter (×4 langues)

```
studio.picker.title         = Joindre un fichier
studio.picker.tab_local     = Depuis l'ordinateur
studio.picker.tab_media     = Médiathèque
studio.picker.drop_hint     = Glissez un fichier ici
studio.picker.browse        = Parcourir
studio.picker.formats       = Images, CSV, JSON, PDF, TXT — max 10 MB
studio.picker.too_large     = Fichier trop volumineux (max 10 MB)
studio.picker.remove        = Supprimer la pièce jointe
```
