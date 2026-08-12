#!/usr/bin/env python3
"""
v1.3.2 - proves the build page is not stored by WP Fastest Cache.

Run against a rig with wp-fastest-cache active and caching switched on. This is
an A/B/A: the fix is removed and restored between runs, because "it wasn't
cached" means nothing unless you have shown the same harness DOES catch the
unfixed version.

Two things this harness got wrong first time round, both worth keeping in mind:

  - Temp files were written to /tmp on a shared machine and another process
    overwrote them, so the results being compared were somebody else's page.
    Everything now stays inside the scratchpad.
  - PHP's opcache revalidates on a timer, so the first request after swapping
    the file can still run the OLD bytecode. Without a settle delay the A/B
    flips at random. Hence the sleep after each swap.
"""
import os
import shutil
import subprocess
import time
import urllib.request

BASE = "http://127.0.0.1:8922"
HERE = os.path.dirname(os.path.abspath(__file__))
SITE = os.path.join(HERE, "site")
SRC = os.path.join(HERE, "plugin/vimeo-page-builder/includes/class-vpb-public.php")
LIVE = os.path.join(SITE, "wp-content/plugins/vimeo-page-builder/includes/class-vpb-public.php")
CACHE = os.path.join(SITE, "wp-content/cache/all")
CALL = "self::tell_caches_to_skip();"

npass = nfail = 0


def chk(label, got, want):
    global npass, nfail
    if got == want:
        npass += 1
        print(f"  PASS  {label}")
    else:
        nfail += 1
        print(f"  FAIL  {label} (got {got!r}, wanted {want!r})")


def cached_files(slug):
    d = os.path.join(CACHE, slug)
    return sum(len(f) for _, _, f in os.walk(d)) if os.path.isdir(d) else 0


def hit(path):
    """Returns True when PHP actually ran (our no-store header is present)."""
    req = urllib.request.Request(BASE + path, headers={"User-Agent": "Mozilla/5.0"})
    r = urllib.request.urlopen(req, timeout=20)
    r.read()
    return "no-store" in r.headers.get("Cache-Control", "")


def measure(path, slug, n=3):
    shutil.rmtree(CACHE, ignore_errors=True)
    ran = sum(1 for _ in range(n) if (hit(path), time.sleep(1))[0])
    return ran, cached_files(slug)


def install(with_fix):
    src = open(SRC).read()
    if not with_fix:
        src = src.replace("\t\t" + CALL + "\n", "")
    open(LIVE, "w").write(src)
    time.sleep(4)          # let opcache notice


def wp(*args):
    return subprocess.run(["php", "wp-cli.phar", "--path=site"] + list(args),
                          capture_output=True, text=True, cwd=HERE).stdout.strip()


assert CALL in open(SRC).read(), "source is missing the opt-out call"
print("WP Fastest Cache:", wp("plugin", "get", "wp-fastest-cache", "--field=version"),
      "| status:", wp("option", "get", "WpFastestCache"))
print()

print("with the fix in place:")
install(True)
ran, files = measure("/video-builder/", "video-builder")
chk("PHP runs on every request", ran, 3)
chk("nothing is written to the cache", files, 0)

print("\nwith the fix removed (must reproduce the bug, or the test proves nothing):")
install(False)
ran, files = measure("/video-builder/", "video-builder")
chk("PHP is bypassed on later requests", ran < 3, True)
chk("a cached copy IS written", files, 1)

print("\nfix restored:")
install(True)
ran, files = measure("/video-builder/", "video-builder")
chk("PHP runs on every request again", ran, 3)
chk("still nothing cached", files, 0)

print("\nthe rest of the site must be unaffected:")
slug = wp("post", "list", "--post_type=page", "--post_status=publish",
          "--field=post_name", "--posts_per_page=1").splitlines()[0]
shutil.rmtree(CACHE, ignore_errors=True)
hit(f"/{slug}/"); time.sleep(1); hit(f"/{slug}/"); time.sleep(1)
chk(f"an ordinary page still caches (/{slug}/)", cached_files(slug), 1)
hit("/video-builder/")
chk("and the build page still does not", cached_files("video-builder"), 0)

print(f"\n{npass} passed, {nfail} failed")
