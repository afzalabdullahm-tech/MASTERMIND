# Build Verification — 31 October 2026

## Automated tests actually executed in the build environment

| Check | Result | Detail |
| --- | --- | --- |
| PHP syntax | PASS | All 7 PHP files linted with PHP 8.4 CLI; no syntax errors. |
| JavaScript syntax | PASS | Node.js `--check` for all client JavaScript, service worker and test script. |
| Database schema presence | PASS (static) | All 17 intended relational table declarations found. This does NOT prove MySQL executes them. |
| Bundle security checks | PASS (static) | Protected `.htaccess` directories, secret files absent, server-side media auth check, PDO prepared statement configuration, static-only PWA cache allowlist. |
| Password primitive | PASS (unit) | Runtime `random_bytes`, `password_hash`, valid `password_verify`, and incorrect-password rejection at hash primitive level. |
| Tic Tac Toe logic | PASS (Node DOM stub) | User move, AI response, new round, cancel stale delayed AI turn, score reset. |
| PNG icons and JSON | PASS | Both app icon PNG files decoded, manifest JSON parsed successfully. |
| Anonymous public game HTTP route | PASS (PHP built-in local HTTP) | HTTP 200 with CSP, noindex and no-store headers, using a temporary fake install config in a separate test copy. |
| Chat auth gate HTTP route | PASS (PHP built-in local HTTP) | Unauthenticated `/chat.php` redirects to `index.php`. |
| Installer lock HTTP route | PASS (PHP built-in local HTTP) | `/install.php` returns 403 when both config and install lock exist. |

## Tests NOT executed — verification required on your Hostinger deployment

The build container did not provide MySQL/MariaDB, PDO MySQL or PHP mbstring. **No live database integration tests were run.** A production-ready claim about end-to-end operation would be unjustified until these have passed on your server.

- Browser installer completing MySQL connection, table creation, two-account generation, recovery codes and permanent lock.
- Owner and Partner password verification against MySQL, invalid password lockout, both account separation, recovery code consumption and password changes.
- Cross-device message delivery/read/typing/online/last-seen with incremental change polling and short-poll fallback.
- Database message persistence across refresh, message edits, local deletion, all-user deletion, search, reactions, favorites, pagination and reply.
- Genuine Hostinger multipart uploads/validation, photo/GIF/video/voice/document playback, range-based seeking, gallery and removal.
- Authentication and authorization of private media, direct-folder denial by Hostinger's actual web server, malicious file uploads, XSS/SQL injection integration probes.
- Session idle expiry, all-device logout across two devices, media access after logout, CSRF mutation denial.
- Modern mobile browsers and PWA installability, microphone permissions, touch/keyboard behavior, Hostinger disk and PHP-process limits.

### Recommended immediate acceptance test (two devices)

1. Install using a fresh database. Confirm 17 tables in phpMyAdmin and that `/install.php` is now 403.
2. Set up your two passwords and store the recovery codes offline. Test a wrong password and correct passwords on two separate devices/browsers.
3. Test text in both directions and inspect read markers. Reload each device and confirm the history is unchanged.
4. Test replies, emoji, reactions, edit, favorite, hide for me and delete for both, then a search and loading older messages.
5. Test image, MP4 and voice uploads below your configured limit. Verify download and seek, media gallery, and removal for both.
6. Set nicknames, profile photos, custom wallpaper, shared date and notes; reload and check persistence.
7. Without a session, open `/media.php?id=1`; confirm 401. Try direct `/storage/media/` and `/database/schema.sql` paths; confirm 403/404. Never accept raw files being returned.
8. Test all-device logout: open the same account on a second browser, revoke, and confirm both sessions require login.
9. Use a single recovery code once and confirm reuse is rejected. Keep the remaining codes safe.
10. Enable Hostinger backups; verify both database dump and protected media storage can be restored.

The PHP development server ignores `.htaccess` directives, so **it must not be used as a public production server** for this project. Testing `.htaccess` enforcement requires Apache/LiteSpeed on Hostinger.
