# BV Google Vision

Runs every uploaded image through the Google Cloud Vision API on upload, and
uses the result to tag the attachment, flag NSFW content, and — when the image
contains a recognisable landmark and has no GPS EXIF — synthesise a
`geom_point` so it still appears on the map.

**155 of the 1542 files here are ours.** `index.php` is the plugin; everything
under `vendor/` is Composer-installed third-party code.

## Requirements

- PHP 7.4+ with the **gRPC** extension (or it falls back to REST via Guzzle)
- A Google Cloud project with the **Vision API** enabled, and a service
  account credential — see [Credentials](#credentials)
- `voyage` / `bv_geotagged_media` own the downstream `geom_point` consumers,
  though this plugin writes that meta independently

## Credentials

The client is constructed as `new ImageAnnotatorClient()` with no arguments
(`index.php:57`), so it uses **Application Default Credentials** — it reads
the path in the `GOOGLE_APPLICATION_CREDENTIALS` environment variable. In this
install `wp-config.php` sets that from a `GOOG_VISION_JSON` constant.

> **The keyfile must live outside the web root.** It is a private key, and
> anything under the document root is fetchable over HTTP unless something
> explicitly blocks it. A `config/` directory inside the WordPress root is
> *not* safe — denying directory listing does not deny the file itself. Put it
> somewhere like `/etc/gcp/`, owned `root:<web-user>` with mode `640`, and
> point the constant at an absolute path. If PHP cannot then read it, check
> `open_basedir`, SELinux and AppArmor before loosening permissions.

`.gitignore` blocks `*credentials*.json`, `*service-account*.json` and GCP's
default `<project>-<keyid>.json` naming, so a keyfile dropped into this
directory will not be committed by accident.

## How it works

`wp_generate_attachment_metadata` (priority 10) → `bv_update_imagetags()`:

1. takes the `large` image URL and sends it to Vision with three features —
   `LABEL_DETECTION`, `LANDMARK_DETECTION`, `SAFE_SEARCH_DETECTION`
2. stores raw results in `google_labels` and `google_landmarks` post meta
3. tags the attachment with labels scoring above 0.95, minus `$BAD_TAGS`
4. adds an `NSFW` tag when adult or violence likelihood is `VERY_LIKELY`
   (the score is the Vision enum 0-5 divided by 5, compared against 0.9)
5. if the attachment has no `geom_point` and a landmark was located, writes a
   GeoJSON Point built from the landmark's lat/lng

`$BAD_TAGS` (`index.php:23-28`) is a stop-list of labels too generic to be
useful — `water`, `nature`, `people`, `adult` and similar.

## Why `vendor/` is committed

`index.php:11` does `require __DIR__ . '/vendor/autoload.php'`, and the plugin
is deployed by copying the directory, so a checkout without `vendor/` fatals
on load. `composer.lock` is committed alongside it, so the tree is still
reproducible with `composer install`. The cost is a 13 MB repository and 27
third-party packages whose updates are invisible to dependency tooling.

Those packages are old — `google/cloud-vision` 1.0.0, `grpc/grpc` 1.27.0,
`guzzlehttp/guzzle` 6.5.3 — and have not been refreshed since the plugin was
written. Updating them is a separate exercise with real regression risk.

## Fixed in `fix/upload-resilience`

- **Uploads no longer fatal when Vision is unavailable.** `new
  ImageAnnotatorClient()` throws when the credential is missing, unreadable or
  expired, and `annotateImage()` throws on quota, permission and network
  errors. Neither was caught, so any of those took down the upload request.
  Both are now wrapped in `try`/`catch ( \Throwable )` — `Throwable`, not
  `Exception`, because the gRPC and protobuf layers raise `Error` subclasses —
  the failure is logged, and an empty result is returned so the attachment is
  still created, just untagged. The client is closed in a `finally`.
  Reproduced before the fix: a throwing constructor propagated out of
  `bv_update_imagetags()` as a fatal.
- **Non-image uploads no longer warn or make a billable API call.**
  `wp_generate_attachment_metadata` fires for every attachment;
  `wp_get_attachment_image_src()` returns `false` for a PDF or video, so `[0]`
  on it was `Trying to access array offset on false` and `false` was then sent
  to Vision. Confirmed before the fix: one billable call per PDF. Now guarded
  with `wp_attachment_is_image()` plus an `is_array()` check on the result.
- **Removed `txt_domain_delete_fullsize_image()`.** It called
  `unlink( wp_get_original_image_path( $media_id ) )` with no existence check,
  no capability check and no way back. Its `add_filter` was commented out, so
  it never ran — but it sat one uncommented line away from permanently
  deleting every original upload. Removed rather than guarded: nothing in the
  install referenced it, and a disk-space problem should be solved with a
  deliberate, reversible media cleanup, not a filter on every upload.
- `require` → `require_once` for both includes; the manual
  `ImageAnnotatorClient.php` include was a duplicate-class fatal waiting on
  the autoloader resolving the class first.
- Added `defined( 'ABSPATH' ) || exit;`, and `$GLOBALS['BAD_TAGS']` is now
  read through `isset()` + `is_array()`.

## Known issues

- **Every upload still blocks on a synchronous three-feature API call**,
  billed per feature per image. There is no caching, no batching, no per-mime
  opt-out and no way to reprocess without re-uploading. Making this
  asynchronous is a design change, not a fix.
- **Thresholds are hardcoded** — 0.95 for labels, 0.8 for landmarks, 0.9 for
  NSFW — with no filter or setting.
- **`$BAD_TAGS` is a bare file-scope global**, the same unguarded-global
  pattern as `$special_categories` in `bv_geotagged_media`.
- **The vendored dependencies are years out of date** — `google/cloud-vision`
  1.0.0, `grpc/grpc` 1.27.0, `guzzlehttp/guzzle` 6.5.3. Updating them is a
  separate exercise with real regression risk.
- `index.php` still contains one ~300-column whitespace-padded line, the same
  mangled-paste pathology as the other `bv_*` plugins.

## License

The plugin is GPL-2.0-or-later; see [LICENSE](LICENSE). Code under `vendor/`
is covered by its own licences, predominantly Apache-2.0 (Google, gRPC) and
MIT (Guzzle, Monolog, PSR).

## Credits

Lai Consulting Team. Previously hosted at
`gitlab.com/beauvoyage/bv_google_vision`.
