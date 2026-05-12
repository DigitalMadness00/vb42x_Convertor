# vBulletin 4 → phpBB 3.3.x Converter

**Current version:** see the [VERSION](./VERSION) file at the repo root, and
the latest [Release](../../releases/latest) for downloadable artifacts.

A modernised version of the Dicky / prototech vBulletin 4 converter, brought
up to **phpBB 3.3.16** running on **PHP 7.x, 8.0, 8.1, 8.2, or 8.3**.

The original converter was written for phpBB 3.0.11 on PHP 5.x and has been
unmaintained since 2013. Running it against a modern phpBB results in:

- PHP 7+ fatal errors (the `/e` regex modifier, `set_config()` removal, etc.)
- Unknown column SQL errors (8 user-profile columns moved to a new table in 3.1+, plus several other column renames)
- Silent INSERT failures that result in posts/topics importing but **zero users** ending up in `phpbb_users`
- Auth-provider failures because phpBB 3.1+ uses a class-based provider system instead of the old procedural plug-ins

This package fixes all of those. Tested end-to-end on a 962-user / 252,529-post / 8,060-attachment / 4,589-PM vBulletin 4 board into a fresh phpBB 3.3.16 install. 100% of users imported, 0 orphan group memberships, 99.8% post fidelity.

---

## Requirements

- A working phpBB 3.3.x install (tested on 3.3.16)
- PHP 7.4+ (8.x recommended)
- MariaDB 10.x or MySQL 5.7+
- Source vBulletin 4.x.x board (database accessible to the phpBB host)

---

## What's in this repo

The directory structure mirrors phpBB's own — every file's location in
this repo matches exactly where it goes inside your phpBB installation.

```
.
├── install/
│   └── convertors/
│       ├── convert_vb4.php          # Main converter schema
│       └── functions_vb4.php        # Converter helper functions
├── phpbb/
│   └── auth/
│       └── provider/
│           └── vb4.php              # Auth provider class for vB4 password format
├── config/
│   └── default/
│       └── container/
│           └── services_auth.yml.fragment   # Snippet to merge into services_auth.yml
├── sql/
│   └── vb4_pre_conversion_inventory.sql     # Optional pre-conversion source inventory
├── README.md
├── CHANGES.md
└── LICENSE
```

---

## Installation

### 1. Copy the install/, phpbb/, and config/ directories into your phpBB tree

The simplest way: extract this package's zip and copy three top-level
directories straight into the root of your phpBB install. The paths
inside this package mirror phpBB exactly.

If your phpBB lives at `/var/www/html/phpBB3/`:

```bash
# Extract the package somewhere temporary
unzip phpbb-vb4-converter-modernized-X.Y.Z.zip -d /tmp/vb4-conv

# Copy the three directories — using cp -r merges with existing dirs.
cp -r /tmp/vb4-conv/install/   /var/www/html/phpBB3/
cp -r /tmp/vb4-conv/phpbb/     /var/www/html/phpBB3/
# Note: config/ contains a .fragment file you'll merge by hand in step 3.
# The cp won't overwrite anything; it just adds vb4-related files alongside
# what phpBB ships.
```

After this, you should have:

```
/var/www/html/phpBB3/install/convertors/convert_vb4.php
/var/www/html/phpBB3/install/convertors/functions_vb4.php
/var/www/html/phpBB3/phpbb/auth/provider/vb4.php
```

If your phpBB is installed somewhere else (e.g. `phpBB3316/`,
`forums/`, or directly at `/var/www/html/`), substitute that path
in the `cp` commands.

### 2. Register the auth provider as a service

Open `phpBB/config/default/container/services_auth.yml` in your phpBB
install. Add the contents of
`config/default/container/services_auth.yml.fragment` (from this
package) as a new entry under the existing `services:` block,
matching the indentation of the other `auth.provider.*` entries:

```yaml
    auth.provider.vb4:
        class: phpbb\auth\provider\vb4
        arguments:
            - '@captcha.factory'
            - '@config'
            - '@dbal.conn'
            - '@passwords.manager'
            - '@request'
            - '@user'
            - '%core.root_path%'
            - '%core.php_ext%'
        tags:
            - { name: auth.provider }
```

