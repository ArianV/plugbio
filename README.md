<div align="center">

# PlugBio

**One link for every release.** Smart-link landing pages for musicians, built from scratch in plain PHP.

[**plugbio.me**](https://plugbio.me) · [See a live page](https://plugbio.me/s/olivia-rodrigo-drivers-license)

<img src="assets/og-default.png" alt="PlugBio" width="760">

</div>

---

## What it is

When an artist drops a song, it lives on half a dozen platforms. PlugBio gives each release a single page with
the cover art and a button for every service, so fans tap one link and listen wherever they want. The artist
gets to see which platforms people actually pick.

I built the first version a while back. When I came back to it, I rewrote most of it: I tightened up security,
redesigned the UI, swapped Postgres for SQLite, and moved it onto my own VPS. This repo is that second version.

## Features

- **Song pages:** title, artist, cover art and as many streaming links as you want. Paste a URL and it figures out
  whether it's Spotify, Apple Music, YouTube, SoundCloud, TIDAL, Deezer, Bandcamp or Audiomack.
- **Link previews:** every song gets its own generated share image, so links look right in iMessage, Discord,
  X, Instagram and Slack.
- **Analytics:** unique daily views, clicks per platform, click-through rate, top links and referrers.
  Bots and your own visits don't count.
- **Artist profiles:** bio, socials and every release at `/u/<handle>`.
- **Drafts:** pages stay private until you publish, and a published link never changes, even if you edit the title.
- **Discover feed:** the home page shows the latest releases.
- **SEO:** sitemap, canonical URLs and schema.org data, so songs show up properly in search.

## How it's built

No framework and no build step. Just PHP, CSS and SQL :)

```
index.php          front controller: serves static files, defines every route
config.php         settings at the top, then the plumbing: session, DB, auth, router
lib/pages.php      slugs, link validation, streaming-service detection
lib/uploads.php    the one function that writes user images to disk
routes/            one file per page or endpoint
views/             shared layout + the song editor form
schema.sql         the whole database, applied automatically on first run
assets/            stylesheet, fonts, icons
```

A request goes through `index.php`, which matches it against a small route table and hands it to a file in
`routes/`. That file does its work and renders through `views/layout.php`. Nothing is hidden behind a framework,
so you can follow any page from URL to HTML in one or two files.

## Stack

PHP 8 · SQLite · vanilla JS · CSS
