---
title: AI Providers
description: Configure AI providers for the Jambo Studio.
---

## Supported providers

| Provider | Models |
|---|---|
| **OpenAI** | GPT-4o, GPT-4o mini |
| **Anthropic** | Claude Sonnet, Opus, Haiku |
| **DeepSeek** | DeepSeek Chat, Reasoner |
| **Ollama** | Any locally installed model |

## Configuration

Go to **Admin → Settings → AI Studio** and enable the providers you want to use. API keys are stored encrypted in the database — no environment variables needed.

## Ollama (local)

For Ollama, set the server URL (default: `http://localhost:11434`) instead of an API key. This allows running AI features completely offline.
