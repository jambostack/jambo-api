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