> ⚠️ **YAML uses spaces, not tabs.** 4 spaces for the key, 8 for the
> children, 12 for the dashes. If you copy the snippet through an
> editor that converts spaces to tabs, the YAML parser will reject the
> whole file with a generic "Indentation problem" error.

### 3. Clear phpBB's cache

After editing the YAML, clear the compiled cache so phpBB sees the new
service:

```bash
rm -rf phpBB/cache/production/* phpBB/cache/installer/*
```

### 4. Run the conversion

Browse to:

```
https://<your-domain>/phpBB3/install/app.php/convert
```

(Adjust the `phpBB3/` path component to match your installation's
folder name — common alternatives are `phpBB3316/`, `forums/`, or
nothing at all if phpBB is at the document root.)

Select **vBulletin 4.x.x** from the convertor list. Step through the
configuration screens (source database credentials, table prefix,
etc.) and click **Begin conversion**.

> 💡 **About the `install/` folder name.** phpBB's stock unpack puts
> the installer at `install/`. After you complete a conversion (or
> any installation), phpBB recommends *renaming* `install/` to
> something like `old.install/` so the installer routes are no longer
> exposed but the convertor files stay around in case you need to
> re-run them. Don't *delete* `install/` until you're 100% sure no
> further conversion attempts are needed.

---

## Pre-conversion inventory (optional but recommended)

Before clicking *Begin conversion*, run `sql/vb4_pre_conversion_inventory.sql`
in phpMyAdmin against your **vBulletin** database to see exactly what's about
to be processed. Edit the prefix at the top of the file:

```sql
SET @p := 'vb_';   -- or '' if your vB has no prefix
```

The script returns a single result table:

```
item                              | count_n
----------------------------------+--------
Users (total)                     | 962
Categories + forums (vB nodes)    | 99
Topics (excluding moved stubs)    | 14,738
Posts                             | 252,529
Attachments (count)               | 5,346
Attachments total size            | 1.22 GB
Private messages (recipient rows) | 12,408
... etc.
```

This lets you eyeball whether the numbers match what your old vB ACP says
*before* committing to a multi-hour conversion run.

---

## Post-conversion checklist

### Quick path: one-command wrapper

Run `scripts/post_convert.php` to do everything in one step:

```bash
# Preview what will change without applying
php scripts/post_convert.php --dry-run

# Run interactively (analyzes, prompts to confirm, then applies)
php scripts/post_convert.php --src-name=<your_vb_db>

# For automation (skips the prompt)
php scripts/post_convert.php --yes --src-name=<your_vb_db>
```

The wrapper runs four phases:
1. **Verify conversion completed** — bails if posts/users counts look wrong
2. **Reassign orphan posts** — fixes `phpbb_posts.poster_id` references to deleted users
3. **Permission mapping** — invokes `scripts/map_permissions.php` (see below)
4. **Forum/topic statistics resync** — recomputes counts and last-post pointers

By default it does an analysis pass, prints a summary, prompts `Apply all
these changes? [yes/N]`, and only writes if you type `yes`. Use `--yes`
to skip the prompt for automation.

For more control, run the individual scripts described below.

### Manual ACP steps that the wrapper does NOT do

These have to happen in the phpBB ACP because they're either too slow
for a script to handle gracefully or genuinely need human judgment:

1. **Maintenance → Search index → Create index** — converted posts
   won't appear in search results until the index is built. The default
   Native Fulltext driver works fine on most boards. Building takes
   hours on large boards, so it's not in the wrapper.
2. **Review permission-mapping WARNINGS** — see `/tmp/vb4_perm_report.txt`
   for forums where moderators got locked out of forums whose names
   suggest mod scope. Decide whether to grant read access manually.
3. **Delete or rename phpBB's `install/` directory** for security:
   ```bash
   rm -rf phpBB/install
   # or keep it for re-running the convertor:
   mv phpBB/install phpBB/old.install
   ```

### Detailed: the individual scripts

If you want to run only specific phases (e.g. permission mapping
without stats resync, or with a custom `--aggressive` setting), call
the individual scripts directly:

