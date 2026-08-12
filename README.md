# Vimeo Page Builder

One field, one button. A member of staff pastes a Vimeo ID, clicks **BUILD**, and a new
page goes live: cloned from your master Elementor page, named after the video ID, sitting
on `/{video-id}/`, with the video inside the page swapped to the new one.

---

## How it works, and why it is built this way

The plugin **never rebuilds your layout**. Elementor stores a whole page as one JSON blob
in the database. This copies that blob verbatim and changes only the video reference
inside it.

That matters. The usual way one of these breaks is that it recreates widgets in code —
which means it encodes assumptions about Elementor's internal widget format, and an
Elementor update eventually invalidates them. Copying the blob makes the plugin
indifferent to what is in your layout: sections, columns, custom widgets, third-party
add-ons, whatever. It does not need to understand them.

### What gets changed

Two passes, because a video reaches a page by more than one route:

1. **Elementor video widgets** set to Vimeo have their URL rewritten.
2. **Everything else** — hand-pasted `<iframe>` embeds, HTML widgets, section background
   videos, buttons linking to the video — is caught by a string pass that swaps the old
   ID for the new one.

The string pass walks individual field values, not the encoded JSON. Two reasons: PHP's
`json_encode` escapes forward slashes, so a regex written for `vimeo.com/123` silently
matches nothing against the stored blob; and a blob-level replace could rewrite an
unrelated number that merely happens to equal the video ID — a z-index, a phone number,
an attachment ID. Both were real failures caught in testing.

### What is deliberately not copied

`_elementor_css`, `_elementor_page_assets` and friends are generated caches keyed to the
original post ID. Copying them hands the new page the master page's stylesheet, which is
the classic reason a cloned Elementor page comes out looking broken. They are left absent
and regenerated for the new page.

---

## Things worth knowing

**Numeric URLs.** WordPress refuses, by design, to give a page a slug that is nothing but
digits — `wp_unique_post_slug()` treats `/1234567/` as clashing with pagination like
`/page/2/` and quietly appends `-2`. Since the requirement is that the URL *is* the video
ID, the plugin overrides that, but only during its own insert and only when the slug is
genuinely free. Everything else on the site keeps WordPress's normal behaviour.

**Privacy hashes.** An unlisted Vimeo video has a hash (`vimeo.com/123/abc456`). The hash
belongs to one specific video, so swapping the ID and leaving the old hash behind gives
you a URL that looks correct and refuses to play. The hash is always handled alongside the
ID it arrived with — replaced, added or removed as needed.

**More than one video on the master page.** Only the one identified in Settings is
replaced. A second video — a fixed testimonial or intro clip — is left alone. Settings
warns you when it finds more than one, and lets you say which is which.

This is not a hypothetical. On the site this was built for, each page carries a hero video
plus a grid of 24 further episodes. Rewriting every video widget — the obvious
implementation — would have wiped that grid out on every page built.

**An optional company name.** If you are handed a company name along with each video, put
`{company}` in the title format and a second box appears on the build screen. The page
title becomes something searchable; the URL stays the bare video ID. A list of pages
titled only `1211689555` is unusable a year later.

**Building without a WordPress login.** Optional. Puts the build screen on a secret
address (`yoursite.com/?vpb=<40-character-key>`) so staff can use it without an account.
The key is the credential, so it is treated as one: compared in constant time, kept out of
search engines by both header and meta tag, sent with `Referrer-Policy: no-referrer` so it
cannot leak to third parties through the Referer header, rate limited to 30 builds an hour
per IP, and rotatable from Settings the instant it is shared with the wrong person. A wrong
key renders nothing at all — the URL does not reveal that a tool is there. An optional
passcode can be required on top, so a leaked address alone is not enough. The page can only
build; it cannot edit, delete, or reach settings, and every build through it is logged with
IP and timestamp.

Be clear-eyed about the trade: a secret URL is weaker than a login. Anyone holding it can
publish pages. It is a reasonable trade when the alternative is staff not using the tool at
all, and the blast radius is capped — but it is a trade, not a free win.

**A video in a global header.** If your video lives in a Theme Builder header rather than
in the page, cloning the page will not touch it, and the plugin says so rather than
silently building a page with the wrong video on it.

---

## Setup

1. Plugins → Add New → Upload Plugin → the zip → Activate.
2. **Video Pages → Settings**: choose the master page. Everything else has a sensible
   default.
3. **Video Pages → Build New**: paste an ID, click BUILD.

Staff need Editor or above by default; you can loosen or tighten that in Settings.
Settings itself is always administrator-only.

### Settings

| Setting | Notes |
|---|---|
| Master page | The page that gets copied. Only pages with Elementor content are listed. |
| Video ID in the master page | Auto-detected. Only needed to override, e.g. two videos on the page. |
| Create as | Page or post. |
| Publish as | Live immediately, or draft so someone eyeballs it first. |
| Page title | `{id}`, `{title}` (the video's title on Vimeo), `{company}` (typed at build time). URL always uses the ID alone. |
| Parent page | Optional, e.g. to get `/videos/1234567/`. |
| Who can build | Editors, authors, or administrators only. |
| Check with Vimeo | Confirms the video exists before building. Catches a mistyped ID. |

---

## What staff see

One field and a BUILD button. Paste an ID or a whole Vimeo link — both work, along with a
copied embed code, an unlisted link with its hash, or a channel URL.

- Build the same video twice and it refuses, links you to the existing page, and offers to
  build a second one anyway if that is genuinely what you want.
- Mistype the ID and Vimeo is asked whether it exists before anything is published.
- The **View page** link appears next to the button, and the last ten builds are listed
  underneath with who built them and when.

---

## Testing

Verified against WordPress 7.0.3 and Elementor 4.2.2 on PHP 8.3.

- 19 input-parsing cases: bare IDs, full URLs, unlisted links with hashes, player URLs,
  channel and group URLs, pasted iframe embeds, and inputs that must be rejected.
- 9 master-page shapes: standard video widget, raw iframe in an HTML widget, section
  background video, deeply nested inner sections, a page with no video at all, a page
  where the video ID also appears as unrelated text, two different videos on one page, and
  both privacy-hash directions.
- Role checks: administrator, editor and subscriber against both screens.
- 28 checks on the no-login page: key accepted/rejected/rotated, prefix and
  whitespace-padded keys, the off switch, passcode, rate limit boundary, endpoint
  exposure, noindex headers, referrer policy, and a wrong key revealing nothing.
- 10 title-format cases including empty tokens, pasted HTML and stray punctuation.
- Front-end render confirmed: correct video, correct per-page stylesheet, master page
  untouched.
