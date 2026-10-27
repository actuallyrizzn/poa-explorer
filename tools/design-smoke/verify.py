#!/usr/bin/env python3
"""PoA explorer visual + functional smoke. Bounded php -S; always torn down.

  python3 tools/design-smoke/verify.py
"""
from __future__ import annotations

import shutil
import socket
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from contextlib import contextmanager
from pathlib import Path

OUT = Path(__file__).resolve().parent / "out"
REPO = Path(__file__).resolve().parents[2]
PUBLIC = REPO / "public"
DESKTOP = {"width": 1280, "height": 800}
MOBILE = {"width": 390, "height": 844}
KODA_ADDR = "poa1AoECFMm8ge4C9dx8x7LzBxvaByGsZagiT6HY1GYwBa5W"
SAMPLE_TX = "54187e2ad9d4bf5444b6bc705598f343fbf26b4f1c0dda2a7fd943fec537d944"


def free_port() -> int:
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as sock:
        sock.bind(("127.0.0.1", 0))
        return int(sock.getsockname()[1])


def wait_http(url: str, timeout: float = 12.0) -> None:
    deadline = time.time() + timeout
    last: Exception | None = None
    while time.time() < deadline:
        try:
            urllib.request.urlopen(url, timeout=2.0)
            return
        except (urllib.error.URLError, TimeoutError, OSError) as exc:
            last = exc
            time.sleep(0.15)
    raise RuntimeError(f"php -S never answered {url}: {last}")


def stop_php(proc: subprocess.Popen[bytes]) -> None:
    if proc.poll() is not None:
        return
    proc.terminate()
    try:
        proc.wait(timeout=3)
    except subprocess.TimeoutExpired:
        proc.kill()
        proc.wait(timeout=3)


@contextmanager
def php_server():
    php = shutil.which("php")
    if not php:
        raise RuntimeError("php not on PATH")
    port = free_port()
    proc = subprocess.Popen(
        [php, "-S", f"127.0.0.1:{port}", "-t", str(PUBLIC)],
        cwd=str(PUBLIC),
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )
    try:
        wait_http(f"http://127.0.0.1:{port}/assets/app.css")
        yield f"http://127.0.0.1:{port}"
    finally:
        stop_php(proc)


def shot(page, name: str) -> None:
    dest = OUT / f"{name}.png"
    page.screenshot(path=str(dest), full_page=False)
    print(f"shot {dest}")