3. **Forum permissions** — vBulletin's permission model doesn't map cleanly
   onto phpBB's. **Use the included permission-mapping script** to handle
   group consolidation and per-forum ACL setup automatically:

   ```bash
   # Preview what will change (no writes):
   php scripts/map_permissions.php --dry-run

   # Apply:
   php scripts/map_permissions.php
   ```

   The script:
   - Consolidates duplicate `vB - *` groups into phpBB's system groups
     (REGISTERED, GLOBAL_MODERATORS, ADMINISTRATORS) so each user is in
     one canonical group
   - Maps vB forum-permission bitfields to phpBB ACL roles per-group,
     per-forum, including private/hidden forums
   - Maps the vB `moderator` table to per-user, per-forum moderator
     assignments
   - Preserves custom groups (e.g. clan groups, special-access groups)
     with their permissions

   Output: a detailed report at `/tmp/vb4_perm_report.txt` plus stdout
   plus entries in the phpBB ACP log. Run `--dry-run` first to verify
   the plan before applying. Re-running is idempotent.

   See [docs/permission_mapping_design.md](./docs/permission_mapping_design.md)
   for the full specification, mapping tables, and acceptance tests.

   Even after running the script, **review forum permissions in the ACP**
   for any that the script flagged as needing manual attention.

4. **Fix broken attachment markup** — vBulletin used two attachment-BBCode
   formats (`[ATTACH]N[/ATTACH]` and `[ATTACH=CONFIG]N[/ATTACH]`) plus
   raw URL embeds. The convertor handles `[ATTACH=CONFIG]` correctly but
   the older `[ATTACH]N[/ATTACH]` and raw `[IMG]http://.../attachment.php?attachmentid=N[/IMG]`
   patterns are left as broken text/dead links. Use:

   ```bash
   # Preview:
   php scripts/fix_attachment_urls.php --dry-run

   # Apply:
   php scripts/fix_attachment_urls.php

   # Apply + invoke phpBB's textformatter reparser (15-30 minutes, optional):
   php scripts/fix_attachment_urls.php --reparse
   ```

   The script:
   - Rewrites both broken patterns to phpBB's `[attachment=I]filename[/attachment]` BBCode
   - Leaves external URLs (other forums) alone — `--vb-url` flag controls
     which hosts are treated as local
   - Looks up filenames from `phpbb_attachments`, assigns per-post indices
   - Marks affected posts with `post_attachment=1`
   - Optionally invokes phpBB's reparser to rebuild stored XML

   Idempotent: re-running skips posts that already have valid `[attachment=N]`
   markup.

5. **Reassign orphan posts** — if your source vB had posts authored by
   user IDs that no longer existed in vB's user table (typical for users
   who deleted their accounts), those posts are imported with
   non-existent `poster_id` values. Reassign them to anonymous:

   ```sql
   UPDATE phpbb_posts
   SET poster_id = 1
   WHERE poster_id NOT IN (SELECT user_id FROM phpbb_users)
     AND poster_id <> 1;

   UPDATE phpbb_topics
   SET topic_poster = 1
   WHERE topic_poster NOT IN (SELECT user_id FROM phpbb_users)
     AND topic_poster <> 1;
   ```

   Username strings (`post_username`, `topic_first_poster_name`, etc.) are
   preserved as columns on the row, so attribution survives.

5. **Delete the `install/` directory** for security:
   ```bash
   rm -rf phpBB/install
   ```

---

## How passwords work after conversion

vBulletin uses a different password format than phpBB:

- vB:    `md5( md5(plaintext) . user_passwd_salt )` → 32-char hex
- phpBB: bcrypt or argon2id (60+ chars)

The converter stores the raw vB hashes in `user_password` and sets a flag
`user_pass_convert = 1` on every imported user. The vb4 auth provider then
handles login by:

1. Looking up the user
2. If `user_pass_convert = 1`, computing the vB hash from the supplied
   password and comparing to `user_password` using `hash_equals()`
3. On match, re-hashing the password using phpBB's modern passwords manager
   and clearing `user_pass_convert`
4. On subsequent logins, falls through to phpBB's stock `db` provider

After every imported user has logged in once, you can switch the auth
method back to plain `Database` in the ACP. Any users who never logged in
will need to use the forgot-password flow.

The auth provider's `user_passwd_salt` column is added at conversion time
by `vb_add_user_salt_field()`, which is idempotent and safe to re-run.

---

## What does NOT migrate

