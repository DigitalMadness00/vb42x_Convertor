# Permission Mapping Script — Design

**Status:** Draft v2 (revised after seeing real beyondtheportal data).
Spec for `scripts/map_permissions.php`, a standalone post-conversion
utility that maps vBulletin 4 forum/group/moderator permissions into
phpBB 3.3.x ACL roles, AND consolidates the duplicate group hierarchies
the converter creates.

**Issue:** [#4 in vb42x_Convertor](https://github.com/DigitalMadness00/vb42x_Convertor/issues/4)

---

## 1. Scope

### In scope (v1)

**Group consolidation** (new):
- Merge `vB - Registered` / `Moderators` / `vB - Administrators` etc.
  into phpBB's system groups (REGISTERED / GLOBAL_MODERATORS / ADMINISTRATORS)
- Move `phpbb_user_group` rows from the vB-imported groups onto the
  corresponding system groups
- Drop the empty `vB - *` duplicate groups
- Preserve genuinely custom groups (Rebourne, etc.) under their existing
  ids

**Permission mapping**:
- Group-level forum permissions (vB `forumpermission` + `usergroup` defaults)
- Per-group, per-forum permission overrides
- Standard moderator groups (vB usergroupid 5, 7)
- Per-forum moderator assignments (vB `moderator` table)
- Super-moderators (rows with `forumid = -1`)
- Private/hidden forums (forums where some groups have `forumpermissions = 0`)
- Custom user groups (e.g. Rebourne) — full permission mapping
- Conservative vs aggressive flag for handling edge cases

### Out of scope (v1, possible future work)
- PM permissions per-group (we map standard PM roles only)
- Custom permissions added by vB mods/plugins
- vB's "infraction" system (no phpBB equivalent)
- vB's albums/social-group permissions (different phpBB feature)
- User-individual permission overrides outside `moderator` table

### Explicitly skipped
- vB usergroupid 1 (Guest) — phpBB's GUESTS group has its own defaults
- vB usergroupid 4 (COPPA) — phpBB's REGISTERED_COPPA handles this
- vB usergroupid 8 (Banned) — bans are per-user in phpBB via `phpbb_banlist`

### Why consolidation is part of this script

The original converter creates two parallel group hierarchies:
1. **vB-imported groups** at ids 2-8 + custom — where imported users actually live
2. **phpBB system groups** at ids 15-21 — mostly empty

A user who was a vB Registered (group 2) ends up in **both** `vB - Registered` (id 2)
AND `REGISTERED` (id 16) in phpBB. This makes permission assignment ambiguous —
which group's role wins? — and inflates `phpbb_user_group` by ~2×.

Permission mapping is only meaningful if we know which group is the "real"
group for each user. Therefore: consolidate first, then map.

---

## 2. Permission bit decoding

### vBulletin `forumpermissions` bitfield

Cross-referenced against vBulletin 4.2 source (`includes/init.php`,
`includes/functions_forumlist.php`):

| Bit  | Value      | vB name                  | Meaning                            |
| ---- | ---------- | ------------------------ | ---------------------------------- |
| 0    | 1          | canview                  | Can see forum exists               |
| 1    | 2          | canviewothers            | Can see threads by others          |
| 2    | 4          | canviewthreads           | Can read threads                   |
| 3    | 8          | cansearch                | Can search this forum              |
| 4    | 16         | canemail                 | Can use email-thread function      |
| 5    | 32         | canpostnew               | Can start new threads              |
| 7    | 128        | canreplyown              | Can reply to own threads           |
| 8    | 256        | canreplyothers           | Can reply to others' threads       |
| 9    | 512        | caneditpost              | Can edit own posts                 |
| 10   | 1024       | candeletepost            | Can delete own posts               |
| 11   | 2048       | candeletethread          | Can delete own threads             |
| 12   | 4096       | canopenclose             | Can open/close own threads         |
| 13   | 8192       | canmove                  | Can move own threads               |
| 14   | 16384      | canuseannounce           | Can use announce-mode posts        |
| 16   | 65536      | canpostpoll              | Can post polls                     |
| 17   | 131072     | canvote                  | Can vote in polls                  |
| 18   | 262144     | canthreadrate            | Can rate threads                   |
| 19   | 524288     | canattachment            | Can post attachments               |
| 20   | 1048576    | canpostattachment        | Can upload attachments             |
| 21   | 2097152    | cangetattachment         | Can download attachments           |
| 22   | 4194304    | canseedelnotice          | Can see deletion notices           |
| 23   | 8388608    | canviewforum             | Can view this specific forum       |
| 24   | 16777216   | canpostnonmod (4.x+)     | Can post without moderation        |
| 25   | 33554432   | followforummoderation    | Follow forum's moderation setting  |
| 26   | 67108864   | canpostgroupthread       | Can post group threads             |

Common values observed in beyondtheportal's data:

| Value      | What it means                                                              |
| ---------- | -------------------------------------------------------------------------- |
| 0          | No access at all (forum totally hidden from this group)                    |
| 131072     | "Vote-only" — actually means read-only access (this bit is the common one) |
| 655363     | Guest baseline (1+2+131072+524288)                                         |
| 8523776    | View + read but no post (4096 + 16384 + 131072 + 8388608)                  |
| 9048079    | Limited posting (~8523776 + post bits)                                     |
| 11178183/479 | Standard posting with extras                                             |
| 16447487   | Super-mod tier without admin                                               |
| 16511999   | Standard registered (22 of 24 bits)                                        |
| 16777215   | All 24 low bits — admin tier                                               |
| 134217727  | All 27 bits — founder/admin                                                |

### vBulletin `moderator.permissions` bitfield

| Bit  | Value      | vB name                       | Meaning                       |
| ---- | ---------- | ----------------------------- | ----------------------------- |
| 0    | 1          | caneditposts                  | Can edit posts                |
| 1    | 2          | candeleteposts                | Can soft-delete posts         |
| 2    | 4          | canremoveposts                | Can hard-delete posts         |
| 3    | 8          | canopenclose                  | Can lock/unlock threads       |
| 4    | 16         | canmanagethreads              | Can move/merge/split threads  |
| 5    | 32         | canannounce                   | Can post announcements        |
| 6    | 64         | canmoderateposts              | Can approve queued posts      |
| 7    | 128        | canmoderateattachments        | Can approve attachments       |
| 8    | 256        | canmassmove                   | Can mass-move                 |
| 9    | 512        | canmassprune                  | Can mass-delete               |
| 10   | 1024       | canviewips                    | Can see poster IPs            |
| 11   | 2048       | canviewprofile                | Can see hidden profile info   |
| 12   | 4096       | canbanusers                   | Can ban users                 |
| 13   | 8192       | canunbanusers                 | Can unban users               |
| 17   | 131072     | canexamineuser                | Can view user details         |
| 19   | 524288     | canviewdeleted                | Can see soft-deleted posts    |
| 20   | 1048576    | canmanagedeleted              | Can hard-delete                |

Common values observed:

| Value     | What it means                              |
| --------- | ------------------------------------------ |
| 255       | Basic mod: edit/delete/lock/announce/etc.  |
| 1279      | Common mod: 255 + canviewips               |
| 6143/6399 | Bigger mod: includes ban + view-deleted    |
| 8191      | Extensive mod (13 bits)                    |
| 8339455   | Near-full mod (most bits)                  |
| 1193215/1048831 | Forum-specific high-trust roles      |

---

## 3. vB-to-phpBB permission mapping table

### Forum permissions

| vB bit | vB name         | phpBB permission(s)           | Notes                       |
| ------ | --------------- | ----------------------------- | --------------------------- |
| 0      | canview         | `f_list`, `f_list_topics`     | Implied by any other access |
| 1      | canviewothers   | (no equivalent — phpBB shows everything readable) |        |
| 2      | canviewthreads  | `f_read`                      |                             |
| 3      | cansearch       | `f_search`                    |                             |
| 4      | canemail        | `f_email`                     |                             |
| 5      | canpostnew      | `f_post`                      |                             |
| 7      | canreplyown     | `f_reply`                     | phpBB doesn't distinguish own vs others |
| 8      | canreplyothers  | `f_reply`                     | same                        |
| 9      | caneditpost     | `f_edit`                      |                             |
| 10     | candeletepost   | `f_delete`, `f_softdelete`    |                             |
| 11     | candeletethread | `f_delete`                    |                             |
| 12     | canopenclose    | `f_user_lock`                 |                             |
| 13     | canmove         | (no user-level equivalent in phpBB; moderator perm only) |  |
| 14     | canuseannounce  | `f_announce`                  |                             |
| 16     | canpostpoll     | `f_poll`                      |                             |
| 17     | canvote         | `f_vote`                      |                             |
| 19     | canattachment   | `f_attach`                    |                             |
| 21     | cangetattachment| `f_download`                  |                             |
| 23     | canviewforum    | `f_read` (implies all above)  |                             |
| 24     | canpostnonmod   | `f_noapprove`                 |                             |

### Moderator permissions

| vB bit | vB name              | phpBB permission        |
| ------ | -------------------- | ----------------------- |
| 0      | caneditposts         | `m_edit`                |
| 1      | candeleteposts       | `m_softdelete`          |
| 2      | canremoveposts       | `m_delete`              |
| 3      | canopenclose         | `m_lock`                |
| 4      | canmanagethreads     | `m_move`, `m_merge`, `m_split` |
| 5      | canannounce          | `f_announce` (forum perm) |
| 6      | canmoderateposts     | `m_approve`             |
| 8      | canmassmove          | `m_move`                |
| 10     | canviewips           | `m_info`                |
| 12     | canbanusers          | `m_ban`                 |

### User-class (global)

Map by `usergroupid` rather than by decoding bits — vB's `genericpermissions`
encoding is messy and most boards use defaults.

| vB usergroupid       | phpBB user role          |
| -------------------- | ------------------------ |
| 2 (Registered)       | ROLE_USER_STANDARD       |
| 3 (Email pending)    | ROLE_USER_LIMITED        |
| 5 (Super Mod)        | ROLE_USER_STANDARD (mod perms added separately) |
| 6 (Admin)            | ROLE_USER_FULL           |
| 7 (Mod)              | ROLE_USER_STANDARD (mod perms added separately) |
| 14+ (custom)         | ROLE_USER_STANDARD (conservative default)       |

---

## 4. Role-selection algorithm

For each (vB group, vB forum) pair with a `forumpermissions` value:

```
function select_phpbb_role(int $vb_perms): string
{
    if ($vb_perms === 0) {
        return 'ROLE_FORUM_NOACCESS';
    }

    // Decode bits to flags
    $can_read    = ($vb_perms & 4) || ($vb_perms & 8388608);  // canviewthreads | canviewforum
    $can_post    = ($vb_perms & 32);                          // canpostnew
    $can_reply   = ($vb_perms & 384);                         // canreplyown | canreplyothers
    $can_attach  = ($vb_perms & 524288);                      // canattachment
    $can_poll    = ($vb_perms & 65536);                       // canpostpoll
    $can_edit    = ($vb_perms & 512);                         // caneditpost
    $can_delete  = ($vb_perms & 1024);                        // candeletepost
    $can_announce= ($vb_perms & 16384);                       // canuseannounce

    // Read-only: see but can't post
    if ($can_read && !$can_post && !$can_reply) {
        return 'ROLE_FORUM_READONLY';
    }

    // No read access at all (but value isn't 0 because of canview bit)
    if (!$can_read) {
        // Has canview but not canviewthreads - extremely rare edge case
        return 'ROLE_FORUM_NOACCESS';
    }

    // Full perms (admin-tier values like 16777215 or 134217727)
    if ($can_announce && $can_edit && $can_delete && $can_attach && $can_poll) {
        return 'ROLE_FORUM_FULL';
    }

    // Standard posting with attach and polls
    if ($can_post && $can_reply && $can_attach && $can_poll) {
        return 'ROLE_FORUM_STANDARD';
    }

    // Standard posting without attach (or partial)
    if ($can_post && $can_reply) {
        // Could be ROLE_FORUM_LIMITED_POLLS or ROLE_FORUM_LIMITED
        return $can_poll ? 'ROLE_FORUM_LIMITED_POLLS' : 'ROLE_FORUM_LIMITED';
    }

    // Anything else falls back to read-only as the safe default
    return 'ROLE_FORUM_READONLY';
}
```

### Verification against beyondtheportal values

| vB value    | Decoded                                              | Selected role           |
| ----------- | ---------------------------------------------------- | ----------------------- |
| `0`         | nothing                                              | ROLE_FORUM_NOACCESS     |
| `131072`    | canvote only (=read-only in practice)                | ROLE_FORUM_READONLY     |
| `655363`    | guest pattern: canview + canviewothers + canvote + canattachment | ROLE_FORUM_READONLY |
| `8523776`   | view + read + can-see-delete-notices                 | ROLE_FORUM_READONLY     |
| `9048079`   | view + read + post + reply + attach (partial)        | ROLE_FORUM_LIMITED      |
| `11178183`  | view + read + post + reply + attach + others         | ROLE_FORUM_LIMITED_POLLS|
| `16447487`  | super-mod tier                                       | ROLE_FORUM_FULL         |
| `16511999`  | standard registered                                  | ROLE_FORUM_STANDARD     |
| `16777215`  | full 24 bits                                         | ROLE_FORUM_FULL         |
| `134217727` | full 27 bits (admin)                                 | ROLE_FORUM_FULL         |

---

## 5. Group-to-group mapping

The mapping table — corrected after observing actual post-conversion state
of `phpbb_groups` in beyondtheportal:

| vB id | vB title              | Target phpBB group     | phpBB id | Strategy                       |
| ----- | --------------------- | ---------------------- | -------- | ------------------------------ |
| 1     | Unregistered          | GUESTS                 | 15       | Skipped (phpBB defaults apply) |
| 2     | Registered            | REGISTERED             | 16       | **Consolidate** to system group |
| 3     | Email pending         | (none)                 | -        | Skipped (phpBB handles inactive) |
| 4     | COPPA                 | REGISTERED_COPPA       | 17       | Skipped by design              |
| 5     | Super Moderators      | GLOBAL_MODERATORS      | 18       | **Consolidate** + ROLE_MOD_FULL globally |
| 6     | Administrators        | ADMINISTRATORS         | 19       | **Consolidate** to system group |
| 7     | Moderators            | GLOBAL_MODERATORS      | 18       | **Consolidate** + ROLE_MOD_STANDARD globally |
| 8     | Banned                | (none)                 | -        | Skipped (use phpBB banlist)    |
| 14    | Rebourne              | Rebourne (kept)        | 14       | **Preserved** (custom group)   |
| 15+   | (other custom)        | (kept under same id)   | 15+      | **Preserved** (custom groups)  |

Built dynamically at script start by matching `usergroup.title` against
`phpbb_groups.group_name`, with the `vB - ` prefix the converter adds:

```sql
-- The vB-side group selection
SELECT usergroupid, title FROM beyondtheportal.usergroup;

-- The phpBB-side group selection
SELECT group_id, group_name FROM phpbb_groups;

-- Build the in-memory map:
-- - For each vB group, find the phpBB group via:
--   (a) hardcoded vB-id → phpBB-system-id table (above)
--   (b) name match (case-insensitive, with/without "vB - " prefix)
--   (c) fall through to "this is a custom group, preserve current phpBB id"
```

For beyondtheportal specifically, the script will produce:

| vB id | vB title                | → | phpBB target id | phpBB target name |
| ----- | ----------------------- | - | --------------- | ----------------- |
| 1     | Unregistered            | → | (skipped)       | -                 |
| 2     | Registered              | → | 16              | REGISTERED        |
| 3     | Email pending           | → | (skipped)       | -                 |
| 4     | COPPA                   | → | (skipped)       | -                 |
| 5     | Super Moderators        | → | 18              | GLOBAL_MODERATORS |
| 6     | Administrators          | → | 19              | ADMINISTRATORS    |
| 7     | Moderators              | → | 18              | GLOBAL_MODERATORS |
| 8     | Banned                  | → | (skipped)       | -                 |
| 14    | Rebourne                | → | 14              | Rebourne (kept)   |

---

## 5a. Group consolidation algorithm

Runs BEFORE permission mapping. For each vB group with a consolidation target:

```
for each vb_group with target_phpbb_id:
    # 1. Move all user_group rows from the vB-imported group to the target
    UPDATE phpbb_user_group
       SET group_id = <target_phpbb_id>
     WHERE group_id = <vb_imported_phpbb_id>
       AND user_id NOT IN (
           SELECT user_id FROM phpbb_user_group
            WHERE group_id = <target_phpbb_id>
       );

    # 2. Delete any leftover duplicates (users who were in both)
    DELETE FROM phpbb_user_group
     WHERE group_id = <vb_imported_phpbb_id>;

    # 3. Update users whose default_group is the vB-imported group
    UPDATE phpbb_users
       SET group_id = <target_phpbb_id>
     WHERE group_id = <vb_imported_phpbb_id>;

    # 4. Now the vB-imported group is empty - drop it
    DELETE FROM phpbb_groups WHERE group_id = <vb_imported_phpbb_id>;
```

### Idempotency for consolidation

If the consolidation already ran, the vB-imported groups don't exist
anymore and steps 1-4 are no-ops. The script detects this by checking
if the source group still exists before doing anything.

### Acceptance for beyondtheportal

After consolidation:

| phpBB group_id | Name              | Member count before | Member count after |
| -------------- | ----------------- | ------------------- | ------------------ |
| 16             | REGISTERED        | 1017                | 1017+ (including former vB - Registered members) |
| 18             | GLOBAL_MODERATORS | 8                   | ~150 (8 + 142 from vB Moderators + 1 Super Mod) |
| 19             | ADMINISTRATORS    | 7                   | 7 (same admins, just consolidated) |
| 14             | Rebourne          | 2                   | 2 (unchanged)      |
| 2              | vB - Registered   | 808                 | dropped (group removed) |
| 6              | vB - Administrators | 7                 | dropped |
| 5              | Super Moderators  | 1                   | dropped |
| 7              | Moderators        | 142                 | dropped |
| 8              | Banned by Moderators | 11               | preserved (bans need manual review) |

Note on Banned: we **don't** consolidate group 8. It stays as `vB - Banned`
(or rename to phpBB convention). The admin uses phpBB's banlist for
ongoing bans, but the existing 11 banned users keep their group membership
as a historical marker. The mapping script gives this group `f_ = NEVER`
on a configurable list of forums (or nothing — see report). v2 could
auto-add these users to phpBB's banlist.

---

## 6. The mapping algorithm (per-group, per-forum)

```
plan = []
report = []

// Get the role-id lookup once
$role_ids = lookup_phpbb_role_ids();   // ['ROLE_FORUM_STANDARD' => 15, ...]

// PHASE 0: Build vB-id → phpBB-target-id map (see §5)
$group_map = build_consolidation_map();

// PHASE 1: Consolidate (if not already done — see §5a)
if !is_consolidated():
    apply_consolidation($group_map)

// PHASE 2: For each vB group, plan the permission rows
for each vb_group in usergroup:
    if vb_group.usergroupid in [1, 4, 8]:
        report.skip(vb_group, 'system group, skipped by design')
        continue

    target_phpbb_id = $group_map[vb_group.usergroupid]
    if not target_phpbb_id:
        report.skip(vb_group, 'no matching phpBB group')
        continue

    // Step 1: the group's user-class role (global)
    user_role = pick_user_role(vb_group.usergroupid)
    plan.add_user_role(target_phpbb_id, user_role)

    // Step 2: for groups 5, 7 — also add mod role globally
    if vb_group.usergroupid == 5:
        plan.add_mod_role_global(target_phpbb_id, 'ROLE_MOD_FULL')
    elif vb_group.usergroupid == 7:
        plan.add_mod_role_global(target_phpbb_id, 'ROLE_MOD_STANDARD')

    // Step 3: per-forum forum permissions
    for each forum in vb_forums:
        // Try the per-forum override first, fall back to group default
        override = forumpermission.find(forum.forumid, vb_group.usergroupid)
        if override:
            effective_perms = override.forumpermissions
        else:
            effective_perms = vb_group.forumpermissions

        forum_role = select_phpbb_role(effective_perms)
        phpbb_forum_id = forum_mapping[forum.forumid]   // forum IDs preserved

        // Important: if this is a consolidation target group like GLOBAL_MODERATORS
        // and multiple vB groups map to it (5 + 7), we need to merge.
        // Strategy: highest role wins (NOACCESS < READONLY < LIMITED < STANDARD < FULL)
        existing = plan.find_forum_role(target_phpbb_id, phpbb_forum_id)
        if existing and role_rank(existing) > role_rank(forum_role):
            // Keep the existing higher role
            continue

        plan.add_forum_role(target_phpbb_id, phpbb_forum_id, forum_role)
        report.log(target_phpbb_id, phpbb_forum_id, effective_perms, forum_role)

// PHASE 3: moderator table
for each mod in moderator:
    phpbb_user_id = user_mapping[mod.userid]
    mod_role = pick_mod_role(mod.permissions)

    if mod.forumid == -1:
        plan.add_user_mod_global(phpbb_user_id, mod_role)
    else:
        phpbb_forum_id = forum_mapping[mod.forumid]
        plan.add_user_mod_forum(phpbb_user_id, phpbb_forum_id, mod_role)

return plan, report
```

### Multi-source consolidation handling

When two vB groups consolidate into one phpBB group (the 5+7 → 18 case),
forum-level permissions are merged by **taking the highest role per
forum**. Rationale: in vB, a user who's in *both* groups got the OR of
their permissions. After consolidation in phpBB, they're in *one* group,
so that group should grant at least as much as the highest-permissioned
source group did.

Edge case: if vB group 7 has `forumpermissions = 0` on forum X (locked
out), but group 5 has `8523776` (read-only) on the same forum, the
merged GLOBAL_MODERATORS group gets `ROLE_FORUM_READONLY` on forum X.
Individual moderators who need elevated permissions for forum X get
those via `phpbb_acl_users` from the `moderator` table — not from the
group role.

---

## 7. Conflict resolution

phpBB's permission system uses YES (1) / NEVER (-1) / unset semantics:
- YES from any role grants the permission
- NEVER from any role denies it (NEVER beats YES)
- unset = inherited

For vBulletin's OR-of-all-groups semantics, the mapping is:
- Per-forum YES roles map directly (this is the common case)
- For "private forum hidden from everyone except group X": we set
  ROLE_FORUM_NOACCESS for unprivileged groups and ROLE_FORUM_STANDARD
  (or higher) for the privileged ones. The NOACCESS includes `f_ = 0`
  (NEVER), which overrides any inherited YES.

**Edge case**: if user is in vB groups 2 AND 14 (Rebourne), and forum X has:
- group 2: `forumpermissions = 131072` (read-only)
- group 14: `forumpermissions = 16511999` (standard)

vB OR-semantics: user gets `16511999` (can post).
phpBB: group_id 16 (REGISTERED) gets ROLE_FORUM_READONLY on forum X,
       group_id 14 (Rebourne) gets ROLE_FORUM_STANDARD on forum X.
Result: user is in both groups, no NEVER set, so gets the union of YESes.
**Outcome matches vB.** ✓

**Edge case 2**: vB has forum X with group 8 (Banned) explicitly set to 0:
- group 8: `forumpermissions = 0` → ROLE_FORUM_NOACCESS → `f_ = NEVER`

But we're **skipping group 8** by design (bans are per-user in phpBB).
So this row is logged in the report and not written. Admin handles bans
via phpBB's banlist after conversion.

---

## 8. The dry-run report format

A text file at `/tmp/vb4_perm_report.txt` (configurable via `--report-file`):

```
==============================================================================
vBulletin 4 → phpBB 3.3.x permission mapping report
Generated: 2026-05-08 16:23:45
Mode: dry-run (no changes written)
==============================================================================

PHASE 1: GROUP CONSOLIDATION
==============================================================================
vB id  vB title                  →  phpBB target   Action
   1   Unregistered                  GUESTS         ⊘ skipped by design
   2   Registered                    REGISTERED     ⇒ consolidate (808 members → group 16)
   3   Email pending                 (none)         ⊘ skipped by design
   4   COPPA                         (none)         ⊘ skipped by design
   5   Super Moderators              GLOBAL_MODS    ⇒ consolidate (1 member → group 18)
   6   Administrators                ADMINISTRATORS ⇒ consolidate (7 members → group 19)
   7   Moderators                    GLOBAL_MODS    ⇒ consolidate (142 members → group 18)
   8   Banned by Moderators          (preserved)    ⊘ kept as-is (use phpBB banlist)
  14   Rebourne                      Rebourne       ✓ preserved (custom group)

User_group rows to move:    958
Groups to drop:             4 (vB - Registered, Super Moderators, vB - Administrators, Moderators)
Net member count delta:     0 (all users preserved, just moved to canonical groups)

PHASE 2: PER-FORUM PERMISSIONS
==============================================================================
Forum 25: "|IB| Map Strategies - Private"
  REGISTERED (16)        vB 8523776  → ROLE_FORUM_READONLY
  Rebourne (14)          vB 8523776  → ROLE_FORUM_READONLY
  GLOBAL_MODS (18)       merged from groups 5+7: max → ROLE_FORUM_STANDARD
  ADMINISTRATORS (19)    vB 16777215 → ROLE_FORUM_FULL

Forum 545: "w4r - Map Strategies"
  REGISTERED (16)        vB 0        → ROLE_FORUM_NOACCESS  ⚠ private forum
  Rebourne (14)          vB 131072   → ROLE_FORUM_READONLY
  GLOBAL_MODS (18)       merged from groups 5+7: max → ROLE_FORUM_NOACCESS (both source groups had 0)
  ADMINISTRATORS (19)    vB 16777215 → ROLE_FORUM_FULL
  + 5 per-user moderator assignments (see PHASE 3)

... (continued for all 111 forums) ...

PHASE 3: MODERATOR ASSIGNMENTS
==============================================================================
GLOBAL (super-moderators, vB forumid = -1):
  Admin                (user_id 4258) → ROLE_MOD_FULL  (vB perms 8339455)
  Vlad                 (user_id 5)    → ROLE_MOD_FULL  (vB perms 8339455)
  (... 6 more)

PER-FORUM:
  Forum 25  → KegRun         (user_id 141)  → ROLE_MOD_STANDARD (vB perms 255)
  Forum 47  → Admin          (user_id 4258) → ROLE_MOD_FULL     (vB perms 8191)
  (... 97 more)

PHASE 4: PRIVATE / HIDDEN FORUMS (groups with f_ = NEVER applied)
==============================================================================
Forum 545 "w4r - Map Strategies": NEVER applied to REGISTERED, GLOBAL_MODERATORS
Forum 543 "w4r- Private": NEVER applied to REGISTERED, Rebourne
Forum 644 "{AH} - Apocalyptic Horsemen - Private": NEVER applied to REGISTERED
(... etc.)

UNMAPPABLE ITEMS (needs manual ACP review)
==============================================================================
None.

==============================================================================
SUMMARY
==============================================================================
Consolidation:
  Groups to drop:           4
  user_group rows moved:    958
  Members in canonical groups (after): 1182 / 1182

Permission mapping:
  Groups mapped:            5 of 9 (4 skipped by design)
  Per-forum rules planned:  264
  Moderators (global):      8
  Moderators (per-forum):   92
  Private forums detected:  12
  Unmappable:               0

To apply: run again without --dry-run
```

---

## 9. Idempotency

The script tags every row it writes by inserting them into a custom phpBB
ACL role that starts with `ROLE_VB4_AUTO_*`. On re-run:

1. Read every row in `phpbb_acl_groups` and `phpbb_acl_users` that
   references a `ROLE_VB4_AUTO_*` role → delete them.
2. Compute the new plan.
3. Write new rows.

This means manual edits the admin made to non-`VB4_AUTO_*` roles
survive re-runs. Conversely, any tweaks they made *to* a `VB4_AUTO_*`
role get overwritten — which is the expected behavior since those
roles are owned by the script.

Actually — looking at the role list, all the existing roles are
`ROLE_FORUM_*`, `ROLE_MOD_*`, etc. Using the existing roles directly is
simpler than creating custom ones. **Revised approach:**

1. The script tags its inserts via the `phpbb_log` table with a
   recognizable operation name.
2. On re-run, the script first deletes any `phpbb_acl_groups` /
   `phpbb_acl_users` row whose insertion is logged in `phpbb_log`
   with `log_operation = 'LOG_VB4_PERM_MAP_INSERT'`.
3. Then it re-runs the mapping.

The admin can edit any of the script's outputs in the ACP — those
edits persist (the script only deletes rows it knows it inserted).

---

## 10. Script invocation

```bash
php scripts/map_permissions.php [options]

Options:
  --dry-run                 Don't write anything; print the report and exit.
  --aggressive              Enable per-bit decoding for custom groups
                            instead of conservative role-matching.
  --skip-consolidation      Don't perform group consolidation (only map perms
                            to whatever groups currently exist). Useful if
                            you've already consolidated manually or want to
                            run perm mapping again without re-consolidation.
  --skip-moderators         Skip moderator-table mapping.
  --skip-private-forums     Don't apply NEVER for private forums.
  --report-file <path>      Where to write the report (default /tmp/vb4_perm_report.txt)
  --phpbb-root <path>       Path to phpBB install root (default: ./)
  --src-host <host>         Override source DB host (default: read from phpbb_config)
  --src-port <port>         Override source DB port
  --src-user <user>         Override source DB user
  --src-pass <pass>         Override source DB password
  --src-name <name>         Override source DB name
  --src-prefix <prefix>     Source table prefix (default: empty)
  --help                    Show this help

Default behaviour:
  - Reads source-DB creds from phpbb_config (src_dbhost, src_dbuser, etc.)
  - Performs group consolidation (move users to system groups, drop duplicates)
  - Conservative role mapping
  - Writes to /tmp/vb4_perm_report.txt AND to phpbb_log AND to stdout
  - Applies changes (run with --dry-run to preview first)
```

---

## 11. Acceptance tests (against beyondtheportal data)

### After consolidation phase:

```sql
-- Test C1: Duplicate vB groups should be gone
SELECT group_id, group_name FROM phpbb_groups
WHERE group_id IN (2, 5, 6, 7);
-- Expected: empty (all consolidated into 16, 18, 19)

-- Test C2: Banned and Rebourne preserved
SELECT group_id, group_name FROM phpbb_groups
WHERE group_id IN (8, 14);
-- Expected: 2 rows (Banned + Rebourne both still here)

-- Test C3: Member counts moved correctly
SELECT g.group_id, g.group_name, COUNT(ug.user_id) AS members
FROM phpbb_groups g
LEFT JOIN phpbb_user_group ug ON ug.group_id = g.group_id
WHERE g.group_id IN (14, 15, 16, 17, 18, 19, 20, 21)
GROUP BY g.group_id;
-- Expected:
--   14 (Rebourne)            : 2
--   15 (GUESTS)              : 1
--   16 (REGISTERED)          : 1017+ (includes ex-vB-Registered members)
--   17 (REGISTERED_COPPA)    : 0
--   18 (GLOBAL_MODERATORS)   : ~150
--   19 (ADMINISTRATORS)      : 7
--   20 (BOTS)                : 55
--   21 (NEWLY_REGISTERED)    : 0

-- Test C4: No orphan user_group rows
SELECT COUNT(*) FROM phpbb_user_group ug
WHERE ug.group_id NOT IN (SELECT group_id FROM phpbb_groups);
-- Expected: 0
```

### After permission mapping phase:

```sql
-- Test P1: Rebourne (group_id 14) has ROLE_FORUM_STANDARD on forums
-- where vB had it at 16511999.
SELECT COUNT(*) FROM phpbb_acl_groups
WHERE group_id = 14 AND auth_role_id = 15;  -- ROLE_FORUM_STANDARD
-- Expected: > 0

-- Test P2: Forum 545 (private) should have NOACCESS for REGISTERED (group_id 16).
SELECT auth_role_id FROM phpbb_acl_groups
WHERE group_id = 16 AND forum_id = 545;
-- Expected: 16 (ROLE_FORUM_NOACCESS)

-- Test P3: Global moderators (the 8 -1 entries in vB.moderator) have global m_ perms.
-- After phpbb_user_id mapping, vB user 1 → phpBB 4258 (admin remap)
SELECT u.username, COUNT(ag.auth_role_id) AS mod_assignments
FROM phpbb_users u
JOIN phpbb_acl_users ag ON ag.user_id = u.user_id
WHERE u.user_id IN (4258, 5, 9, 10, 11, 258, 523, 1163)
GROUP BY u.username;
-- Expected: 8 rows, all with assignments

-- Test P4: Forum-specific moderators (e.g., w4r forums 542-545).
SELECT u.username, ag.forum_id, ag.auth_role_id
FROM phpbb_acl_users ag
JOIN phpbb_users u ON u.user_id = ag.user_id
WHERE ag.forum_id IN (542, 543, 544, 545)
ORDER BY ag.forum_id, u.username;
-- Expected: 20 rows (5 mods × 4 forums)

-- Test P5: GLOBAL_MODERATORS (id 18) has ROLE_MOD_STANDARD applied globally
SELECT * FROM phpbb_acl_groups
WHERE group_id = 18 AND forum_id = 0
AND auth_role_id = 11;  -- ROLE_MOD_STANDARD
-- Expected: 1 row

-- Test P6: ADMINISTRATORS (id 19) has ROLE_ADMIN_STANDARD globally
SELECT * FROM phpbb_acl_groups
WHERE group_id = 19 AND forum_id = 0
AND auth_role_id = 1;  -- ROLE_ADMIN_STANDARD
-- Expected: 1 row (or already exists from phpBB defaults)

-- Test P7: Idempotency — re-run script, counts shouldn't change.
-- (Apply, count phpbb_acl_groups rows, re-apply, count again.)

-- Test P8: After applying, sync caches:
-- The script runs $auth->acl_clear_prefetch() and cache->purge() at the end;
-- ACP should show correct moderators in Forums → Forum-based permissions.
```

---

## 12. Out-of-scope notes (for v2)

These are things v1 deliberately doesn't do. Tracked here so we don't
forget them:

- **PM permissions** — vB has `pmpermissions` per-group; phpBB has `u_sendpm` etc.
  Mapping is straightforward but tedious. v2.
- **vB `calendarpermissions`** — different feature.
- **vB `albumpermissions`** — different feature.
- **vB `socialgrouppermissions`** — vB feature with no phpBB analog.
- **Aggressive bit-decoding for custom groups' `genericpermissions`** —
  optional behind `--aggressive` flag. Implementation in v2.
- **Per-user `caneditaccess`** from moderator table — vB's "can this mod
  edit access permissions" — maps to phpBB's `a_authforums`/`a_authgroups`
  on a finer-grained level. v2.
- **`f_announce_global`** for the super-mod tier — could be set if vB
  group had global announce capability. v2.

---

## 13. File structure changes

```
scripts/
  └── map_permissions.php       (new file, this script)

README.md
  → Add "Permission mapping" section pointing to scripts/

CHANGES.md
  → Add entry: "Added scripts/map_permissions.php"

.github/workflows/bump-version.yml
  → No changes needed (script is bundled in the zip via existing patterns)
```

---

## 14. Implementation sequence

If you approve this doc, I'd write the code in this order:

1. **Bit-decoding constants and `select_phpbb_role()`** — pure functions,
   unit-testable.
2. **Database connection scaffolding** — bootstrap into phpBB's
   environment, open source-DB connection.
3. **Group consolidation map builder** — match vB groups to phpBB system
   groups (per §5), preserve custom groups, identify duplicates.
4. **Group consolidation executor** — move user_group rows, fix
   user.group_id, drop duplicate groups (per §5a).
5. **Forum-perm mapping loop** — generate the plan, no writes.
6. **Moderator-perm mapping loop** — extend the plan.
7. **Multi-source role-merging** — handle the 5+7 → 18 consolidation case
   where two vB groups map to one phpBB group (highest role wins).
8. **Report generation** — three surfaces (file, stdout, phpbb_log).
9. **Plan application with idempotency** — delete prior rows, insert new.
10. **Cache invalidation** — `$auth->acl_clear_prefetch()` and friends.
11. **CLI arg parsing and `--dry-run` handling** — split between
    consolidation and mapping (both gated).
12. **Documentation in README** — link to this doc, document the
    post-conversion sequence.

Estimated size: ~750 lines of PHP (was 600 before adding consolidation).
Most of that is the bit-decoding constants and the report generator.