def main() -> int:
    try:
        from playwright.sync_api import sync_playwright
    except ImportError:
        print("python playwright missing", file=sys.stderr)
        return 1

    OUT.mkdir(parents=True, exist_ok=True)

    with php_server() as base:
        with sync_playwright() as p:
            browser = p.chromium.launch(headless=True)
            try:
                page = browser.new_page(viewport=DESKTOP)
                page.route("**/*fonts.googleapis.com/**", lambda route: route.abort())
                page.route("**/*fonts.gstatic.com/**", lambda route: route.abort())
                page.goto(base + "/index.php", wait_until="domcontentloaded", timeout=45000)
                page.get_by_text("Checkpoint height", exact=False).first.wait_for(timeout=45000)
                body = page.inner_text("body")
                if "checkpoint height" not in body.lower():
                    shot(page, "home-debug")
                    raise AssertionError("home missing checkpoint height: " + repr(body[:800]))
                if "wallet" in body.lower() and "no wallet" not in body.lower():
                    raise AssertionError("unexpected wallet copy")
                shot(page, "home-desktop")

                page.set_viewport_size(MOBILE)
                page.goto(base + "/index.php", wait_until="domcontentloaded", timeout=45000)
                shot(page, "home-mobile")

                page.set_viewport_size(DESKTOP)
                addr = base + "/address.php?a=" + urllib.parse.quote(KODA_ADDR)
                page.goto(addr, wait_until="domcontentloaded", timeout=45000)
                abody = page.inner_text("body")
                if "517943" not in abody and "Ledger balance" not in abody:
                    raise AssertionError("address page missing balance")
                if "grading and pending" not in abody.lower() and "pending assignments" not in abody.lower():
                    shot(page, "address-debug")
                    raise AssertionError("address page missing grading panel")
                if "settled via answer txid" not in abody.lower():
                    shot(page, "address-debug")
                    raise AssertionError("address page missing answer-txid join")
                shot(page, "address-desktop")

                page.set_viewport_size(MOBILE)
                page.goto(addr, wait_until="domcontentloaded", timeout=45000)
                shot(page, "address-mobile")

                page.set_viewport_size(DESKTOP)
                tx = base + "/tx.php?id=" + SAMPLE_TX
                page.goto(tx, wait_until="domcontentloaded", timeout=45000)
                tbody = page.inner_text("body")
                if "challenge" not in tbody.lower() and "Transaction" not in tbody:
                    raise AssertionError("tx page empty")
                shot(page, "tx-desktop")

                page.goto(base + "/checkpoint.php", wait_until="domcontentloaded", timeout=45000)
                shot(page, "checkpoint-desktop")

                page.set_viewport_size({"width": 1280, "height": 1400})
                page.goto(base + "/pending.php", wait_until="domcontentloaded", timeout=90000)
                pbody = page.inner_text("body")
                if "without panel" not in pbody.lower():
                    shot(page, "pending-debug")
                    raise AssertionError("pending page missing without-panel count")
                if "with seals" not in pbody.lower():
                    shot(page, "pending-debug")
                    raise AssertionError("pending page missing with-seals count")
                if "snapshot" not in pbody.lower() and "closed" not in pbody.lower():
                    raise AssertionError("pending page missing snapshot/closed caveat")
                if " · " not in pbody and "0 seals" not in pbody.lower():
                    shot(page, "pending-debug")
                    raise AssertionError("pending table missing seal scores")
                shot(page, "pending-desktop")
                page.set_viewport_size(MOBILE)
                page.goto(base + "/pending.php", wait_until="domcontentloaded", timeout=90000)
                shot(page, "pending-mobile")
                page.set_viewport_size({"width": 1280, "height": 1400})
                page.goto(
                    base + "/pending.php?a=" + urllib.parse.quote(KODA_ADDR),
                    wait_until="domcontentloaded",
                    timeout=90000,
                )
                kpend = page.inner_text("body")
                if "showing 1 of 1" in kpend.lower():
                    shot(page, "pending-koda-debug")
                    raise AssertionError("account pending filter still author-only (expected txid join)")
                shot(page, "pending-koda-desktop")
                page.set_viewport_size(DESKTOP)

                page.goto(base + "/search.php?q=" + urllib.parse.quote(KODA_ADDR), wait_until="domcontentloaded", timeout=45000)
                if "address.php" not in page.url:
                    raise AssertionError("search did not route address")

                page.set_viewport_size(DESKTOP)
                page.goto(base + "/docs.php", wait_until="domcontentloaded", timeout=45000)
                dbody = page.inner_text("body")
                if "json api" not in dbody.lower():
                    shot(page, "docs-debug")
                    raise AssertionError("docs page missing API section: " + repr(dbody[:800]))
                if "/api/hosted" not in dbody.lower() and "never /api/hosted" not in dbody.lower():
                    pass
                if "no wallet" not in dbody.lower():
                    raise AssertionError("docs missing no-wallet bound")
                if "sanctum mcp" not in dbody.lower() or "git clone https://github.com/sanctumos/smcp" not in dbody.lower():
                    shot(page, "docs-debug")
                    raise AssertionError("docs missing SMCP install path")
                if "mcp/server.py" not in dbody.lower() and "standard mcp" not in dbody.lower():
                    shot(page, "docs-debug")
                    raise AssertionError("docs missing stdio MCP server")
                if "0.3.1" not in dbody:
                    raise AssertionError("docs missing API version")
                shot(page, "docs-desktop")
                page.set_viewport_size(MOBILE)
                page.goto(base + "/docs.php", wait_until="domcontentloaded", timeout=45000)
                shot(page, "docs-mobile")
            finally:
                browser.close()
    print("ok")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