Eight user profile fields were moved out of `phpbb_users` in 3.1+ and into
a custom-profile-fields table (`phpbb_profile_fields_data`):

- Location (`user_from`)
- Interests (`user_interests`)
- Occupation (`user_occ`)
- Website (`user_website`)
- ICQ / AIM / MSN / Yahoo (`user_icq`, `user_aim`, `user_msnm`, `user_yim`)

This converter doesn't carry those across because doing so cleanly would
require setting up matching custom profile fields on the destination side
first. If your community has a lot of profile data, this is a reasonable
follow-up project.

`user_jabber` IS still in `phpbb_users` in 3.3.x and is preserved.

---

## Troubleshooting

### "Form was invalid" when logging in

A stale cookie from a pre-conversion phpBB session. Clear cookies for the
site (DevTools → Application → Cookies → delete all for the domain) and
try again. Incognito/private window also works.

### "Unknown column" SQL errors during conversion

Either your phpBB schema is older than 3.3.x and missing some columns we
expect, or you have an unusual install. Open an issue with the SQL error
and we can adjust the schema row.

### Conversion completes but `phpbb_users` only has bots

This was the major bug fixed in this release. If you still see it, it
means the vb4 auth provider class isn't reachable. Verify (substitute
your actual phpBB path):

```bash
ls /var/www/html/phpBB3/phpbb/auth/provider/vb4.php
grep -A12 'auth.provider.vb4' /var/www/html/phpBB3/config/default/container/services_auth.yml
```

Both should return real content. If either is missing, redo steps 2 and 3 above and clear the cache.

### Conversion is slow

The converter processes data in batches. The default batch size in
`install/convertors/convert_vb4.php` is 8000 rows per chunk, which works on most servers.
For very large boards (>500K posts) you may want to bump it up to ~20000
on a beefy server, or down to ~2000 on a small one. See the comment block
at the top of `convert_vb4.php` for guidance.

You'll also want to raise PHP limits during conversion:

```ini
; In php.ini or a drop-in like /usr/local/etc/php/conf.d/zz-converter.ini
memory_limit       = 1024M
max_execution_time = 0
post_max_size      = 64M
upload_max_filesize = 64M
```

And MariaDB/MySQL:

```ini
; In my.cnf [mysqld]
max_allowed_packet       = 256M
wait_timeout             = 28800
innodb_lock_wait_timeout = 600
```

You can revert all of these to normal values once conversion is done.

### Post-conversion: orphan group memberships

Run this query to verify that every row in `phpbb_user_group` references a
real user:

```sql
SELECT COUNT(*) FROM phpbb_user_group ug
WHERE ug.user_id NOT IN (SELECT user_id FROM phpbb_users);
```

The expected result is **0**. A non-zero number means somewhere in the
conversion, group rows got committed before the corresponding user rows.
Open an issue with the count and we'll investigate.

---

## Debug mode

If conversion completes but data is missing — users not imported, posts
mapped to non-existent users, etc. — phpBB's own logs are usually empty
because the converter framework swallows transformation errors silently.
The package ships with an opt-in trace mode that reveals exactly what
the per-row transformations are doing.

### Enabling it

Open `install/convertors/functions_vb4.php` and find the block near
the top:

```php
if (!defined('VB4_DEBUG'))
{
    define('VB4_DEBUG', false);
}
if (!defined('VB4_TRACE_FILE'))
{
    define('VB4_TRACE_FILE', '/tmp/vb4_user_trace.log');
}
```

Change `false` to `true`, and optionally point `VB4_TRACE_FILE` somewhere
the web-server user (typically `www-data`, `apache`, or `nginx`) can write.
On shared hosting where `/tmp` may be unwritable or per-process, try a
path inside phpBB's writable areas:

```php
define('VB4_TRACE_FILE', '/full/path/to/phpBB/store/vb4_trace.log');
```

Save the file, clear phpBB's cache, run the conversion, then inspect
the trace file. Each line shows one traced call.

### What gets traced

| Function | Trace coverage |
|---|---|
| `vb_set_phpbb_config()` | every call, both API branches (object vs procedural) |
| `phpbb_user_id()` | every input/output, plus the lazy-init block and remap path |
| `vb_set_user_type()` | called per user-table row — proves the user-table block is iterating |
| `phpbb_check_username_collisions()` | ENTER/EXIT markers |
| `vb_add_user_salt_field()` | ENTER/EXIT plus which columns it added |

