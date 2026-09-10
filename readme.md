=== Pongrass Editorial Plugin ===

Tags: wordpress plugin, plugin, pongrass, pongrass editorial
Requires at least: 5.6
Requires PHP: 8.0
Tested up to: 6.7
Stable tag: "2.7.1"

Pongrass Editorial Plugin. For use in integration with the Pongrass Advertising and Editorial system for WordPress

== Description ==

Adopted from the template plugin EPT Empty Template Plugin. (http://1manfactory.com/ept) This plugin is licensed under GPLv2

It provides functionality to integrate with Pongrass NewsEditor, PMC and Adbooking

Every important point will have it's own page to explain.


== Installation ==

1. Copy the template files to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Open **Pongrass** in the admin menu and generate an API key
4. Configure the Pongrass clients to send that key, then turn legacy mode off

== Remove plugin ==

1. Deactivate plugin through the 'Plugins' menu in WordPress
2. Delete plugin through the 'Plugins' menu in WordPress

It's best to use the build in delete function of wordpress. That way all the stored data will be removed and no orphaned data will stay.

== Endpoint authentication ==

`pep_json.php` is a public URL that can create, change and delete content, so
every request has to be authenticated.

**API key (preferred).** Generate one under *Pongrass* in the admin menu. It is
shown once and only a SHA-256 hash is stored, so keep a copy. Send it either as
a request header:

    X-PEP-Key: pep_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx

or as a `key` member of the JSON-RPC envelope:

    {"id": 1, "method": "pep_get_version", "key": "pep_xxxx...", "params": {}}

For multipart uploads that cannot set headers, a `pep_key` form field also works.

**Legacy mode.** Existing installs are upgraded with legacy mode on so clients
that do not send a key keep working. In legacy mode a keyless request is
accepted only when the caller's address is on the IP whitelist. Once every
client sends a key, turn legacy mode off.

An empty whitelist matches nothing. Before 2.7.0 an empty whitelist skipped the
check completely and the endpoint accepted requests from anyone.

Whitelist entries accept a single address (`203.0.113.9`), a CIDR range
(`203.0.113.0/24`) or a trailing wildcard (`203.0.113.*`).

== wp-config.php constants ==

Put these in `wp-config.php`, above the `/* That's all, stop editing! */` line.

* `PEP_DISABLE_OTHER_PLUGINS` - set false to keep other plugins loaded during
  RPC requests. Defaults to true, which also disables any security plugin.
* `PEP_TRUST_PROXY_HEADER` - set true only behind a reverse proxy that
  overwrites `X-Forwarded-For`. Otherwise the header is ignored, since a client
  can set it to anything.
* `PEP_LEGACY_ALLOW_ANY_IP` - emergency escape hatch that accepts keyless
  requests from any address. Leaves the endpoint open; remove it as soon as the
  clients send a key.
* `PEP_LOG_PAYLOADS` - set true to log full request and response bodies. Off by
  default because those contain post content and user email addresses.

== pep-local-config.php ==

One setting cannot live in `wp-config.php`, because it is what locates
`wp-config.php` in the first place:

* `PEP_WP_LOAD_PATH` - absolute path to `wp-load.php` for a non-standard layout.

If the plugin directory contains `pep-local-config.php`, it is loaded before
anything else and can define it:

    <?php
    define( 'PEP_WP_LOAD_PATH', '/www/example/public/web/wp/wp-load.php' );

The file is per-site and is not tracked in git. Everything else belongs in
`wp-config.php`.

== Screenshots ==

soon

== Frequently Asked Questions ==


== Upgrade Notice ==

= 2.7.0 =
Security release. The RPC endpoint now requires authentication. Read the
breaking changes below before upgrading a live site.

== Changelog ==

= 2.7.1 =

* Version, build and release date moved into `pep_version.php`. `pep.php`,
  `pep_json.php` and the settings screen all read from there, so a release
  only needs that one file edited.
* `pep_get_version` no longer carries its own hard-coded version and date.
  It had been reporting 2.63 / 2025-03-11 while the plugin constant said
  2.6.4 and the plugin header said 2.6.2.
* `pep_get_version` now also returns `build`.
* Added an admin notice when the `Version:` line in the pep.php header drifts
  from `PEP_CURRENT_VERSION`. WordPress parses that header as a static
  comment, so it is the one value that still has to be typed twice.

= 2.7.0 =

Security:

* The RPC endpoint required no authentication. It now requires an API key, or
  legacy mode plus a whitelisted IP.
* An empty IP whitelist skipped the whitelist check entirely instead of
  rejecting the request. It now matches nothing.
* `pep_get_authors` took a `login` from the request body and called
  `wp_set_current_user()` on it, letting an unauthenticated caller assume any
  account including an administrator. It then called `wp_logout()`, destroying
  that user's session. Both removed.
* `pep_link_uploaded_file` used the client-supplied filename unfiltered, so a
  path such as `../../../` escaped the uploads directory. Filenames are now run
  through `basename()` and `sanitize_file_name()`, and the FTP source path is
  confirmed to resolve inside `FTP_ROOT`.
* Captions were concatenated into post HTML and into HTML attributes without
  escaping. They are now escaped per context.
* The admin settings page echoed stored options and `REMOTE_ADDR` unescaped.
* The admin menu was registered at capability `0` and `9`. Capability `0` maps
  to the subscriber level, so any logged-in user could open the settings page.
  It is now behind `manage_options`.
* Whitelist values were written verbatim to `whitelist_N.txt` in the plugin
  directory, which is readable over HTTP. The files are gone; the values live
  in options and are validated as an address, CIDR range or wildcard.
* `pep_set_meta_data` would write any meta key from the payload, including
  protected keys such as `_thumbnail_id`, and would create arbitrary
  taxonomies. Protected keys are now refused unless allowed through the
  `pep_allowed_protected_meta` filter, and only existing taxonomies are written.
* `pep_get_recent_post` passed the caller's filter straight to
  `wp_get_recent_posts()`, which allowed reading drafts and private posts. Only
  known query vars and public statuses are accepted.
* `pep_cancel_post_by_form_id` with an empty `form_number` matched and deleted
  every post with an empty `form_number`. It is now rejected.
* Request and response bodies are no longer logged by default; the log sits
  inside the web root and is readable through `pep_get_logfile`.
* The log directory now ships a `.htaccess` deny rule and an `index.php`.
  `index.html` alone did not stop the `.log` files being fetched by name.
* Exception messages, which can carry file paths and SQL, are no longer
  returned to the caller. They are written to the log instead.
* Methods other than the read-only ones now require POST.
* The log folder is created 0755 rather than world-writable 0777.

PHP 8 compatibility:

* `FTP_ROOT` was referenced while undefined, which is a fatal `Error` on PHP 8.
* `pep_publish_acd_classified` used `$mypost['ID']` on a `WP_Post` object,
  a fatal `Error` on PHP 8.
* `pep_deltree()` recursed into `ept_deltree()`, which does not exist; `@` does
  not suppress that fatal on PHP 8.
* `pep_get_recent_post` declared its parameter as `$requesst` and read
  `$request` throughout, so every read hit an undefined variable.
* `utf8_encode()` removed. Deprecated as of PHP 8.2, and it mangled the UTF-8
  that WordPress supplies by treating it as Latin-1.
* `fwrite()` was called on the result of an unchecked `fopen()`, a `TypeError`
  on PHP 8. Log writes now go through `file_put_contents()` with `LOCK_EX`.
* Undefined array keys and variables throughout the request handling, all of
  which emit warnings on PHP 8 and corrupt the JSON response when
  `display_errors` is on.
* `catch (Exception)` did not catch `Error`, so engine failures escaped the
  handler. Now catches `Throwable`.

Fixes:

* The `Content-Type` test was an exact match against `application/json` and
  failed as soon as a client appended `; charset=utf-8`.
* `strpos()` was used as a boolean, so a picture marker at offset 0 was treated
  as absent.
* `register_handler()` assigned to an undefined local, so no handler was ever
  registered.
* `error_log($upload_dir).' is not writable'` concatenated onto the return
  value and logged nothing useful. Same for the `pep_writelog` equivalent.
* The WordPress root is discovered by walking up from the plugin directory
  instead of being hard-coded to two specific customer paths.
* `wp-load.php` is loaded instead of `wp-blog-header.php`, which also ran the
  main query and the template loader and emitted theme HTML ahead of the JSON.
* The plugin-suppression filter keyed off `$_SERVER['PHP_SELF']`, which is
  request-derived. It now keys off a constant set by the endpoint.
* The admin menu registered the same page four times.
* Log files rotate at 8 MB instead of growing without limit.
* Removed `wp-blog-header.php`, a stray copy of a WordPress core file that
  fataled when requested directly, and `pep_config _neoskosmos.php`, a stale
  site-specific duplicate.

Breaking changes:

* Installs with no IP whitelist and no API key will reject every RPC request
  until one is configured. This is the fix for the open endpoint; there is no
  configuration that both authenticates callers and keeps accepting anonymous
  ones.
* `pep_get_authors` no longer accepts a `login` parameter.
* Write methods reject GET.
* Payloads are reduced to known post fields before reaching `wp_insert_post()`.
* Protected meta keys are refused by default.
* Minimum PHP is now 8.0.

= 0.0.0.1 (03.08.2012) =
* first version
