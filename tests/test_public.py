#!/usr/bin/env python3
"""Security behaviour of the no-login build page."""
import re, sys, urllib.parse, requests

BASE = "http://127.0.0.1:8922"

def get_key():
    import subprocess
    B = "/tmp/claude-1003/-home-freelancer/14fa1bf1-afdd-4825-90e5-256f4791f33a/scratchpad/vimeobuilder"
    out = subprocess.run(
        ["php", f"{B}/wp-cli.phar", "eval",
         '$s=vpb_settings(); echo $s["public_key"];', "--allow-root"],
        cwd=f"{B}/site", capture_output=True, text=True)
    return out.stdout.strip()

key = get_key()
print("key length:", len(key))
ok = lambda b: "PASS" if b else "*** FAIL ***"

s = requests.Session()

# 1. no key at all -> normal site, not the tool
r = s.get(f"{BASE}/", timeout=15)
print(" 1", ok("BUILD" not in r.text or "vpb" not in r.text.lower()),
      "- homepage without a key does not expose the tool")

# 2. wrong key -> indistinguishable from a normal page, no hint the tool exists
r = s.get(f"{BASE}/?vpb=wrongkeywrongkeywrongkey", timeout=15)
print(" 2", ok("Build A Video Page" not in r.text),
      f"- wrong key gives nothing away (HTTP {r.status_code})")

# 3. correct key -> the tool renders
r = s.get(f"{BASE}/?vpb={key}", timeout=15)
page = r.text
print(" 3", ok("Build A Video Page" in page), "- correct key renders the tool")

# 4. search engines are told to stay away, both ways
hdr = r.headers.get("X-Robots-Tag", "")
meta = bool(re.search(r'<meta name="robots" content="[^"]*noindex', page))
print(" 4", ok("noindex" in hdr and meta),
      f"- noindex header ({hdr!r}) and meta tag ({meta})")

# 5. referrer policy so the key does not leak to Vimeo etc via Referer
print(" 5", ok(r.headers.get("Referrer-Policy") == "no-referrer"),
      f"- Referrer-Policy: {r.headers.get('Referrer-Policy')!r}")

# 6. build through the public endpoint with the right key
nonce = re.search(r'NONCE\s*=\s*"([^"]+)"', page).group(1)
def build(vid, k=key, pc="", nonce=nonce):
    return s.post(f"{BASE}/wp-admin/admin-ajax.php", timeout=45, data={
        "action": "vpb_public_build", "key": k, "nonce": nonce,
        "vimeo": vid, "company": "Key Financial Inc", "passcode": pc, "force": "0",
    })

r6 = build("1084537")
j6 = r6.json()
print(" 6", ok(j6.get("success")), "- valid key builds:",
      (j6.get("data") or {}).get("title") or j6)

# 7. wrong key on the AJAX endpoint is rejected even with a valid nonce
r7 = build("22439234", k="wrongkey")
print(" 7", ok(r7.status_code == 403 and not r7.json().get("success")),
      f"- wrong key rejected at the endpoint (HTTP {r7.status_code})")

# 8. missing nonce rejected
r8 = s.post(f"{BASE}/wp-admin/admin-ajax.php", timeout=30, data={
    "action": "vpb_public_build", "key": key, "vimeo": "22439234"})
print(" 8", ok(r8.status_code in (400, 403) or not r8.json().get("success")),
      f"- missing nonce rejected (HTTP {r8.status_code})")

print("\nkey rotation and passcode are covered in the PHP test.")
