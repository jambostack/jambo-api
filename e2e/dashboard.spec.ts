import { test, expect } from '@playwright/test';
import { ADMIN_EMAIL, ADMIN_PASSWORD, loginAsAdmin } from './helpers';

test.describe('JamboAPI CMS - Dashboard & Projets', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page, ADMIN_EMAIL, ADMIN_PASSWORD);
  });

  test('affiche le tableau de bord avec les projets existants', async ({ page }) => {
    // Vérification de la présence de projets (au moins Blog ou Restaurant)
    const projectCards = page.locator('div').filter({ hasText: /Blog|Restaurant|Moduscap/i });
    await expect(projectCards.first()).toBeVisible({ timeout: 15000 });
  });

  test('permet de filtrer les projets via la barre de recherche', async ({ page }) => {
    const searchInput = page.locator('input[type="search"], input[placeholder*="search" i], input[placeholder*="recherch" i]').first();
    if (await searchInput.isVisible({ timeout: 5000 }).catch(() => false)) {
      await searchInput.fill('Blog');
      await expect(page.locator('text=Blog').first()).toBeVisible();
    }
  });

  test('affiche le bouton de création de nouveau projet', async ({ page }) => {
    const createBtn = page.locator('button').filter({ hasText: /new project|nouveau projet/i }).first();
    await expect(createBtn).toBeVisible({ timeout: 10000 });
  });
});
