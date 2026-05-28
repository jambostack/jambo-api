"""
JamboApi CMS — Python SDK
Client typé avec cache, retry automatique et pagination.

Usage:
    from jamboapi import JamboApiClient
    api = JamboApiClient(base_url="https://...", api_key="...")
    posts = api.list("posts", locale="fr", limit=10)
"""

from __future__ import annotations

import time
import json
from typing import Any, Optional, Dict, List
from urllib.parse import urlencode
from urllib.request import Request, urlopen
from urllib.error import HTTPError, URLError


class JamboApiError(Exception):
    """Erreur JamboApi."""
    def __init__(self, message: str, status_code: int = 0):
        super().__init__(message)
        self.status_code = status_code


class JamboApiClient:
    """Client HTTP typé pour JamboApi CMS."""

    def __init__(
        self,
        base_url: str,
        api_key: Optional[str] = None,
        timeout: int = 15,
        retries: int = 2,
        cache: bool = False,
        cache_ttl: int = 60,
    ):
        self.base_url = base_url.rstrip("/")
        self.api_key = api_key
        self.timeout = timeout
        self.retries = retries
        self.cache_enabled = cache
        self.cache_ttl = cache_ttl
        self._cache: Dict[str, tuple[float, Any]] = {}

    # ===== Content API =====

    def list(
        self, collection: str,
        locale: Optional[str] = None,
        status: Optional[str] = None,
        limit: int = 50,
        offset: int = 0,
    ) -> Dict[str, Any]:
        """Liste les entrées d'une collection."""
        params = {k: v for k, v in {
            "locale": locale, "status": status, "limit": limit, "offset": offset,
        }.items() if v is not None}
        return self._get(f"/api/collections/{collection}", params)

    def get_entry(self, collection: str, uuid: str) -> Optional[Dict[str, Any]]:
        """Obtient une entrée par UUID."""
        return self._get(f"/api/collections/{collection}/{uuid}")

    def create(self, collection: str, data: Dict[str, Any]) -> Dict[str, Any]:
        """Crée une entrée."""
        return self._post(f"/api/collections/{collection}", data)

    def update(self, collection: str, uuid: str, data: Dict[str, Any]) -> Dict[str, Any]:
        """Met à jour une entrée."""
        return self._put(f"/api/collections/{collection}/{uuid}", data)

    def delete(self, collection: str, uuid: str) -> bool:
        """Supprime (soft-delete) une entrée."""
        result = self._request("DELETE", f"/api/collections/{collection}/{uuid}")
        return result.get("deleted", False) is True

    def search(
        self, query: str,
        collection: Optional[str] = None,
        locale: Optional[str] = None,
        limit: int = 20,
    ) -> Dict[str, Any]:
        """Recherche full-text."""
        params = {k: v for k, v in {
            "q": query, "collection": collection, "locale": locale, "limit": limit,
        }.items() if v is not None}
        return self._get("/api/search", params)

    def list_media(
        self, search: Optional[str] = None, limit: int = 50, offset: int = 0,
    ) -> Dict[str, Any]:
        """Liste les médias."""
        params = {k: v for k, v in {
            "search": search, "limit": limit, "offset": offset,
        }.items() if v is not None}
        return self._get("/api/media", params)

    def media_url(self, uuid: str, **transforms: Any) -> str:
        """URL d'un média avec transformations."""
        qs = urlencode({k: v for k, v in transforms.items() if v is not None})
        return f"{self.base_url}/cdn/media/{uuid}?{qs}" if qs else f"{self.base_url}/cdn/media/{uuid}"

    def clear_cache(self) -> None:
        """Vide le cache."""
        self._cache.clear()

    # ===== HTTP Core =====

    def _request(self, method: str, path: str, body: Any = None) -> Any:
        url = self.base_url + path
        headers = {"Content-Type": "application/json"}
        if self.api_key:
            headers["Authorization"] = f"Bearer {self.api_key}"

        data = json.dumps(body).encode("utf-8") if body is not None else None
        last_error: Optional[Exception] = None

        for attempt in range(self.retries + 1):
            try:
                req = Request(url, data=data, headers=headers, method=method)
                with urlopen(req, timeout=self.timeout) as resp:
                    return json.loads(resp.read().decode("utf-8"))
            except HTTPError as e:
                raise JamboApiError(str(e), e.code)
            except (URLError, OSError) as e:
                last_error = e
                if attempt < self.retries:
                    time.sleep(min(1.0 * (2 ** attempt), 8.0))

        raise JamboApiError(f"Échec après {self.retries} tentatives: {last_error}")

    def _get(self, path: str, params: Dict[str, Any] = None) -> Any:
        query = f"?{urlencode(params)}" if params else ""
        cache_key = path + query

        if self.cache_enabled and cache_key in self._cache:
            ts, data = self._cache[cache_key]
            if time.time() - ts < self.cache_ttl:
                return data

        result = self._request("GET", path + query)
        if self.cache_enabled:
            self._cache[cache_key] = (time.time(), result)
        return result

    def _post(self, path: str, data: Dict[str, Any]) -> Any:
        return self._request("POST", path, data)

    def _put(self, path: str, data: Dict[str, Any]) -> Any:
        return self._request("PUT", path, data)
