# @jambostack/live-preview

SDK frontend pour le système de Live Preview de Jambo CMS.

## Installation

```bash
npm install @jambostack/live-preview
```

## Usage Next.js

```tsx
import { useLivePreview } from '@jambostack/live-preview/next';

export default function BlogPost({ initialData }) {
  const { data, isPreview } = useLivePreview({ initialData });

  return (
    <article>
      <h1>{data.title}</h1>
      <div dangerouslySetInnerHTML={{ __html: data.body }} />
    </article>
  );
}
```

## Visual Editing (v1.14b)

Ajoutez `data-jambo-*` aux elements HTML avec `fieldProps()` :

```tsx
const { data, isPreview, fieldProps } = useLivePreview({ initialData });

return (
  <article>
    <h1 {...fieldProps('title', 'text')}>{data.title}</h1>
    <div {...fieldProps('body', 'rich-text')}>
      <RichText content={data.body} />
    </div>
    <img {...fieldProps('cover', 'media')} src={data.cover} />
  </article>
);
```

- Survol : contour bleu dans l'iframe + surbrillance du champ dans l'admin
- Clic sur un champ texte : popover d'edition inline
- Clic sur un champ complexe : scroll vers le champ dans l'admin

## Usage Vanilla / Autre framework

```ts
import { subscribe } from '@jambostack/live-preview/core';

const unsub = subscribe({
  onInit: async (ctx) => {
    const res = await fetch(`/api/content/${ctx.collection}/${ctx.entryUuid}`);
    return res.json();
  },
  onUpdate: (data) => {
    document.getElementById('title')!.textContent = data.title;
  },
});
```

## Licence

MIT
