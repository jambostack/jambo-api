---
title: Quick Start
description: Create your first collection and query your content in 5 minutes.
---

## 1. Create a project

Log in to your Jambo API admin, click **New Project**, give it a name.

## 2. Add a collection

In your project, go to **Collections → New Collection**.
Name it `Articles`, add fields: `title` (Text), `body` (Rich Text), `slug` (Slug).

## 3. Add content

Navigate to **Content → Articles → New Entry**, fill in the fields, click **Publish**.

## 4. Get an API token

Go to **Settings → API Access → New Token**. Copy the token.

## 5. Query the API

```bash
curl https://your-domain.com/api/{project-uuid}/articles?locale=en \
  -H "Authorization: Bearer YOUR_TOKEN"
```

Response:

```json
{
  "data": [
    {
      "uuid": "...",
      "locale": "en",
      "status": "published",
      "title": "Hello World",
      "slug": "hello-world",
      "body": "<p>...</p>"
    }
  ],
  "meta": { "total": 1, "limit": 20, "offset": 0 }
}
```
