# Jambo Cloud — Traefik setup

## Prerequisites
- A host with Docker + the Docker Engine API reachable by the Jambo app
  (`DOCKER_API_BASE`, e.g. expose `tcp://127.0.0.1:2375` locally, or mount
  the socket and use a socket-proxy in production).
- A wildcard DNS record `*.jambo.app` pointing at this host.
- DNS-provider API credentials for the ACME DNS challenge.

## Bring up Traefik
```bash
cd docker/traefik
export CF_DNS_API_TOKEN=...    # your DNS provider token
docker compose up -d
```
This creates the shared `jambo_cloud` network. Jambo app containers are
started by `DockerContainerOrchestrator` on the same network with Traefik
labels, so Traefik routes them automatically and provisions SSL.

## Enable the feature in Jambo
Set in `.env.local`:
```
JAMBO_CLOUD_ENABLED=true
JAMBO_CLOUD_BASE_DOMAIN=jambo.app
DOCKER_API_BASE=http://127.0.0.1:2375
JAMBO_PUBLIC_URL=https://your-cms-host
```

## Custom domains
Users add a domain in the Workbench → it returns a TXT record to create.
Once `verify` succeeds, the next deploy adds the domain to the container's
Traefik router rule and Traefik issues a certificate for it.
