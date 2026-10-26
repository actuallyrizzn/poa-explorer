"""Stdlib HTTP client for https://poa.explorer.zero1.network JSON."""

from __future__ import annotations

import json
import urllib.error
import urllib.parse
import urllib.request
from typing import Any


class PoaExplorerError(RuntimeError):
    def __init__(self, message: str, payload: dict[str, Any] | None = None) -> None:
        super().__init__(message)
        self.payload = payload or {}


class PoaExplorerClient:
    def __init__(
        self,
        base_url: str = "https://poa.explorer.zero1.network",
        timeout: float = 20.0,
    ) -> None:
        self.base_url = base_url.rstrip("/")
        self.timeout = timeout

    def status(self) -> dict[str, Any]:
        return self.get("/api/status.php")

    def recent_txs(self) -> dict[str, Any]:
        return self.get("/api/txs.php")

    def tx(self, tx_id: str) -> dict[str, Any]:
        return self.get("/api/tx.php", {"id": tx_id})

    def address(self, addr: str) -> dict[str, Any]:
        return self.get("/api/address.php", {"a": addr})

    def anchors(self) -> dict[str, Any]:
        return self.get("/api/anchors.php")

    def epochs(self) -> dict[str, Any]:
        return self.get("/api/epochs.php")

    def challenges(self) -> dict[str, Any]:
        return self.get("/api/challenges.php")

    def changelog(self) -> dict[str, Any]:
        return self.get("/api/changelog.php")

    def close(self, epoch: str | int) -> dict[str, Any]:
        return self.get("/api/close.php", {"epoch": str(epoch)})

    def pending(self, query: dict[str, str] | None = None) -> dict[str, Any]:
        return self.get("/api/pending.php", query)

    def get(self, path: str, query: dict[str, str] | None = None) -> dict[str, Any]:
        url = self.base_url + path
        if query:
            url += "?" + urllib.parse.urlencode(query)
        req = urllib.request.Request(
            url,
            headers={
                "Accept": "application/json",
                "User-Agent": "PoaExplorerClient/0.3 Python",
            },
        )
        try:
            with urllib.request.urlopen(req, timeout=self.timeout) as resp:
                raw = resp.read().decode("utf-8")
        except urllib.error.HTTPError as exc:
            raw = exc.read().decode("utf-8", errors="replace")
            payload = _decode(raw)
            raise PoaExplorerError(str(payload.get("error") or exc), payload) from exc
        except urllib.error.URLError as exc:
            raise PoaExplorerError("explorer unreachable: " + url) from exc
        payload = _decode(raw)
        if payload.get("ok") is False:
            raise PoaExplorerError(str(payload.get("error") or "request failed"), payload)
        return payload


def _decode(raw: str) -> dict[str, Any]:
    data = json.loads(raw)
    if not isinstance(data, dict):
        raise PoaExplorerError("explorer returned non-object JSON")
    return data
