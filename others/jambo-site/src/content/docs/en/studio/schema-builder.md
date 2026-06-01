---
title: Schema Builder
description: Design your content schema by chatting with an AI.
---

## Overview

The Jambo AI Studio lets you design and modify your content schema by describing what you need in plain language. No code required.

## How it works

1. Go to **Project → Studio → Schema Builder**
2. Describe your collections in the chat: _"Create a blog with articles, categories, and authors"_
3. Review the proposed schema
4. Click **Apply** to create the collections
5. Click **Save** to persist to the database

## Naming conventions

Jambo enforces strict naming rules automatically:

- Collection names: **PascalCase plural** (`BlogPosts`, `Products`)
- Singleton names: **PascalCase singular** (`Hero`, `Config`)
- Field names: **camelCase** (`publishedAt`, `featuredImage`)
- Slugs: **snake_case** (`blog_posts`, `published_at`)

## Supported field types

`text` · `longtext` · `richtext` · `slug` · `email` · `password` · `number` · `decimal` · `boolean` · `date` · `datetime` · `time` · `color` · `json` · `enumeration` · `media` · `relation`
