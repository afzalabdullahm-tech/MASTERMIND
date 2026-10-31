# Our Little Space — Hostinger PHP/MySQL edition

**Tagline:** Just Between Us ❤️  
**Deployment:** standalone PHP 8.2+ website for Hostinger Linux shared hosting, Apache/LiteSpeed `.htaccess`, MariaDB/MySQL. No Composer, npm, SSH, cron, Node, WebSocket, or paid services required.

This is a **two-account private messenger**, not a WordPress theme/plugin. It must be extracted to the document root of a dedicated domain/subdomain, normally `public_html`. Do **not** upload through WordPress Appearance → Themes. Use **hPanel → Websites → Dashboard → Files → File Manager**, or the equivalent Hosting File Manager.

## Quick deployment

1. In Hostinger hPanel, create or select a **separate domain/subdomain** for your private site. Turn on its SSL certificate under **Security → SSL**. Do not overwrite an existing production website without backing it up.
2. In **Databases → Management / MySQL Databases**, create an **empty** MySQL database and a database user. Record the full database name, username, password, and host from hPanel (host is often `localhost`). The user needs `CREATE`, `ALTER`, `SELECT`, `INSERT`, `UPDATE`, and `DELETE` permissions.
3. In the domain's **PHP Configuration**, select PHP **8.2 or later**. Enable `pdo_mysql`, `fileinfo`, `mbstring`, and `openssl`. Recommended: `upload_max_filesize=64M`, `post_max_size=70M`, `max_execution_time=120`, sufficient `memory_limit`. Actual options vary by Hostinger plan.
4. In File Manager, open the domain's document root (e.g. `public_html`). Upload **our-little-space-hostinger-ready.zip** and click **Extract**. If extraction created an extra `our-little-space` folder, move its **contents** (including `.htaccess`) into `public_html`. Make sure `public_html/index.php`, `public_html/install.php`, `public_html/api/`, and `public_html/includes/` exist directly.
5. Open `https://YOUR-DOMAIN/install.php` on your private network/device. The installer shows a requirements checklist. Enter the database host, name, user and password; choose an upload cap. Click the setup button **once**. On success, two accounts are created with two unique passwords and ten single-use recovery codes (five per account).
6. **Before leaving the installer success page**, copy the password labeled **Your Private Password**, copy **Partner's Private Password**, and click **Download recovery codes**. Store the recovery file securely. Send only the partner's password to your partner through a trusted channel. Never put the credentials in screenshots, social media, or shared online documents.
7. Visit the homepage. Play the Tic Tac Toe game or click the tiny lock in the top right. Paste either password (no username required). Each password opens its own separate account.
8. Open a private/incognito window or another phone, log in with the second password, send messages in both directions, verify read indicators, and test a small photo and voice message.
9. Once installed, `install.php` should show **Installation is locked**. The created files `includes/config.php` and `includes/install.lock` must remain present. Do not delete or edit them. `config.php` is not included in the downloadable ZIP; the installer creates it.
10. Enable Hostinger's recurring **file and database backups**. Export the MySQL database via phpMyAdmin and securely back up `storage/media/` and `includes/config.php`. Restore these together to recover messages and media.

## Structure

```
index.php                  Public Tic Tac Toe, discreet login
chat.php                   Server-side session-gated chat UI
install.php                One-time browser setup
media.php                  Authenticated media streaming, byte-range support
api/index.php              All JSON and file-upload endpoints
includes/bootstrap.php     Database, session, CSRF, headers, authorization
includes/config.php        Generated during setup (NOT included in ZIP)
includes/install.lock      Generated during setup (NOT included in ZIP)
database/schema.sql        InnoDB schema (17 tables)
storage/media/             Private randomly named binary attachments
assets/                    All local CSS/JS/icons; no trackers/CDN
manifest.json              Installable PWA metadata
service-worker.js          Caches static public assets ONLY
robots.txt                 Disallows search-engine crawling
```

### Included functional features

- Public responsive Tic Tac Toe against minimax computer, scores retained in browser-local storage, new round and score reset.
- Owner and Partner accounts only; independent securely generated passwords; password-only identification; server-side password hashing; recovery codes; password change; all-device logout.
- Two-person persistent text chat; incremental message-change synchronization using 12-second long polling and retry/fallback; per-message delivery/read receipts; typing, online/last seen; replies; reactions; edit; hide locally; delete for both (sender only); copy; favorites; search; older-message pagination; timestamps and date separators; unread count.
- Server-validated private photo/GIF/video/audio/document uploads, previews, progress, authenticated download and streaming/range requests, gallery, image lightbox, optional microphone voice notes with native playback/seek controls.
- Profile names/photos, per-user favorite messages, notes shared by both (editable by author), special date/day counter, four chat wallpapers and private custom wallpaper image upload.
- Android/iOS/desktop responsive layouts, PWA metadata, static-only service worker cache, offline banner, basic keyboard accessibility, reduced-motion CSS.

### Security model and limitations

**This site does not implement end-to-end encryption.** HTTPS protects traffic in transit and authentication/server access controls restrict browsing and downloads. The hosting server, its operators with database access, and the website operator with hosting access can technically read the messages. Do not claim that messages are E2EE or impossible to recover by hosting administrators.

