---
title: GraphQL
description: Query your content with GraphQL.
---

## Endpoint

```
POST /api/{project-uuid}/graphql
```

## Example query

```graphql
query {
  articles(locale: "en", status: "published") {
    uuid
    title
    slug
    created_at
  }
}
```

## Introspection

Use any GraphQL client (Insomnia, Postman, GraphiQL) with the endpoint above to explore your schema automatically.
