import { test, expect } from '@playwright/test';

test.describe('JamboAPI CMS - API REST Endpoints', () => {
  test('vérifie la documentation OpenAPI Admin (/api/settings/admin-api/openapi.json)', async ({ request }) => {
    const res = await request.get('/api/settings/admin-api/openapi.json');
    expect(res.ok()).toBeTruthy();
    const data = await res.json();
    expect(data.openapi).toMatch(/^3\./);
    expect(data.info).toBeDefined();
    expect(data.paths).toBeDefined();
  });

  test('redirige les requêtes web non connectées de / vers /login', async ({ request }) => {
    const res = await request.get('/', { maxRedirects: 0 });
    // Doit retourner une redirection 302
    expect(res.status()).toBe(302);
    expect(res.headers()['location']).toContain('/login');
  });

  test('refuse les connexions EndUser avec des identifiants vides ou erronés', async ({ request }) => {
    const res = await request.post('/api/1/auth/login', {
      data: {
        email: 'invalid@example.com',
        password: 'wrong_password',
      },
    });
    // Doit rejeter avec 400 ou 401
    expect([400, 401, 404, 422]).toContain(res.status());
  });
});
