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

## Known issues

Recorded, not fixed — this initial commit is a faithful import of what is
deployed.

- **`txt_domain_delete_fullsize_image()` permanently deletes original
  uploads.** `index.php:148-155` calls
  `unlink( wp_get_original_image_path( $media_id ) )` with no existence check,
  no capability check and no way back. The `add_filter` that would run it is
  commented out (`index.php:147`), so it is dormant — but it is one
  uncommented line away from destroying every original image on upload, and
  `unlink()` on a missing path also warns. A landmine, not a live bug.
- **Non-image uploads warn, then make a billable API call.**
  `wp_generate_attachment_metadata` fires for every attachment.
  `wp_get_attachment_image_src()` returns `false` for a PDF or video, so
  `[0]` on it is "Trying to access array offset on value of type bool" on
  PHP 8, and `false` is then handed to Vision.
- **No error handling around the API.** `new ImageAnnotatorClient()` throws if
  credentials are missing, unreadable or expired, and `annotateImage()` throws
  on quota, network or permission errors. Nothing catches either, so a Google
  outage or an expired key turns **every upload into a fatal**. This is the
  single highest-impact defect here.
- **Every upload blocks on a synchronous three-feature API call**, billed per
  feature per image. There is no caching, no batching, no opt-out per mime
  type, and no way to reprocess without re-uploading.
- **`require` rather than `require_once`** at `index.php:15` for
  `ImageAnnotatorClient.php`, with a comment saying Composer autoload "does
  not work". If the autoloader has already resolved the class, this is a
  duplicate-class fatal. It is safe today only because autoloading is lazy and
  nothing touches the class before line 15.
- **No `defined( 'ABSPATH' ) || exit;` guard** on `index.php`.
- **`$BAD_TAGS` is a bare file-scope global** read back through
  `$GLOBALS['BAD_TAGS']` (`index.php:105`), with the `global` declaration
  commented out above it. It works, but it is the same unguarded-global
  pattern as `$special_categories` in `bv_geotagged_media`.
- **Thresholds are hardcoded** — 0.95 for labels, 0.8 for landmarks, 0.9 for
  NSFW — with no filter or setting.
- `index.php:149` is a single ~300-column line of whitespace-padded code, the
  same mangled-paste pathology seen in the other `bv_*` plugins.

## License

The plugin is GPL-2.0-or-later; see [LICENSE](LICENSE). Code under `vendor/`
is covered by its own licences, predominantly Apache-2.0 (Google, gRPC) and
MIT (Guzzle, Monolog, PSR).

## Credits

Lai Consulting Team. Previously hosted at
`gitlab.com/beauvoyage/bv_google_vision`.