- HTTPS redirect, HSTS on HTTPS, CSP, `nosniff`, no-referrer, frame denial, search-engine noindex, protected directories, strict SameSite/HttpOnly sessions and CSRF tokens.
- Passwords and recovery codes stored as one-way hashes (`password_hash`); account passwords are briefly displayed **once on the setup success page** because the installer must deliver them. They are **not** embedded into normal login pages, app JavaScript, or JSON API output.
- Login/recovery per-address rate limits, authenticated action rate limits, session version revocation, 12-hour inactivity timeout, maximum seven-day server session lifespan.
- All content generated by users is rendered with safe DOM `textContent`, not HTML injection. SQL accesses use PDO prepared statements. Media filenames are random and untrusted extensions never control execution. Private files are denied by `.htaccess` even if held inside `public_html`.
- Hostinger's Apache/LiteSpeed **must honor `.htaccess`**. Verify that `https://YOUR-DOMAIN/storage/media/`, `/database/schema.sql`, and `/includes/config.php` are not publicly readable. For best isolation, move storage outside public_html and adjust `media_storage()` accordingly if your host permits it.
- Media content types are allowlisted. If a permitted media type uses a codec unsupported by a particular browser, it may offer download rather than in-browser playback. Microphone recording requires permission, HTTPS and `MediaRecorder`; some Safari/browser combinations have different formats.
- The chat **needs internet access** and does not cache private conversations or queue offline messages. Push notifications, E2EE, and automated cloud backups are **not** included. Long polling uses PHP requests and can be limited by strict hosting CPU/process limits.
- Uploads are capped by the **lowest** of your installer cap, `upload_max_filesize`, and `post_max_size` (with headroom). For videos, set these suitably high in Hostinger's PHP Configuration before reinstalling or editing your generated `config.php` setting `upload_cap_mb`.
- A single shared history exists for exactly two people. The sender can delete a message for both people; hiding a message for yourself does not remove it for your partner. Recovery codes are one-use and are valid only for their assigned account.

## API and storage precautions

Never share, commit, or email your generated `includes/config.php` or `includes/install.lock`. Media never has a public static URL. The `media.php` route checks sessions and message visibility before opening files. The service worker caches static `.css`, `.js`, and icons only — **never** `index.php`, `chat.php`, APIs, private media, installer output or user content.

New accounts cannot be created from the site. If both passwords and all recovery codes are lost, you must work through a **manual, authorized owner-controlled recovery** involving the hosting database. Do not remove the installer lock and re-run against an existing database: doing so risks data loss.

## Troubleshooting

| Symptom | Resolution |
| --- | --- |
| Installer says missing `pdo_mysql`/`mbstring` | Enable PHP extensions in Hostinger PHP Configuration. Do not proceed until all checks pass. |
| Database connection fails | Confirm actual database host, complete database/user names, password and permissions. Use a newly created empty database. |
| 403/500 after extraction | Verify `.htaccess` was extracted, Apache/LiteSpeed supports rewrites, and permissions are normally directories `755`, files `644` (secrets may be `600`). Restore default Hostinger `.htaccess` only after retaining all protections. |
| Directory or uploads not writable | Adjust `storage/media` permissions via File Manager (do not use world-writable `777`). Check quotas and upload limits. |
| File too large | Change Hostinger `upload_max_filesize` and `post_max_size` and the private config `upload_cap_mb`. PHP may silently discard overly large POST data. |
| Voice note unavailable | Allow microphone for the HTTPS domain, try latest Android Chrome/Safari, and check `MediaRecorder` compatibility. |
| Messages delayed | Check PHP/DB availability and hosting PHP process limits. Try another network and inspect error logs. |
| No PWA install prompt | HTTPS is required. iPhone Safari uses Share → Add to Home Screen. Installation UI varies by browser. |
| Stuck on installation locked | Never delete `install.lock` casually. Ensure database and config are intact; restore from a matching private backup or follow an authorized recovery procedure. |

## Tests

`php -l` and `node --check` can validate syntax without a database. `php tests/security_check.php` checks bundle structure and security primitives; `node tests/game.test.js` checks the game's actual logic using a lightweight browser DOM stub. A live integration test of both accounts, MySQL database writes/reads, uploads, revocation and the Hostinger web server is **mandatory after deployment** and **cannot be replaced by syntax checks**. See `TEST_RESULTS.md` for tests executed on the packaging environment and what remains to verify.

## Restoring from a verified backup

1. Disable public access or use maintenance mode while restoring. Obtain the MySQL export, `storage/media/` directory and matching `includes/config.php` from the **same backup point**.
2. Create an empty MySQL database with a dedicated user in hPanel. Open phpMyAdmin and **Import** the saved SQL dump. Do **not** run the installer on a restored database.
3. Restore the original `storage/media/` folder and `includes/config.php` with correct private permissions. If your database name/username/host/password changed, update **only those four keys** in `config.php`. Preserve `app_key` to keep hashed IP audit values consistent.
4. Restore `includes/install.lock` from backup (or, during authorized migration, create a new long random lock file privately on the server). Never leave the installer exposed or accessible.
5. Confirm HTTPS and directory-deny rules. Log in from both accounts and verify the recovered messages and media files. Re-enable the public site only after private-file denial and user sessions have been checked.
