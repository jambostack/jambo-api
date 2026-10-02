import { test, expect } from '@playwright/test';
import { ADMIN_EMAIL, ADMIN_PASSWORD, loginAsAdmin } from './helpers';

test.describe('JamboAPI CMS - Authentification', () => {
  test('redirige les utilisateurs non connectés vers /login', async ({ page }) => {
    await page.goto('/');
    await expect(page).toHaveURL(/\/login/);
  });

  test('affiche correctement le formulaire de connexion et la marque JamboAPI', async ({ page }) => {
    await page.goto('/login');

    // Titre de l'onglet
    await expect(page).toHaveTitle(/(Welcome|Jambo|Log in|Sign in|Connexion)/i);

    // Champs du formulaire
    const emailInput = page.locator('#email');
    const passwordInput = page.locator('#password');
    const submitBtn = page.locator('button[type="submit"]');

    await expect(emailInput).toBeVisible({ timeout: 10000 });
    await expect(passwordInput).toBeVisible();
    await expect(submitBtn).toBeVisible();
  });

  test('affiche une alerte d\'erreur lors d\'identifiants invalides', async ({ page }) => {
    await page.goto('/login');

    const emailInput = page.locator('#email');
    await expect(emailInput).toBeVisible({ timeout: 10000 });
    await emailInput.fill('bad_user@example.com');
    await page.locator('#password').fill('mauvais_mot_de_passe');
    await page.locator('button[type="submit"]').click();

    // L'alerte d'erreur doit s'afficher
    const alertError = page.locator('.bg-red-50, .text-red-700, .alert-error, [role="alert"]').first();
    await expect(alertError).toBeVisible({ timeout: 10000 });
  });

  test('connecte avec succès l\'administrateur et redirige vers le dashboard', async ({ page }) => {
    await loginAsAdmin(page, ADMIN_EMAIL, ADMIN_PASSWORD);

    // Vérifier que l'on n'est plus sur /login
    expect(page.url()).not.toContain('/login');
  });
});
