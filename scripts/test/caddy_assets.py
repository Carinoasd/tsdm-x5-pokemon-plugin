#!/usr/bin/env python3
"""Exercise the deployed Caddy access rules with real HTTP requests.

Usage: python scripts/test/caddy_assets.py [--caddy /path/to/caddy]

Requires Caddy on PATH. The temporary server binds only to loopback, uses an
isolated document root, and is stopped after the checks. FrankenPHP and TLS are
removed from the test configuration; routing and access rules are unchanged.
"""

import argparse
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
from urllib.error import HTTPError, URLError
from urllib.request import ProxyHandler, build_opener


ROOT = Path(__file__).resolve().parents[2]
CADDYFILE = ROOT / "docker" / "config" / "Caddyfile"
HTTP = build_opener(ProxyHandler({}))

ALLOWED = [
    "source/plugin/pokemon/wasm/_game.js",
    "source/plugin/pokemon/wasm/_admin.js",
    "source/plugin/pokemon/wasm/_game_bg.wasm",
    "source/plugin/pokemon/wasm/_admin_bg.wasm",
    "source/plugin/pokemon/wasm/game.css",
    "source/plugin/pokemon/wasm/admin.css",
    "source/plugin/pokemon/wasm/lucide.min.js",
    "source/plugin/pokemon/wasm/snippets/runtime/src/js/eval.js",
    "source/plugin/pokemon/images/spm/1.gif",
    "source/plugin/pokemon/images/map.jpg",
    "source/plugin/pokemon/images/gender0.png",
    "source/plugin/pokemon/images/site/noavatar.svg",
    "source/plugin/pokemon/images/map.bmp",
]

BLOCKED = [
    "config/config_global.php",
    "uc_client/data/private.txt",
    "uc_server/data/private.txt",
    "data/restore/private.sql",
    "data/log/private.txt",
    "data/back3/private.sql",
    "data/sysdata/private.php",
    "source/class/class_core.php",
    "source/plugin/pokemon/install.php",
    "source/plugin/pokemon/api/battle.php",
    "source/plugin/pokemon/discuz_plugin_pokemon.json",
    "source/plugin/pokemon/images/private.txt",
    "source/plugin/pokemon/images/private.php",
    "source/plugin/pokemon/wasm/private.php",
    "source/plugin/pokemon/wasm/private.PHP",
    "source/plugin/pokemon/wasm/private.php.js",
    "source/plugin/pokemon/wasm/script.php/extra.js",
    "source/plugin/pokemon/images/script.php/image.png",
    "source/plugin/other/wasm/_game.js",
    "data/private.php",
    "static/private.php",
    "template/private.php",
    "attachment/private.php",
]


def request(url):
    try:
        with HTTP.open(url, timeout=2) as response:
            return response.status, response.read()
    except HTTPError as error:
        return error.code, error.read()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--caddy", default="caddy")
    args = parser.parse_args()
    caddy = shutil.which(args.caddy)
    if not caddy:
        parser.error("Caddy is required; install it or pass --caddy /path/to/caddy")

    with socket.socket() as listener:
        listener.bind(("127.0.0.1", 0))
        port = listener.getsockname()[1]

    with tempfile.TemporaryDirectory(prefix="pokemon-caddy-") as directory:
        temporary = Path(directory)
        document_root = temporary / "www"
        for path in ["index.php", *ALLOWED, *BLOCKED]:
            fixture = document_root / path
            fixture.parent.mkdir(parents=True, exist_ok=True)
            fixture.write_text("fixture:" + path, encoding="utf-8")

        config = CADDYFILE.read_text(encoding="utf-8")
        config = config.replace("\tfrankenphp\n", "")
        config = config.replace(
            "\torder php_server before file_server\n",
            "\tadmin off\n\tauto_https off\n",
        )
        config = config.replace("localhost {", f"http://127.0.0.1:{port} {{", 1)
        config = config.replace(
            "\troot * /app/public",
            f'\troot * "{document_root.as_posix()}"',
            1,
        )
        config = config.replace("\tphp_server\n", "")
        config = config.replace(
            "\ttls /caddy_certs/localhost.crt /caddy_certs/localhost.key\n", ""
        )
        config_path = temporary / "Caddyfile"
        config_path.write_text(config, encoding="utf-8")

        with (temporary / "caddy.log").open("w+", encoding="utf-8") as log:
            process = subprocess.Popen(
                [caddy, "run", "--config", str(config_path), "--adapter", "caddyfile"],
                stdout=log,
                stderr=log,
                creationflags=subprocess.CREATE_NO_WINDOW if os.name == "nt" else 0,
            )
            try:
                base_url = f"http://127.0.0.1:{port}/"
                for _ in range(100):
                    if process.poll() is not None:
                        log.seek(0)
                        raise RuntimeError("Caddy failed to start:\n" + log.read())
                    try:
                        request(base_url)
                        break
                    except (URLError, TimeoutError):
                        time.sleep(0.05)
                else:
                    raise RuntimeError("Caddy did not become ready")

                failures = []
                for path in ALLOWED:
                    status, body = request(base_url + path + "?v=regression")
                    if status != 200 or body != ("fixture:" + path).encode():
                        failures.append(f"asset {path}: HTTP {status}, body={body!r}")

                # Missing private paths must be denied before try_files rewrites
                # them to index.php. Encoded PHP names must also stay private.
                denied_requests = [
                    *BLOCKED,
                    "source/private/not-present.php",
                    "source/plugin/pokemon/wasm/script%2ephp/extra.js",
                    "source/plugin/pokemon/images/script%2ephp/image.png",
                    "source/plugin/pokemon/wasm/../api/battle.php",
                    "source/plugin/pokemon/images/../discuz_plugin_pokemon.json",
                    "config/missing-private.php",
                ]
                for path in denied_requests:
                    status, _ = request(base_url + path)
                    if status != 403:
                        failures.append(f"private path {path}: HTTP {status}, expected 403")

                if failures:
                    raise AssertionError("\n".join(failures))
                print(
                    f"PASS: {len(ALLOWED)} plugin assets served; "
                    f"{len(denied_requests)} private/PHP paths denied"
                )
            finally:
                process.terminate()
                try:
                    process.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    process.kill()
                    process.wait(timeout=5)


if __name__ == "__main__":
    main()
