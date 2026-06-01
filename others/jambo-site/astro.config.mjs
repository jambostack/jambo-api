import { defineConfig } from 'astro/config';
import starlight from '@astrojs/starlight';
import tailwind from '@astrojs/tailwind';

export default defineConfig({
  integrations: [
    tailwind({ applyBaseStyles: false }),
    starlight({
      title: 'Jambostack',
      logo: {
        light: './src/assets/logo-light.svg',
        dark: './src/assets/logo-dark.svg',
        replacesTitle: true,
      },
      social: [
        { icon: 'github', label: 'GitHub', href: 'https://github.com/jambostack/jambo-api' },
      ],
      defaultLocale: 'en',
      locales: {
        en: { label: 'English', lang: 'en' },
        fr: { label: 'Français', lang: 'fr' },
        es: { label: 'Español', lang: 'es' },
        ar: { label: 'العربية', lang: 'ar', dir: 'rtl' },
      },
      sidebar: [
        {
          label: 'Getting Started',
          translations: { fr: 'Démarrage', es: 'Comenzar', ar: 'البدء' },
          items: [
            { slug: 'guides/installation' },
            { slug: 'guides/quick-start' },
            { slug: 'guides/configuration' },
          ],
        },
        {
          label: 'API Reference',
          translations: { fr: 'Référence API', es: 'Referencia API', ar: 'مرجع API' },
          items: [
            { slug: 'api/rest' },
            { slug: 'api/graphql' },
            { slug: 'api/mcp' },
          ],
        },
        {
          label: 'Studio AI',
          items: [
            { slug: 'studio/schema-builder' },
            { slug: 'studio/ai-providers' },
          ],
        },
      ],
      customCss: ['./src/styles/global.css'],
    }),
  ],
});
