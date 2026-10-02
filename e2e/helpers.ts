import { Page, expect } from '@playwright/test';

export const ADMIN_EMAIL = 'admin@jambostack.site';
export const ADMIN_PASSWORD = 'admin123';

/**
 * Connecte l'administrateur sur JamboAPI CMS via le formulaire de login
 */
export async function loginAsAdmin(page: Page, email = ADMIN_EMAIL, password = ADMIN_PASSWORD) {
  await page.goto('/login');
  
  await expect(page.locator('#email')).toBeVisible({ timeout: 10000 });
  await page.locator('#email').fill(email);
  await page.locator('#password').fill(password);
  
  await page.locator('button[type="submit"]').click();
  
  // Attente de redirection vers le dashboard ou confirmation de session
  await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 15000 });
}
