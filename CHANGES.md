# Changes from the original Dicky/prototech vBulletin 4 converter

The original converter (2008-2013) targeted phpBB 3.0.11 and PHP 5.x. These
are the fixes required to make it work against phpBB 3.3.x on PHP 7+/8.x.

## New in this release

- **Attachment-URL fixer script** (`scripts/fix_attachment_urls.php`) — fixes
  vBulletin attachment markup that survived conversion as broken text/dead
  links. Scans three text columns: `phpbb_posts.post_text`,
  `phpbb_users.user_sig`, and `phpbb_privmsgs.message_text`. Handles four
  patterns:
  - `[ATTACH]N[/ATTACH]` (old vB BBCode the convertor missed; only the
    newer `[ATTACH=CONFIG]` form was being converted)
  - `[IMG]http://<local>/attachment.php?attachmentid=N[/IMG]` (raw URL form)
  - phpBB's parsed XML form of the above
  - **Legacy `attachment.php?postid=N` URLs** (vB2/vB3 era — `N` is the
    *post* id, not the attachment id; resolved via source-DB lookup)

  Rewrites are smart about target: posts that *own* the referenced attachment
  get phpBB's native `[attachment=I]filename[/attachment]` BBCode (full inline
  rendering with attachment-list entry). Cross-post references and signatures/
  PMs get a `<IMG src="./download/file.php?id=N">` URL form (image displays,
  no attachment-list entry). External URLs (other forums) are left alone.

  Optional `--reparse` flag invokes phpBB's textformatter reparser so the
  stored XML gets rebuilt cleanly. Requires source-DB credentials
  (`--src-host`, `--src-user`, `--src-pass`, `--src-name`) when using
  legacy `postid=N` URL handling. Use `--skip-postid` to disable that.

- **Convertor improvements to `vb_reformat_inline_attach()`** — now handles
  three vB attachment-BBCode formats during conversion:
  - `[ATTACH=CONFIG]N[/ATTACH]` (newer vB) — already worked
  - `[ATTACH]N[/ATTACH]` (older vB, was previously missed)
  - `[IMG]<bburl>/attachment.php?attachmentid=N[/IMG]` (raw URL form, was
    previously missed)

  This benefits new conversions; existing converted boards should run the
  standalone fixer above.

