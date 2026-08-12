#!/usr/bin/env python3
"""
Drive the Settings form itself for the two v1.3.0 guards.

The unit tests cover the helpers; this covers the save handler, which is where a
guard is actually enforced and where a typo would silently let an open publish
page onto the internet.
"""
import json
import subprocess
from playwright.sync_api import sync_playwright

BASE = "http://127.0.0.1:8922"
WP = ["php", "wp-cli.phar", "--path=site"]

npass = nfail = 0


def chk(label, got, want):
    global npass, nfail
    if got == want:
        npass += 1
        print(f"  PASS  {label}")
    else:
        nfail += 1
        print(f"  FAIL  {label} (got {got!r}, wanted {want!r})")


def settings():
    out = subprocess.run(WP + ["option", "get", "vpb_settings", "--format=json"],
                         capture_output=True, text=True, cwd=".").stdout.strip()
    return json.loads(out)


def existing_page_slug():
    out = subprocess.run(WP + ["post", "list", "--post_type=page",
                               "--post_status=publish", "--field=post_name",
                               "--posts_per_page=1"],
                         capture_output=True, text=True, cwd=".").stdout.strip()
    return out.splitlines()[0]


with sync_playwright() as p:
    b = p.chromium.launch()
    pg = b.new_context(viewport={"width": 1280, "height": 900}).new_page()

    pg.goto(f"{BASE}/wp-login.php", wait_until="domcontentloaded")
    pg.fill("#user_login", "admin")
    pg.fill("#user_pass", "admin123")
    pg.click("#wp-submit")
    pg.wait_for_load_state("networkidle")

    S = f"{BASE}/wp-admin/admin.php?page=vimeo-page-builder-settings"

    # ---------------------------------------------------------------- guard 1
    print("a short address with no passcode:")
    pg.goto(S, wait_until="networkidle")
    pg.fill("#public_passcode", "")
    pg.fill("#public_path", "open-door")
    pg.click("#submit")
    pg.wait_for_load_state("networkidle")

    body = pg.content()
    chk("it refuses and says why",
        "short address was not saved" in body, True)
    chk("the address is NOT stored", settings()["public_path"], "")
    chk("so nothing is served there",
        pg.request.get(f"{BASE}/open-door/").status, 404)

    # ---------------------------------------------------------------- guard 2
    print("\na short address that collides with a real page:")
    slug = existing_page_slug()
    pg.goto(S, wait_until="networkidle")
    pg.fill("#public_passcode", "Armadillo")
    pg.fill("#public_path", slug)
    pg.click("#submit")
    pg.wait_for_load_state("networkidle")

    body = pg.content()
    chk("it warns loudly", "Careful:" in body, True)
    chk("it names the page being shadowed", slug in body, True)

    # ---------------------------------------------------------------- normal
    print("\nsetting a sensible one:")
    pg.goto(S, wait_until="networkidle")
    pg.fill("#public_passcode", "Armadillo")
    pg.fill("#public_path", "  /Video Builder/  ")   # sloppy input on purpose
    pg.click("#submit")
    pg.wait_for_load_state("networkidle")

    chk("tidied into a slug", settings()["public_path"], "video-builder")
    chk("passcode kept", settings()["public_passcode"], "Armadillo")
    chk("the address works", pg.request.get(f"{BASE}/video-builder/").status, 200)
    chk("no collision warning this time", "Careful:" in pg.content(), False)

    # -------------------------------------------------- removing it again
    print("\nclearing it:")
    pg.fill("#public_path", "")
    pg.click("#submit")
    pg.wait_for_load_state("networkidle")
    chk("stored as empty", settings()["public_path"], "")
    chk("address goes dead", pg.request.get(f"{BASE}/video-builder/").status, 404)
    chk("the long link still works",
        pg.request.get(f"{BASE}/?vpb={settings()['public_key']}").status, 200)

    # put it back for the demo screenshots
    pg.fill("#public_path", "video-builder")
    pg.click("#submit")
    pg.wait_for_load_state("networkidle")

    b.close()

print(f"\n{npass} passed, {nfail} failed")
