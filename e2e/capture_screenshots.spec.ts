import { test, expect, Page } from '@playwright/test';
import { ADMIN_EMAIL, ADMIN_PASSWORD, loginAsAdmin } from './helpers';
import * as path from 'path';

const DOC_SCREENSHOTS_DIR = path.resolve(__dirname, '../../jambo-doc/src/assets/screenshots');

async function cleanPage(page: Page) {
  await page.addStyleTag({
    content: `
      .sf-toolbar, .sf-toolbarreset, #sfToolbarMainContent { display: none !important; }
      body { overflow-x: hidden !important; }
    `
  }).catch(() => {});
}

test.describe('Capture All Documentation Screenshots', () => {
  test.setTimeout(180000);
  test.use({ viewport: { width: 1440, height: 900 } });

  test('Capture all 15 screenshots cleanly', async ({ page }) => {
    // 1. login.png
    await page.goto('/login');
    await expect(page.locator('#email')).toBeVisible({ timeout: 15000 });
    await cleanPage(page);
    await page.waitForTimeout(1000);
    await page.screenshot({ path: path.join(DOC_SCREENSHOTS_DIR, 'login.png') });

    // Login as admin
    await loginAsAdmin(page, ADMIN_EMAIL, ADMIN_PASSWORD);
    await page.waitForTimeout(1500);

    // 2. dashboard.png
    await page.goto('/');
    await page.waitForTimeout(2000);
    await cleanPage(page);
    await page.screenshot({ path: path.join(DOC_SCREENSHOTS_DIR, 'dashboard.png') });

    // 3. project-settings.png
    await page.goto('/projects/1/settings/project');
    await page.waitForTimeout(2000);
    await cleanPage(page);
    await page.screenshot({ path: path.join(DOC_SCREENSHOTS_DIR, 'project-settings.png') });

    // 4. api-tokens.png (API Access & Tokens)
    await page.goto('/projects/1/settings/api-access');
    await page.waitForTimeout(2000);
    await cleanPage(page);
    await page.screenshot({ path: path.join(DOC_SCREENSHOTS_DIR, 'api-tokens.png') });

    // 5. media-library.png
    await page.goto('/projects/1/assets');
    await page.waitForTimeout(2000);
    await cleanPage(page);
    await page.screenshot({ path: path.join(DOC_SCREENSHOTS_DIR, 'media-library.png') });

    // 6. users.png
    await page.goto('/user-management/users');
    await page.waitForTimeout(2000);
    await cleanPage(page);
    await page.screenshot({ path: path.join(DOC_SCREENSHOTS_DIR, 'users.png') });

    // 7. content-list.png
    await page.goto('/projects/1/collections/1');
    await page.waitForTimeout(2500);
    await cleanPage(page);
    await page.screenshot({ path: path.join(DOC_SCREENSHOTS_DIR, 'content-list.png') });

    // 8. content-edit.png
    await page.goto('/projects/1/collections/1/content/1/edit');
    await page.waitForTimeout(2500);
    await cleanPage(page);
    await page.screenshot({ path: path.join(DOC_SCREENSHOTS_DIR, 'content-edit.png') });

    // 9. live-preview.png
    const previewBtn = page.locator('button:has-text("Preview"), a:has-text("Preview"), button:has-text("Aperçu")').first();
    if (await previewBtn.isVisible().catch(() => false)) {
      await previewBtn.click().catch(() => {});
      await page.waitForTimeout(1500);
    }
    await cleanPage(page);
    await page.screenshot({ path: path.join(DOC_SCREENSHOTS_DIR, 'live-preview.png') });

    // 10. flows-doc-page.png (Automations list page)
    await page.goto('/projects/1/settings/automations');
    await page.waitForTimeout(2000);
    await cleanPage(page);
    await page.screenshot({ path: path.join(DOC_SCREENSHOTS_DIR, 'flows-doc-page.png') });

    // 11. flow-builder.png (Open the pipeline with DAG nodes)
    const workflowBtn = page.locator('table tr button').nth(1);
    if (await workflowBtn.isVisible({ timeout: 5000 }).catch(() => false)) {
      await workflowBtn.click();
      await page.waitForTimeout(3000);
    } else {
      const fbBtn = page.locator('button:has-text("Flow Builder"), button:has-text("Builder")').first();
      if (await fbBtn.isVisible().catch(() => false)) {
        await fbBtn.click();
        await page.waitForTimeout(2500);
      }
    }
    await cleanPage(page);
    await page.screenshot({ path: path.join(DOC_SCREENSHOTS_DIR, 'flow-builder.png') });

    // 12. schema-builder.png (Studio Schema tab)
    await page.goto('/projects/1/settings/studio');
    await page.waitForTimeout(2500);
    await cleanPage(page);
    await page.screenshot({ path: path.join(DOC_SCREENSHOTS_DIR, 'schema-builder.png') });

    // 13. workflows.png (Studio Workflow Tab)
    const workflowTab = page.locator('button:has-text("Workflow"), [role="tab"]:has-text("Workflow")').first();
    if (await workflowTab.isVisible({ timeout: 5000 }).catch(() => false)) {
      await workflowTab.click();
      await page.waitForTimeout(1500);
    }
    await cleanPage(page);
    await page.screenshot({ path: path.join(DOC_SCREENSHOTS_DIR, 'workflows.png') });

    // 14. ai-assistant.png (Studio Chat Tab / AI Assistant)
    const chatTab = page.locator('button:has-text("Chat"), [role="tab"]:has-text("Chat"), button:has-text("Assistant")').first();
    if (await chatTab.isVisible({ timeout: 5000 }).catch(() => false)) {
      await chatTab.click();
      await page.waitForTimeout(1500);
    }
    await cleanPage(page);
    await page.screenshot({ path: path.join(DOC_SCREENSHOTS_DIR, 'ai-assistant.png') });

    // 15. ai-agent.png (MCP Access / AI Agent configuration)
    await page.goto('/projects/1/settings/mcp-access');
    await page.waitForTimeout(2000);
    await cleanPage(page);
    await page.screenshot({ path: path.join(DOC_SCREENSHOTS_DIR, 'ai-agent.png') });
  });
});