- **Post-conversion wrapper script** (`scripts/post_convert.php`) — runs
  all standard post-conversion cleanups in one command:
  1. Verifies the conversion completed (bails if counts look wrong)
  2. Reassigns orphan posts to ANONYMOUS (fixes `phpbb_posts.poster_id`
     references to deleted users — resolves issue #3)
  3. Invokes `scripts/map_permissions.php` for permission mapping
  4. Runs phpBB's `sync('forum', ...)` and `sync('topic', ...)` to
     recompute post counts and last-post pointers

  Default UX: analyzes everything in dry-run mode, prints a summary,
  prompts for confirmation, then applies. Use `--yes`/`-y` to skip the
  prompt (for automation). Use `--skip-orphans` / `--skip-permissions`
  / `--skip-stats` to run individual phases.

  See README for usage details.

- **Permission-mapping script** (`scripts/map_permissions.php`) — handles
  the post-conversion ACP-permissions setup that previously required hours
  of manual clicking. The script:
  - Consolidates the duplicate `vB - *` group hierarchy that the original
    converter creates (every user ended up in both `vB - Registered` and
    phpBB's `REGISTERED`) into a single canonical group per user.
  - Maps vB forum-permission bitfields to phpBB ACL roles per-group,
    per-forum. Decodes the bitfield, picks the closest-matching role
    (`ROLE_FORUM_NOACCESS` → `ROLE_FORUM_READONLY` → `ROLE_FORUM_LIMITED`
    → `ROLE_FORUM_STANDARD` → `ROLE_FORUM_FULL`), inserts into
    `phpbb_acl_groups`.
  - Maps vB's `moderator` table to per-user, per-forum moderator
    assignments via `phpbb_acl_users` — including global super-moderators
    (vB `forumid = -1` rows).
  - Preserves custom groups (Rebourne, clan groups, etc.) with their
    permissions intact.
  - Locks down private/hidden forums (vB forums where some groups had
    `forumpermissions = 0`) with `ROLE_FORUM_NOACCESS`.
  - Provides a `--dry-run` flag for previewing the plan before applying.
  - Idempotent: re-running cleans up prior writes via a `phpbb_log` tag
    and re-applies.
  - Outputs a detailed report to `/tmp/vb4_perm_report.txt` plus stdout
    plus phpBB ACP log entries.

  See [docs/permission_mapping_design.md](./docs/permission_mapping_design.md)
  for the full mapping rules, role-selection algorithm, conflict
  resolution, and acceptance tests.

## PHP language fixes

| Original | Issue | Fix |
|---|---|---|
| `preg_replace('/.../e', ...)` in `vb_prepare_message` | `/e` modifier removed in PHP 7.0 | Replaced with `preg_replace_callback` calling `vb_legacy_size_to_pt()` |
| Hand-written `/ies` BBCode regexes in `add_bbcodes` | Same `/e` modifier issue, plus incompatible with phpBB 3.1+'s s9e text-formatter | Function rewritten to use `acp_bbcodes::build_regexp()` so phpBB generates valid regexes itself |
| `utf8_encode()` calls | Deprecated in PHP 8.2, removal planned | Replaced with `vb_utf8_encode()` wrapper using `mb_convert_encoding` then `iconv` then `utf8_encode` fallback |
| `unserialize($pm_blob)` in `vb_privmsgs_to_users` | PHP 7+ best practice: don't allow object instantiation from untrusted data | `unserialize($blob, ['allowed_classes' => false])` plus `is_array` guard |

## phpBB API changes (3.0 → 3.3)

| Original | Issue | Fix |
|---|---|---|
| `set_config(...)` calls in `phpbb_user_id` and `execute_first` | Function removed in 3.1+; replaced by object-based `$config->set(...)` | New `vb_set_phpbb_config()` helper that uses `$config->set()` if available, falls back to `set_config()` for 3.0 compatibility |
| `phpbb_db_tools` global class | Replaced in 3.1+ by namespaced `\phpbb\db\tools\tools` (via `\phpbb\db\tools\factory`) | `vb_add_user_salt_field()` now prefers the factory, falls back to namespaced class, then to legacy class |
| Hand-written first/second-pass BBCode regexes | s9e text-formatter parser in 3.1+ generates these from `bbcode_match` + `bbcode_tpl` | Function uses `acp_bbcodes::build_regexp()` to produce version-appropriate regexes |
| `gen_email_hash()` call as transformation | Function removed in 3.1, AND the `user_email_hash` column was removed from `phpbb_users` in 3.3.0 | Removed the entire schema row — destination has no column for the value anyway |
| Procedural `login_vb4()` auth function in `includes/auth/auth_vb4.php` | 3.1+ uses class-based service-container providers; legacy procedural auth is dead code | Rewritten as `\phpbb\auth\provider\vb4` extending `\phpbb\auth\provider\db`, with the 8-argument constructor signature 3.3.x expects |
| Auth-provider hash check used `===` | Timing-safe comparison preferred for password verification | Use `hash_equals()` in vb4 auth provider |
| `set_config("auth_method", "vb4")` in execute_first | Same `set_config` removal | Routed through `vb_set_phpbb_config()` |

## Schema changes (3.0 → 3.1)

| Old column | New column(s) | Where fixed |
|---|---|---|
| `forum_posts` | `forum_posts_approved`, `forum_posts_unapproved`, `forum_posts_softdeleted` | `vb_insert_forums()` |
| `forum_topics`, `forum_topics_real` | `forum_topics_approved`, `forum_topics_unapproved`, `forum_topics_softdeleted` | `vb_insert_forums()` |
| `topic_replies`, `topic_replies_real` | `topic_posts_approved`, `topic_posts_unapproved`, `topic_posts_softdeleted` | TOPICS_TABLE schema (regular topics + redirect stubs) |
| (no equivalent in 3.0) | `topic_visibility = ITEM_APPROVED` (default 0 = unapproved!) | TOPICS_TABLE schema |
| (no equivalent in 3.0) | `post_visibility = ITEM_APPROVED` | POSTS_TABLE schema |

Without the visibility-column fixes, every imported topic and post is
silently flagged as `unapproved` and invisible on the front end.

## Schema changes (3.1 → 3.3)

| Removed column | Migrated to | Action taken |
|---|---|---|
| `user_from` | Custom profile fields (Location) | Schema row removed |
| `user_interests` | Custom profile fields (Interests) | Schema row removed |
| `user_occ` | Custom profile fields (Occupation) | Schema row removed |
| `user_website` | Custom profile fields (Website) | Schema row removed |
| `user_msnm` | Custom profile fields (MSN) | Schema row removed |
| `user_yim` | Custom profile fields (Yahoo) | Schema row removed |
| `user_aim` | Custom profile fields (AOL) | Schema row removed |
| `user_icq` | Custom profile fields (ICQ) | Schema row removed |
| `user_email_hash` | Removed entirely (lookup uses `user_email` directly) | Schema row removed |

`user_jabber` is still in `phpbb_users` in 3.3.x and is preserved.

This was the **critical bug** that caused 0 users to import despite the
converter "completing successfully". MySQL's INSERT validation rejects
the entire row on the first unknown column it encounters, so every user
row INSERT failed at `user_from` regardless of any other content. Posts,
topics, attachments, etc. all imported because their schemas don't
reference these columns — but they ended up with `poster_id` values
pointing at users that didn't exist.

## phpBB 3.3 destination columns missing from default schema

The converter requires two columns that phpBB 3.3 doesn't ship with:

- `user_passwd_salt` — vB4 stores a salt; phpBB doesn't have a column
  for it because phpBB's bcrypt/argon2id hashes embed the salt.
- `user_pass_convert` — phpBB 2→3 migration artifact; dropped from 3.3
  default schema.

`vb_add_user_salt_field()` adds both columns at conversion time. The
function is idempotent (uses `sql_column_exists()` to skip columns that
already exist).

## Data-overflow fixes

| Source value | Destination column | Fix |
|---|---|---|
| `thread.attach` (count) | `topic_attachment` (TINYINT(1) UNSIGNED, 0/1 boolean) | Use `not_is_empty` transform to coerce count to boolean |
| `poll.timeout` * 86400 (days→seconds) | `poll_length` (INT(11) UNSIGNED, max ~136 years) | New `vb_poll_length()` clamps anything > 49,710 days to 0 (phpBB convention for "never expires") |
| `[size=N]` BBCode where N > 200 | phpBB's `MAX_FONT_SIZE = 200` | Final-pass clamp in `vb_prepare_message` |
| Any byte > 127 in usernames/posts | UTF-8 column rejection | `vb_set_encoding` reworked to detect ISO-8859-1/Windows-1252 source and convert via `mb_convert_encoding` |

## Driver compatibility

- Added `mssqlnative` to the `IDENTITY_INSERT ON/OFF` switches in
  `vb_insert_forums()` (phpBB 3.1+ added the native MSSQL driver
  alongside the legacy `mssql` and `mssql_odbc`).

## Bonus quality-of-life additions

- `VB4_DEBUG` constant and `vb_trace()` helper at the top of
  `functions_vb4.php` — opt-in runtime tracing for diagnosing silent
  conversion problems. Disabled by default with zero overhead; enable
  by changing the constant to `true`. Logs every call to
  `phpbb_user_id`, `vb_set_phpbb_config`, `vb_set_user_type`,
  `phpbb_check_username_collisions`, and `vb_add_user_salt_field` to
  a configurable file path (`VB4_TRACE_FILE`, default `/tmp/vb4_user_trace.log`).
  See README "Debug mode" section.
- `vb_log_source_inventory()` — runs at the start of conversion, prints
  vBulletin source counts (users, topics, posts, attachments, etc.) into
  the conversion progress log so you can sanity-check before clicking
  through.
- `vb_log_target_counts()` — runs at the end of conversion, prints
  destination phpBB counts. Side-by-side with the source inventory, you
  can immediately see if anything got dropped.
- Configurable batch sizes (`$convert->batch_size` / `$convert->num_wait_rows`)
  documented at the top of `convert_vb4.php` with three pre-set profiles.
- `sql/vb4_pre_conversion_inventory.sql` — standalone phpMyAdmin script
  to preview source counts before kicking off the converter.

## Files changed

- `convert_vb4.php`
  - Renamed/added schema rows for 3.1+ column changes
  - Removed legacy/no-longer-existing schema rows
  - Added `vb_log_source_inventory()` and `vb_log_target_counts()` calls
  - Bounds-checking transforms for overflow-prone fields
  - Documentation block on batch tuning and recommended PHP/DB limits

- `functions_vb4.php`
  - New helpers: `vb_set_phpbb_config`, `vb_utf8_encode`, `vb_legacy_size_to_pt`,
    `vb_poll_length`, `vb_log_source_inventory`, `vb_log_target_counts`
  - `vb_set_encoding` rewritten for windows-1252 fallback
  - `vb_prepare_message` rewritten without `/e` modifier; added size clamp
  - `add_bbcodes` rewritten using `acp_bbcodes::build_regexp()`
  - `vb_add_user_salt_field` generalised to add both required columns
    idempotently using the modern db_tools class

- `auth_vb4.php` (placed at `phpbb/auth/provider/vb4.php`)
  - Complete rewrite from procedural `login_vb4()` to class-based
    `\phpbb\auth\provider\vb4`
  - Extends `\phpbb\auth\provider\db` so post-migration users get the
    stock fast path with zero overhead
  - Uses `passwords.manager->hash()` for the rehash and `hash_equals()`
    for the verification