### Performance impact

When `VB4_DEBUG` is `false` (the default), every `vb_trace()` call
short-circuits in O(1) and writes nothing to disk. There is no measurable
overhead on a normal conversion run, so the flag is safe to ship as
disabled-by-default.

When the flag is `true`, expect the trace file to grow to several hundred
thousand lines on a non-trivial board (every `phpbb_user_id` call is
logged, and that function gets called once per user-id reference across
posts, topics, attachments, etc.). On a 250K-post board the trace file
ended up around 50–60 MB. Make sure the destination filesystem has
the space.

### Turning it off again

Set the flag back to `false`. There's no need to remove the trace file —
it'll just stop growing. Delete it manually if you don't want it sitting
around:

```bash
rm -f /tmp/vb4_user_trace.log
```

---

## Resetting between attempts

If a conversion fails partway through, you must reset the destination
database before retrying. Otherwise you'll get duplicate-key errors on
subsequent runs. The cleanest path is to drop and reinstall phpBB from
scratch:

```sql
DROP DATABASE phpbb3_yourname;
CREATE DATABASE phpbb3_yourname CHARACTER SET utf8 COLLATE utf8_bin;
```

Then re-run `phpBB/install/app.php/install` to recreate the empty schema,
re-deploy the converter files (since reinstalling restores the default
`auth.provider.db` and removes our YAML edit), and try the conversion
again.

For incremental retries, you can also truncate the data tables manually,
but be careful not to truncate `phpbb_migrations` (that tracks which
phpBB upgrade migrations have run and corrupting it breaks future
phpBB updates).

---

## Compatibility matrix

| phpBB version | PHP version | Status |
|---|---|---|
| 3.3.16 | 8.3 | ✅ Tested |
| 3.3.16 | 8.2 | ✅ Should work (PHP 8.2 deprecated `utf8_encode` which is handled) |
| 3.3.16 | 8.1 | ✅ Should work |
| 3.3.16 | 8.0 | ✅ Should work |
| 3.3.16 | 7.4 | ✅ Should work |
| 3.3.x (older) | any | ⚠️ Should work — same auth provider API |
| 3.2.x | any | ⚠️ Untested — auth provider API differs |
| 3.1.x | any | ⚠️ Untested — `db` provider has 6 args instead of 8 |
| 3.0.x | any | ❌ Use the original Dicky/prototech version instead |

---

## Releases

This repository uses an automated GitHub Actions workflow that bumps the
patch version (`0.0.X`) on every push to `main`, tags the commit, builds
a distribution zip, and publishes a GitHub Release.

To download a specific version, visit the [Releases page](../../releases)
and grab `phpbb-vb4-converter-modernized-X.Y.Z.zip` from the assets.

If you want to track changes between releases, see [CHANGES.md](./CHANGES.md)
for the full history of fixes vs. the original Dicky/prototech converter.

---

## Credits

- Original converter: **Dicky** (2008) and **prototech** (2013) on phpbb.com
- Originally based on phpBB's official phpBB2 converter
- Modernisation for phpBB 3.3.x / PHP 7+ compatibility (2026): **DigitalMadness00**
  - GitHub: [@DigitalMadness00](https://github.com/DigitalMadness00) ([repo](https://github.com/DigitalMadness00/vb42x_Convertor))
  - phpbb.com forums: [DigitalMadness](https://www.phpbb.com/community/memberlist.php?mode=viewprofile&u=2255905)

This converter is GPL-2.0 licensed (inherited from phpBB).

---

## Contributing

Issues and PRs welcome at the project repo:
[github.com/DigitalMadness00/vb42x_Convertor](https://github.com/DigitalMadness00/vb42x_Convertor)

When reporting bugs, please include:

- Source vB4 version
- Destination phpBB version
- PHP version
- Database type and version (MySQL / MariaDB / etc.)
- Output from `sql/vb4_pre_conversion_inventory.sql`
- The error message and steps to reproduce

For schema-related issues, the relevant SQL error message (with the column
name) is usually enough to pinpoint the fix.
