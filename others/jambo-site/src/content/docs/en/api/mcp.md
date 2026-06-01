---
title: MCP Server
description: Connect AI agents directly to your Jambo API via Model Context Protocol.
---

## What is MCP?

Model Context Protocol (MCP) lets AI agents (Claude, Cursor, custom agents) interact directly with your CMS — read content, create entries, manage schema — without writing custom integrations.

## Endpoint

```
https://your-domain.com/mcp
```

## Tool categories

| Category | Description |
|---|---|
| **Exploration** | Browse projects, collections, schema |
| **Content** | List, create, update, delete entries |
| **Schema** | Manage collections and fields |
| **Media** | Upload and query assets |
| **End Users** | Manage front-end users |
| **AI Tools** | Content generation, search, versioning |

## Connect Claude Desktop

Add to your `claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "jambo": {
      "url": "https://your-domain.com/mcp",
      "headers": {
        "Authorization": "Bearer YOUR_MCP_TOKEN"
      }
    }
  }
}
```

Create an MCP token in **Settings → MCP Access**.
