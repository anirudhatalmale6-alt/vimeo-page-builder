#!/usr/bin/env python3
"""
v1.3.0 short address, driven over real HTTP the way the employee's phone will.

The unit tests prove the logic; this proves the wiring - cookies actually set,
headers actually sent, and the secret key genuinely absent from the page.
"""
import re
import subprocess
import urllib.request
import urllib.parse
import urllib.error
import http.cookiejar

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


def wp(*args):
    return subprocess.run(WP + list(args), capture_output=True, text=True,
                          cwd=".").stdout.strip()


def setopt(**kw):
    import json
    cur = json.loads(wp("option", "get", "vpb_settings", "--format=json"))
    cur.update(kw)
    subprocess.run(WP + ["option", "update", "vpb_settings", json.dumps(cur),
                         "--format=json"], capture_output=True, text=True, cwd=".")


def fetch(url, data=None, jar=None, headers=None):
    """Returns (status, body, headers, jar)."""
    if jar is None:
        jar = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
    body = urllib.parse.urlencode(data).encode() if data else None
    req = urllib.request.Request(url, data=body, headers=headers or {})
    try:
        r = opener.open(req, timeout=20)
        return r.status, r.read().decode("utf-8", "replace"), dict(r.headers), jar
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace"), dict(e.headers), jar


def clear_limits():
    wp("transient", "delete", "--all")


# ------------------------------------------------------------------ setup
KEY = wp("eval", "$s=vpb_settings(); echo $s['public_key'];")
setopt(public_enabled=1, public_path="video-builder",
       public_passcode="Armadillo", template_id=5, verify_vimeo=0,
       post_status="draft")
clear_limits()

print("a stranger who finds the address:")
st, body, hdrs, _ = fetch(f"{BASE}/video-builder/")
chk("the page loads", st, 200)
chk("it asks for a passcode", "Passcode" in body, True)
chk("it does NOT mention Vimeo", "Vimeo" in body, False)
chk("it does NOT show a build button", "BUILD" in body, False)
chk("it does NOT leak the secret key", KEY in body, False)
chk("kept out of Google", "noindex" in hdrs.get("X-Robots-Tag", ""), True)
chk("referrer suppressed", hdrs.get("Referrer-Policy"), "no-referrer")
# A shared cache keeping a copy of this page is what broke the 12-hour sign-in
# on the live site, so the header is asserted, not assumed.
chk("no shared cache may keep a copy",
    "no-store" in hdrs.get("Cache-Control", ""), True)

print("\ntyping it wrong:")
st, body, _, _ = fetch(f"{BASE}/video-builder/", {"vpb_passcode": "Rhino"})
chk("rejected", "Not right" in body, True)
chk("still no builder", "Vimeo" in body, False)

print("\ntyping it right:")
st, body, _, jar = fetch(f"{BASE}/video-builder/", {"vpb_passcode": "Armadillo"})
chk("the builder appears", "Vimeo video ID" in body, True)
chk("a gate cookie was set", any(c.name == "vpb_gate" for c in jar), True)
cookie = [c for c in jar if c.name == "vpb_gate"][0]
chk("cookie is httponly", cookie.has_nonstandard_attr("HttpOnly"), True)
chk("the secret key is NOT written into the page", KEY in body, False)

print("\ntyping it right, on a phone, in lowercase:")
_, body, _, jar2 = fetch(f"{BASE}/Video-Builder", {"vpb_passcode": " armadillo "})
chk("capitalised URL + sloppy passcode still works", "Vimeo video ID" in body, True)

print("\ncoming back with the cookie:")
st, body, _, _ = fetch(f"{BASE}/video-builder/", jar=jar)
chk("straight to the builder, no passcode again", "Vimeo video ID" in body, True)

print("\nbuilding with only the cookie:")
nonce = re.search(r"NONCE\s*=\s*\"([^\"]+)\"", body).group(1)
st, body, _, _ = fetch(f"{BASE}/wp-admin/admin-ajax.php", {
    "action": "vpb_public_build", "key": "", "nonce": nonce,
    "vimeo": "1188503927", "company": "", "force": "1"}, jar=jar)
chk("the build succeeds", '"success":true' in body, True)
chk("a page was created", '"status":"built"' in body, True)

print("\nbuilding with neither cookie nor key:")
st, body, _, _ = fetch(f"{BASE}/wp-admin/admin-ajax.php", {
    "action": "vpb_public_build", "key": "", "nonce": nonce,
    "vimeo": "1188503927", "force": "1"})
chk("refused", st, 403)

print("\nthe long key address is untouched:")
st, body, _, _ = fetch(f"{BASE}/?vpb={KEY}")
chk("still goes straight to the builder", "Vimeo video ID" in body, True)
chk("and still asks for the passcode there", 'id="pc"' in body, True)

print("\nanything else on the site:")
st, _, _, _ = fetch(f"{BASE}/video-builder-2/")
chk("a near-miss URL is a normal 404", st, 404)

print("\nguessing the passcode:")
clear_limits()
for i in range(10):
    fetch(f"{BASE}/video-builder/", {"vpb_passcode": f"guess{i}"})
st, body, _, _ = fetch(f"{BASE}/video-builder/", {"vpb_passcode": "Armadillo"})
chk("locked out after 10 wrong tries", "Too many tries" in body, True)
chk("even the correct passcode is refused while locked",
    "Vimeo video ID" in body, False)
clear_limits()
st, body, _, _ = fetch(f"{BASE}/video-builder/", {"vpb_passcode": "Armadillo"})
chk("works again once the hour is up", "Vimeo video ID" in body, True)

print("\nwith the short address switched off:")
setopt(public_path="")
st, _, _, _ = fetch(f"{BASE}/video-builder/")
chk("the address goes dead", st, 404)
setopt(public_path="video-builder")

# ------------------------------------------------------------------ tidy up
setopt(verify_vimeo=1, post_status="publish")
clear_limits()

print(f"\n{npass} passed, {nfail} failed")
