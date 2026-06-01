---
title: REST API
description: Full reference for the Jambo API REST endpoints.
---

## Base URL

```
https://your-domain.com/api/{project-uuid}
```

## Authentication

All requests require a Bearer token:

```
Authorization: Bearer YOUR_API_TOKEN
```

## Endpoints

### List entries

```
GET /{collection}?locale=en&limit=20&offset=0&status=published
```

| Parameter | Default | Description |
|---|---|---|
| `locale` | project default | Content locale |
| `limit` | 20 | Results per page (max 100) |
| `offset` | 0 | Pagination offset |
| `status` | `published` | `draft` or `published` |

### Get single entry

```
GET /{collection}/{uuid}
```

### Response format

```json
{
  "data": [
    {
      "uuid": "a1b2c3d4-...",
      "locale": "en",
      "status": "published",
      "created_at": "2026-01-01T00:00:00+00:00",
      "updated_at": "2026-01-15T12:00:00+00:00",
      "title": "Example entry",
      "slug": "example-entry"
    }
  ],
  "meta": {
    "total": 42,
    "limit": 20,
    "offset": 0
  }
}
```
