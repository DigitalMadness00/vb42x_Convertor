<?php
/**
* vBulletin 4 → phpBB 3.3.x permission mapping & group consolidation
*
* Standalone post-conversion utility. Run AFTER the main converter completes.
*
*   php scripts/map_permissions.php --dry-run     # preview, no writes
*   php scripts/map_permissions.php               # apply changes
*
* See docs/permission_mapping_design.md for the full design spec.
*
* @copyright (c) 2026 DigitalMadness00
* @license   GPL-2.0
* @version   0.0.12
*/

// -----------------------------------------------------------------------
// CLI argument parsing
// -----------------------------------------------------------------------

$opts = [
	'dry-run'              => false,
	'aggressive'           => false,
	'skip-consolidation'   => false,
	'skip-moderators'      => false,
	'skip-private-forums'  => false,
	'report-file'          => '/tmp/vb4_perm_report.txt',
	'phpbb-root'           => __DIR__ . '/..',
	'src-host'             => null,
	'src-port'             => null,
	'src-user'             => null,
	'src-pass'             => null,
	'src-name'             => null,
	'src-prefix'           => '',
];

foreach (array_slice($argv, 1) as $arg)
{
	if ($arg === '--help' || $arg === '-h')
	{
		print_help();
		exit(0);
	}

	if (preg_match('/^--([a-z\-]+)(?:=(.*))?$/', $arg, $m))
	{
		$key   = $m[1];
		$value = $m[2] ?? true;

		if (!array_key_exists($key, $opts))
		{
			fwrite(STDERR, "Unknown option: --$key\n");
			fwrite(STDERR, "Run with --help to see available options.\n");
			exit(1);
		}

		$opts[$key] = $value;
		continue;
	}

	fwrite(STDERR, "Unexpected argument: $arg\n");
	exit(1);
}

function print_help()
{
	echo <<<HELP
vBulletin 4 → phpBB 3.3.x permission mapping & group consolidation

Usage:
  php scripts/map_permissions.php [options]

Options:
  --dry-run                 Don't write anything; print the report and exit.
  --aggressive              Per-bit decoding for custom groups (v2 feature, stub for now).
  --skip-consolidation      Don't move users between groups / drop duplicates.
  --skip-moderators         Skip moderator-table mapping.
  --skip-private-forums     Don't apply NEVER for private forums.
  --report-file=<path>      Where to write the text report (default /tmp/vb4_perm_report.txt)
  --phpbb-root=<path>       Path to phpBB install root (default: parent of script)
  --src-host=<host>         Source DB host (default: read from phpbb_config)
  --src-port=<port>         Source DB port
  --src-user=<user>         Source DB user
  --src-pass=<pass>         Source DB password
  --src-name=<dbname>       Source DB name (the vBulletin database)
  --src-prefix=<prefix>     Source table prefix (default: empty)
  -h, --help                Show this help.

The script reads vBulletin permission tables from the SOURCE database
(usergroup, forumpermission, moderator, forum), maps them into phpBB's
ACL system, and writes role assignments to phpbb_acl_groups and
phpbb_acl_users.

Run --dry-run first to preview, then run without it to apply.

HELP;
}

// -----------------------------------------------------------------------
// Bootstrap phpBB
// -----------------------------------------------------------------------

$phpbb_root_path = rtrim($opts['phpbb-root'], '/') . '/';
$phpEx           = 'php';

if (!is_file($phpbb_root_path . 'common.' . $phpEx))
{
	fwrite(STDERR, "Cannot find phpBB at: $phpbb_root_path\n");
	fwrite(STDERR, "Use --phpbb-root=<path> to specify the phpBB install root.\n");
	exit(1);
}

define('IN_PHPBB', true);

// CLI mode - some phpBB code paths assume we have $_SERVER set
if (!isset($_SERVER['REMOTE_ADDR']))     $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
if (!isset($_SERVER['HTTP_HOST']))        $_SERVER['HTTP_HOST'] = 'cli';
if (!isset($_SERVER['REQUEST_METHOD']))   $_SERVER['REQUEST_METHOD'] = 'GET';
if (!isset($_SERVER['REQUEST_URI']))      $_SERVER['REQUEST_URI'] = '/cli';

require($phpbb_root_path . 'common.' . $phpEx);

/** @var \phpbb\db\driver\driver_interface $db */
/** @var \phpbb\config\config              $config */
/** @var \phpbb\cache\service              $cache */
/** @var \phpbb\auth\auth                  $auth */
/** @var \phpbb\user                       $user */

global $db, $config, $cache, $auth, $user, $phpbb_container;

$user->session_begin();
$auth->acl($user->data);

echo "vBulletin 4 → phpBB 3.3.x permission mapper\n";
echo "Mode: " . ($opts['dry-run'] ? "DRY-RUN (no writes)" : "APPLY") . "\n";
echo "phpBB root: $phpbb_root_path\n";
echo str_repeat('=', 78) . "\n\n";

// -----------------------------------------------------------------------
// Source DB connection (the vBulletin database)
// -----------------------------------------------------------------------

$src_creds = read_source_creds($opts, $config);

if (!$src_creds['name'])
{
	fwrite(STDERR, "Cannot determine source database name.\n");
	fwrite(STDERR, "Either set --src-name=<vb_database> or run the converter first\n");
	fwrite(STDERR, "(which leaves source creds in phpbb_config as src_* entries).\n");
	exit(1);
}

echo "Source vBulletin database: {$src_creds['name']}@{$src_creds['host']} (prefix='{$src_creds['prefix']}')\n";

$src_db = new mysqli($src_creds['host'], $src_creds['user'], $src_creds['pass'], $src_creds['name'], (int) ($src_creds['port'] ?: 3306));
if ($src_db->connect_error)
{
	fwrite(STDERR, "Source DB connection failed: {$src_db->connect_error}\n");
	exit(1);
}
$src_db->set_charset('utf8mb4');

function read_source_creds(array $opts, $config): array
{
	// Fallback chain: CLI flag → src_* in phpbb_config (set during conversion)
	// → phpBB's own DB config (which globals are set from config.php at bootstrap).
	// The third fallback handles the common case where the converter cleared
	// the src_* config rows after success and the user has co-located DBs.
	global $dbhost, $dbport, $dbuser, $dbpasswd, $dbname, $table_prefix;

	$host = $opts['src-host'] !== null ? $opts['src-host']
	     : ($config['src_dbhost']   ?? $dbhost   ?? 'localhost');

	$port = $opts['src-port'] !== null ? $opts['src-port']
	     : ($config['src_dbport']   ?? $dbport   ?? null);

	$user = $opts['src-user'] !== null ? $opts['src-user']
	     : ($config['src_dbuser']   ?? $dbuser   ?? null);

	$pass = $opts['src-pass'] !== null ? $opts['src-pass']
	     : ($config['src_dbpasswd'] ?? $dbpasswd ?? null);

	$name = $opts['src-name'] !== null ? $opts['src-name']
	     : ($config['src_dbname']   ?? null);

	$prefix = $opts['src-prefix'] !== '' ? $opts['src-prefix']
	       : ($config['src_table_prefix'] ?? '');

	return compact('host', 'port', 'user', 'pass', 'name', 'prefix');
}

// -----------------------------------------------------------------------
// Role and option lookups (phpBB side)
// -----------------------------------------------------------------------

$role_ids = lookup_role_ids($db);
$auth_opts = lookup_auth_options($db);

function lookup_role_ids($db): array
{
	$out = [];
	$sql = 'SELECT role_id, role_name FROM ' . ACL_ROLES_TABLE;
	$result = $db->sql_query($sql);
	while ($row = $db->sql_fetchrow($result))
	{
		$out[$row['role_name']] = (int) $row['role_id'];
	}
	$db->sql_freeresult($result);
	return $out;
}

function lookup_auth_options($db): array
{
	$out = [];
	$sql = 'SELECT auth_option_id, auth_option FROM ' . ACL_OPTIONS_TABLE;
	$result = $db->sql_query($sql);
	while ($row = $db->sql_fetchrow($result))
	{
		$out[$row['auth_option']] = (int) $row['auth_option_id'];
	}
	$db->sql_freeresult($result);
	return $out;
}

// Sanity-check that the roles we need actually exist
$required_roles = [
	'ROLE_FORUM_NOACCESS', 'ROLE_FORUM_READONLY',
	'ROLE_FORUM_LIMITED', 'ROLE_FORUM_LIMITED_POLLS',
	'ROLE_FORUM_STANDARD', 'ROLE_FORUM_FULL',
	'ROLE_MOD_STANDARD', 'ROLE_MOD_FULL',
	'ROLE_USER_STANDARD', 'ROLE_USER_LIMITED', 'ROLE_USER_FULL',
];
foreach ($required_roles as $r)
{
	if (!isset($role_ids[$r]))
	{
		fwrite(STDERR, "phpBB role '$r' not found - your phpBB install may be too old.\n");
		exit(1);
	}
}

// -----------------------------------------------------------------------
// vB → phpBB bit-decoding (the heart of the mapping)
// -----------------------------------------------------------------------

// vB forum permission bits (cross-referenced from vB4 source)
const VB_CAN_VIEW           = 1;          // bit 0
const VB_CAN_VIEW_OTHERS    = 2;          // bit 1
const VB_CAN_VIEW_THREADS   = 4;          // bit 2
const VB_CAN_SEARCH         = 8;          // bit 3
const VB_CAN_EMAIL          = 16;         // bit 4
const VB_CAN_POSTNEW        = 32;         // bit 5
const VB_CAN_REPLY_OWN      = 128;        // bit 7
const VB_CAN_REPLY_OTHERS   = 256;        // bit 8
const VB_CAN_EDIT_POST      = 512;        // bit 9
const VB_CAN_DELETE_POST    = 1024;       // bit 10
const VB_CAN_DELETE_THREAD  = 2048;       // bit 11
const VB_CAN_OPENCLOSE      = 4096;       // bit 12
const VB_CAN_MOVE           = 8192;       // bit 13
const VB_CAN_ANNOUNCE       = 16384;      // bit 14
const VB_CAN_POSTPOLL       = 65536;      // bit 16
const VB_CAN_VOTE           = 131072;     // bit 17
const VB_CAN_ATTACHMENT     = 524288;     // bit 19
const VB_CAN_VIEW_FORUM     = 8388608;    // bit 23
const VB_CAN_POST_NONMOD    = 16777216;   // bit 24

// vB moderator permission bits
const VB_MOD_EDIT_POSTS        = 1;
const VB_MOD_DELETE_POSTS      = 2;       // soft-delete
const VB_MOD_REMOVE_POSTS      = 4;       // hard-delete
const VB_MOD_OPENCLOSE         = 8;
const VB_MOD_MANAGE_THREADS    = 16;      // move/merge/split
const VB_MOD_ANNOUNCE          = 32;
const VB_MOD_MODERATE_POSTS    = 64;
const VB_MOD_MASS_MOVE         = 256;
const VB_MOD_VIEW_IPS          = 1024;
const VB_MOD_BAN_USERS         = 4096;

// Rank ordering for "highest role wins" in multi-source merges
const ROLE_RANK = [
	'ROLE_FORUM_NOACCESS'      => 0,
	'ROLE_FORUM_READONLY'      => 1,
	'ROLE_FORUM_LIMITED'       => 2,
	'ROLE_FORUM_LIMITED_POLLS' => 3,
	'ROLE_FORUM_STANDARD'      => 4,
	'ROLE_FORUM_FULL'          => 5,
];

/**
* Decode a vB forumpermissions bitfield and pick the closest phpBB role.
*/
function select_forum_role(int $vb_perms): string
{
	if ($vb_perms === 0)
	{
		return 'ROLE_FORUM_NOACCESS';
	}

	// vB's canvote bit (131072) is widely used as a "forum visible but not
	// readable" marker — equivalent to vB's "private" forum convention. In
	// that mode the forum shows in the listing but you can't read threads.
	// phpBB has no exact equivalent: ROLE_FORUM_READONLY grants thread
	// reading. We map the canvote-only-bit-set case to NOACCESS (closer to
	// the operator's intent on most boards), then let the admin override
	// in the ACP if they wanted readonly access.
	//
	// True read access requires canviewthreads (bit 2) or canviewforum
	// (bit 23) — both are commonly set together with other content bits.
	$can_read     = (bool) ($vb_perms & (VB_CAN_VIEW_THREADS | VB_CAN_VIEW_FORUM));
	$can_post     = (bool) ($vb_perms & VB_CAN_POSTNEW);
	$can_reply    = (bool) ($vb_perms & (VB_CAN_REPLY_OWN | VB_CAN_REPLY_OTHERS));
	$can_attach   = (bool) ($vb_perms & VB_CAN_ATTACHMENT);
	$can_poll     = (bool) ($vb_perms & VB_CAN_POSTPOLL);
	$can_edit     = (bool) ($vb_perms & VB_CAN_EDIT_POST);
	$can_delete   = (bool) ($vb_perms & VB_CAN_DELETE_POST);
	$can_announce = (bool) ($vb_perms & VB_CAN_ANNOUNCE);

	if (!$can_read)
	{
		// Has some bits set but not the read bits — usually 131072 (canvote)
		// alone, indicating a "private" forum in vB terminology.
		return 'ROLE_FORUM_NOACCESS';
	}

	if (!$can_post && !$can_reply)
	{
		return 'ROLE_FORUM_READONLY';
	}

	// Has post/reply access. Pick the most-similar role based on what else is granted.
	if ($can_announce && $can_edit && $can_delete && $can_attach && $can_poll)
	{
		return 'ROLE_FORUM_FULL';
	}

	if ($can_post && $can_reply && $can_attach && $can_poll)
	{
		return 'ROLE_FORUM_STANDARD';
	}

	if ($can_post && $can_reply)
	{
		return $can_poll ? 'ROLE_FORUM_LIMITED_POLLS' : 'ROLE_FORUM_LIMITED';
	}

	return 'ROLE_FORUM_READONLY';
}

/**
* Decode a vB moderator.permissions bitfield and pick the closest phpBB role.
*/
function select_mod_role(int $vb_perms): string
{
	// Count how many "serious" mod bits are set
	$serious_bits = (
		(($vb_perms & VB_MOD_BAN_USERS)      ? 1 : 0) +
		(($vb_perms & VB_MOD_REMOVE_POSTS)   ? 1 : 0) +
		(($vb_perms & VB_MOD_MODERATE_POSTS) ? 1 : 0) +
		(($vb_perms & VB_MOD_MASS_MOVE)      ? 1 : 0)
	);

	// If they have ban + can-do-most-things, that's MOD_FULL
	if (($vb_perms & VB_MOD_BAN_USERS) && $serious_bits >= 3)
	{
		return 'ROLE_MOD_FULL';
	}

	return 'ROLE_MOD_STANDARD';
}

// -----------------------------------------------------------------------
// PHASE 0: Group consolidation map
// -----------------------------------------------------------------------

echo "PHASE 0: Building group consolidation map\n";
echo str_repeat('-', 78) . "\n";

// Hardcoded vB-system-id → phpBB-system-group-name mapping per the design doc
$vb_to_phpbb_system = [
	1 => null,                 // Unregistered - skip (phpBB defaults)
	2 => 'REGISTERED',
	3 => null,                 // Email pending - skip
	4 => null,                 // COPPA - skip
	5 => 'GLOBAL_MODERATORS',
	6 => 'ADMINISTRATORS',
	7 => 'GLOBAL_MODERATORS',
	8 => null,                 // Banned - skip (use phpBB banlist)
];

// Read vB groups
$vb_groups = [];
$sql = "SELECT usergroupid, title, description, forumpermissions, ispublicgroup
        FROM `{$src_creds['prefix']}usergroup`
        ORDER BY usergroupid";
$res = $src_db->query($sql);
if (!$res)
{
	fwrite(STDERR, "Failed to read source usergroup table: {$src_db->error}\n");
	exit(1);
}
while ($row = $res->fetch_assoc())
{
	$vb_groups[(int) $row['usergroupid']] = $row;
}
$res->free();

// Read phpBB groups
$phpbb_groups = [];        // group_id → row
$phpbb_groups_by_name = []; // upper(group_name) → group_id
$sql = 'SELECT group_id, group_name, group_type FROM ' . GROUPS_TABLE;
$result = $db->sql_query($sql);
while ($row = $db->sql_fetchrow($result))
{
	$phpbb_groups[(int) $row['group_id']] = $row;
	$phpbb_groups_by_name[strtoupper($row['group_name'])] = (int) $row['group_id'];
}
$db->sql_freeresult($result);

// Build the consolidation plan
$consolidation = []; // vb_id => ['source_phpbb_id'=>int, 'target_phpbb_id'=>int, 'action'=>str, 'note'=>str]

foreach ($vb_groups as $vb_id => $vb_g)
{
	$vb_title = $vb_g['title'];
	$entry = ['vb_id' => $vb_id, 'vb_title' => $vb_title];

	// Step 1: find the vB-imported phpBB group (either "vB - Title" or "Title")
	$source_phpbb_id = null;
	foreach (["vB - $vb_title", $vb_title] as $candidate)
	{
		if (isset($phpbb_groups_by_name[strtoupper($candidate)]))
		{
			$source_phpbb_id = $phpbb_groups_by_name[strtoupper($candidate)];
			break;
		}
	}
	$entry['source_phpbb_id'] = $source_phpbb_id;
	$entry['source_phpbb_name'] = $source_phpbb_id ? $phpbb_groups[$source_phpbb_id]['group_name'] : null;

	// Step 2: decide what to do
	if (array_key_exists($vb_id, $vb_to_phpbb_system))
	{
		$target_name = $vb_to_phpbb_system[$vb_id];
		if ($target_name === null)
		{
			$entry['action']           = 'skip';
			$entry['target_phpbb_id']  = null;
			$entry['note']             = 'skipped by design';
		}
		else
		{
			$target_id = $phpbb_groups_by_name[$target_name] ?? null;
			if ($target_id && $source_phpbb_id && $target_id !== $source_phpbb_id)
			{
				$entry['action']          = 'consolidate';
				$entry['target_phpbb_id'] = $target_id;
				$entry['note']            = "consolidate into $target_name";
			}
			elseif ($target_id === $source_phpbb_id || !$source_phpbb_id)
			{
				$entry['action']          = 'map_only';
				$entry['target_phpbb_id'] = $target_id;
				$entry['note']            = 'already in system group';
			}
			else
			{
				$entry['action']          = 'skip';
				$entry['target_phpbb_id'] = null;
				$entry['note']            = "target $target_name not found in phpBB";
			}
		}
	}
	else
	{
		// Custom group (e.g. Rebourne) - preserve it
		if ($source_phpbb_id)
		{
			$entry['action']          = 'preserve';
			$entry['target_phpbb_id'] = $source_phpbb_id;
			$entry['note']            = 'custom group preserved';
		}
		else
		{
			$entry['action']          = 'skip';
			$entry['target_phpbb_id'] = null;
			$entry['note']            = 'custom group has no phpBB equivalent';
		}
	}

	$consolidation[$vb_id] = $entry;
}

foreach ($consolidation as $vb_id => $e)
{
	printf("  vB %3d  %-30s  →  %-25s  (%s) %s\n",
		$vb_id, mb_substr($e['vb_title'], 0, 28),
		$e['target_phpbb_id'] ? ($phpbb_groups[$e['target_phpbb_id']]['group_name'] ?? '?') : '(none)',
		$e['action'],
		$e['note']
	);
}

echo "\n";

// -----------------------------------------------------------------------
// PHASE 1: Execute group consolidation
// -----------------------------------------------------------------------

$consolidation_summary = ['moved' => 0, 'dropped' => 0];

if (!$opts['skip-consolidation'])
{
	echo "PHASE 1: Group consolidation\n";
	echo str_repeat('-', 78) . "\n";

	foreach ($consolidation as $vb_id => $e)
	{
		if ($e['action'] !== 'consolidate') continue;

		$src = $e['source_phpbb_id'];
		$dst = $e['target_phpbb_id'];

		// Count rows that will be moved (those that don't already exist in destination)
		$sql = 'SELECT COUNT(*) AS n FROM ' . USER_GROUP_TABLE . "
		        WHERE group_id = $src
		        AND user_id NOT IN (SELECT user_id FROM " . USER_GROUP_TABLE . " WHERE group_id = $dst)";
		$result = $db->sql_query($sql);
		$movable = (int) $db->sql_fetchfield('n');
		$db->sql_freeresult($result);

		// Count duplicates (rows in source where user is already in destination)
		$sql = 'SELECT COUNT(*) AS n FROM ' . USER_GROUP_TABLE . " src
		        WHERE src.group_id = $src
		        AND EXISTS (SELECT 1 FROM " . USER_GROUP_TABLE . " dst
		                    WHERE dst.group_id = $dst AND dst.user_id = src.user_id)";
		$result = $db->sql_query($sql);
		$duplicates = (int) $db->sql_fetchfield('n');
		$db->sql_freeresult($result);

		printf("  Group %d (%s) → %d (%s):  move %d row(s), drop %d duplicate(s), drop source group\n",
			$src, $e['source_phpbb_name'],
			$dst, $phpbb_groups[$dst]['group_name'],
			$movable, $duplicates
		);

		// Count the plan regardless of dry-run / apply mode
		$consolidation_summary['moved']   += $movable;
		$consolidation_summary['dropped']++;

		if (!$opts['dry-run'])
		{
			// Move movable rows
			$sql = 'UPDATE ' . USER_GROUP_TABLE . "
			        SET group_id = $dst
			        WHERE group_id = $src
			        AND user_id NOT IN (SELECT user_id FROM (
			            SELECT user_id FROM " . USER_GROUP_TABLE . " WHERE group_id = $dst
			        ) AS d)";
			$db->sql_query($sql);

			// Delete duplicates (users already in destination)
			$sql = 'DELETE FROM ' . USER_GROUP_TABLE . " WHERE group_id = $src";
			$db->sql_query($sql);

			// Fix users whose default group was the source
			$sql = 'UPDATE ' . USERS_TABLE . " SET group_id = $dst WHERE group_id = $src";
			$db->sql_query($sql);

			// Drop the now-empty source group
			$sql = 'DELETE FROM ' . GROUPS_TABLE . " WHERE group_id = $src";
			$db->sql_query($sql);
		}
	}

	echo "\n";
}
else
{
	echo "PHASE 1: SKIPPED (--skip-consolidation)\n\n";
}

// -----------------------------------------------------------------------
// PHASE 2: Per-forum permission planning
// -----------------------------------------------------------------------

echo "PHASE 2: Planning per-forum permissions\n";
echo str_repeat('-', 78) . "\n";

// Re-read phpBB groups (may have changed after consolidation)
$phpbb_groups = [];
$sql = 'SELECT group_id, group_name FROM ' . GROUPS_TABLE;
$result = $db->sql_query($sql);
while ($row = $db->sql_fetchrow($result))
{
	$phpbb_groups[(int) $row['group_id']] = $row['group_name'];
}
$db->sql_freeresult($result);

// Read vB forum permission overrides
$forum_perms = []; // [usergroupid][forumid] = forumpermissions
$sql = "SELECT forumid, usergroupid, forumpermissions
        FROM `{$src_creds['prefix']}forumpermission`";
$res = $src_db->query($sql);
while ($row = $res->fetch_assoc())
{
	$forum_perms[(int) $row['usergroupid']][(int) $row['forumid']] = (int) $row['forumpermissions'];
}
$res->free();

// Read vB forum list (we need the ids)
$vb_forums = [];
$sql = "SELECT forumid, title FROM `{$src_creds['prefix']}forum` WHERE forumid > 0";
$res = $src_db->query($sql);
while ($row = $res->fetch_assoc())
{
	$vb_forums[(int) $row['forumid']] = $row['title'];
}
$res->free();

// Read phpBB forums (to know which forum IDs actually exist on the destination)
$phpbb_forums = [];
$sql = 'SELECT forum_id, forum_name FROM ' . FORUMS_TABLE;
$result = $db->sql_query($sql);
while ($row = $db->sql_fetchrow($result))
{
	$phpbb_forums[(int) $row['forum_id']] = $row['forum_name'];
}
$db->sql_freeresult($result);

// Build the plan: per (target_phpbb_group, forum) → role
$forum_plan = [];        // [phpbb_group_id][forum_id] = role_name
$forum_plan_meta = [];   // [phpbb_group_id][forum_id] = ['source_vb_groups' => [], 'vb_perms_seen' => []]

foreach ($consolidation as $vb_id => $e)
{
	if (in_array($e['action'], ['skip'])) continue;

	$target = $e['target_phpbb_id'];
	if (!$target) continue;

	$vb_group_default = (int) $vb_groups[$vb_id]['forumpermissions'];

	foreach ($vb_forums as $forum_id => $forum_title)
	{
		// Skip forums that don't exist in destination
		if (!isset($phpbb_forums[$forum_id])) continue;

		// Effective perms = per-forum override if present, else group default
		$effective = $forum_perms[$vb_id][$forum_id] ?? $vb_group_default;
		$role = select_forum_role($effective);

		// Multi-source merge: keep the highest role per (target_group, forum)
		if (isset($forum_plan[$target][$forum_id]))
		{
			$existing = $forum_plan[$target][$forum_id];
			if ((ROLE_RANK[$role] ?? 0) > (ROLE_RANK[$existing] ?? 0))
			{
				$forum_plan[$target][$forum_id] = $role;
			}
		}
		else
		{
			$forum_plan[$target][$forum_id] = $role;
		}

		$forum_plan_meta[$target][$forum_id]['source_vb_groups'][] = $vb_id;
		$forum_plan_meta[$target][$forum_id]['vb_perms_seen'][] = $effective;
	}
}

$forum_rules_count = 0;
foreach ($forum_plan as $g => $f) $forum_rules_count += count($f);

echo "  Forum permission rules planned: $forum_rules_count\n\n";

// -----------------------------------------------------------------------
// PHASE 3: Moderator planning
// -----------------------------------------------------------------------

$mod_plan_global = []; // user_id => role_name
$mod_plan_forum  = []; // [user_id][forum_id] = role_name

if (!$opts['skip-moderators'])
{
	echo "PHASE 3: Planning moderator assignments\n";
	echo str_repeat('-', 78) . "\n";

	// Build vB userid → phpBB user_id mapping (mostly identity, except admin remap)
	$user_id_map = [];
	$increment   = (int) ($config['increment_user_id'] ?? 0);

	// vB moderator rows
	$sql = "SELECT moderatorid, forumid, userid, permissions
	        FROM `{$src_creds['prefix']}moderator`";
	$res = $src_db->query($sql);
	$mod_rows = [];
	while ($row = $res->fetch_assoc())
	{
		$mod_rows[] = $row;
	}
	$res->free();

	// For each unique vB userid, look up the phpBB user_id
	$vb_userids = array_unique(array_map(fn($r) => (int) $r['userid'], $mod_rows));
	if (!empty($vb_userids))
	{
		// The conversion preserved userids (mostly). Admin userid 1 was remapped to max+1.
		foreach ($vb_userids as $vb_uid)
		{
			if ($vb_uid === 1 && $increment > 0)
			{
				$user_id_map[$vb_uid] = $increment;
			}
			else
			{
				$user_id_map[$vb_uid] = $vb_uid;
			}
		}

		// Verify these phpBB user_ids actually exist
		$check_ids = implode(',', array_map('intval', array_values($user_id_map)));
		$sql = 'SELECT user_id FROM ' . USERS_TABLE . " WHERE user_id IN ($check_ids)";
		$result = $db->sql_query($sql);
		$existing = [];
		while ($row = $db->sql_fetchrow($result))
		{
			$existing[(int) $row['user_id']] = true;
		}
		$db->sql_freeresult($result);

		foreach ($user_id_map as $vb_uid => $phpbb_uid)
		{
			if (!isset($existing[$phpbb_uid]))
			{
				unset($user_id_map[$vb_uid]);
			}
		}
	}

	foreach ($mod_rows as $row)
	{
		$vb_userid = (int) $row['userid'];
		$forum_id  = (int) $row['forumid'];
		$vb_perms  = (int) $row['permissions'];

		if (!isset($user_id_map[$vb_userid])) continue;
		$phpbb_user_id = $user_id_map[$vb_userid];

		$mod_role = select_mod_role($vb_perms);

		if ($forum_id === -1)
		{
			// Super-moderator (global)
			$mod_plan_global[$phpbb_user_id] = $mod_role;
		}
		else
		{
			// Per-forum moderator - only if forum exists in destination
			if (isset($phpbb_forums[$forum_id]))
			{
				$mod_plan_forum[$phpbb_user_id][$forum_id] = $mod_role;
			}
		}
	}

	echo "  Global super-moderator assignments: " . count($mod_plan_global) . "\n";
	$per_forum_count = 0;
	foreach ($mod_plan_forum as $u => $forums) $per_forum_count += count($forums);
	echo "  Per-forum moderator assignments:    $per_forum_count\n\n";
}
else
{
	echo "PHASE 3: SKIPPED (--skip-moderators)\n\n";
}

// -----------------------------------------------------------------------
// PHASE 4: Apply the plan
// -----------------------------------------------------------------------

$applied = ['acl_groups' => 0, 'acl_users' => 0];

if ($opts['dry-run'])
{
	echo "PHASE 4: SKIPPED (dry-run mode)\n\n";
}
else
{
	echo "PHASE 4: Applying permission plan\n";
	echo str_repeat('-', 78) . "\n";

	// Idempotency: tag our prior writes via phpbb_log so re-runs can clean up.
	// Find rows we previously inserted.
	$sql = 'SELECT log_data FROM ' . LOG_TABLE . "
	        WHERE log_operation = 'LOG_VB4_PERM_MAP_INSERT'";
	$result = $db->sql_query($sql);
	$prior_inserts = ['acl_groups' => [], 'acl_users' => []];
	while ($row = $db->sql_fetchrow($result))
	{
		$data = @unserialize($row['log_data'], ['allowed_classes' => false]);
		if (is_array($data))
		{
			if (!empty($data['acl_groups'])) $prior_inserts['acl_groups'] = array_merge($prior_inserts['acl_groups'], $data['acl_groups']);
			if (!empty($data['acl_users'])) $prior_inserts['acl_users']  = array_merge($prior_inserts['acl_users'], $data['acl_users']);
		}
	}
	$db->sql_freeresult($result);

	// Delete prior writes from acl_groups
	foreach (array_chunk($prior_inserts['acl_groups'], 200) as $chunk)
	{
		if (empty($chunk)) continue;
		$quoted = array_map(function($k) use ($db) {
			return "'" . $db->sql_escape($k) . "'";
		}, $chunk);
		$in_list = implode(',', $quoted);
		$db->sql_query('DELETE FROM ' . ACL_GROUPS_TABLE . " WHERE auth_role_id <> 0 AND CONCAT(group_id,'_',forum_id) IN ($in_list)");
	}
	// Delete prior writes from acl_users
	foreach (array_chunk($prior_inserts['acl_users'], 200) as $chunk)
	{
		if (empty($chunk)) continue;
		$quoted = array_map(function($k) use ($db) {
			return "'" . $db->sql_escape($k) . "'";
		}, $chunk);
		$in_list = implode(',', $quoted);
		$db->sql_query('DELETE FROM ' . ACL_USERS_TABLE . " WHERE auth_role_id <> 0 AND CONCAT(user_id,'_',forum_id) IN ($in_list)");
	}
	// Clear old log entries
	$db->sql_query('DELETE FROM ' . LOG_TABLE . " WHERE log_operation = 'LOG_VB4_PERM_MAP_INSERT'");

	$inserted_acl_groups = [];
	$inserted_acl_users  = [];

	// Insert forum_plan into phpbb_acl_groups
	foreach ($forum_plan as $group_id => $forums)
	{
		foreach ($forums as $forum_id => $role_name)
		{
			$role_id = $role_ids[$role_name];
			$sql_ary = [
				'group_id'      => (int) $group_id,
				'forum_id'      => (int) $forum_id,
				'auth_option_id'=> 0,
				'auth_role_id'  => (int) $role_id,
				'auth_setting'  => 0,
			];
			$db->sql_query('REPLACE INTO ' . ACL_GROUPS_TABLE . ' ' . $db->sql_build_array('INSERT', $sql_ary));
			$inserted_acl_groups[] = "{$group_id}_{$forum_id}";
			$applied['acl_groups']++;
		}
	}

	// Insert moderator plan into phpbb_acl_users
	foreach ($mod_plan_global as $user_id => $role_name)
	{
		$role_id = $role_ids[$role_name];
		$sql_ary = [
			'user_id'       => (int) $user_id,
			'forum_id'      => 0,
			'auth_option_id'=> 0,
			'auth_role_id'  => (int) $role_id,
			'auth_setting'  => 0,
		];
		$db->sql_query('REPLACE INTO ' . ACL_USERS_TABLE . ' ' . $db->sql_build_array('INSERT', $sql_ary));
		$inserted_acl_users[] = "{$user_id}_0";
		$applied['acl_users']++;
	}

	foreach ($mod_plan_forum as $user_id => $forums)
	{
		foreach ($forums as $forum_id => $role_name)
		{
			$role_id = $role_ids[$role_name];
			$sql_ary = [
				'user_id'       => (int) $user_id,
				'forum_id'      => (int) $forum_id,
				'auth_option_id'=> 0,
				'auth_role_id'  => (int) $role_id,
				'auth_setting'  => 0,
			];
			$db->sql_query('REPLACE INTO ' . ACL_USERS_TABLE . ' ' . $db->sql_build_array('INSERT', $sql_ary));
			$inserted_acl_users[] = "{$user_id}_{$forum_id}";
			$applied['acl_users']++;
		}
	}

	// Log what we did for idempotency on re-run
	$log_data = serialize([
		'acl_groups' => $inserted_acl_groups,
		'acl_users'  => $inserted_acl_users,
	]);
	$db->sql_query('INSERT INTO ' . LOG_TABLE . ' ' . $db->sql_build_array('INSERT', [
		'log_type'      => LOG_ADMIN,
		'user_id'       => (int) $user->data['user_id'],
		'forum_id'      => 0,
		'topic_id'      => 0,
		'reportee_id'   => 0,
		'log_ip'        => '127.0.0.1',
		'log_time'      => time(),
		'log_operation' => 'LOG_VB4_PERM_MAP_INSERT',
		'log_data'      => $log_data,
	]));

	// Invalidate caches so phpBB picks up the new permissions
	$cache->purge();
	$auth->acl_clear_prefetch();

	echo "  Inserted into phpbb_acl_groups: {$applied['acl_groups']} rows\n";
	echo "  Inserted into phpbb_acl_users:  {$applied['acl_users']} rows\n";
	echo "  Cleared auth prefetch + cache.\n\n";
}

// -----------------------------------------------------------------------
// Report file
// -----------------------------------------------------------------------

$report_lines = [];
$report_lines[] = str_repeat('=', 78);
$report_lines[] = 'vBulletin 4 → phpBB 3.3.x permission mapping report';
$report_lines[] = 'Generated: ' . date('Y-m-d H:i:s');
$report_lines[] = 'Mode: ' . ($opts['dry-run'] ? 'DRY-RUN (no changes written)' : 'APPLIED');
$report_lines[] = str_repeat('=', 78);
$report_lines[] = '';
$report_lines[] = 'GROUP CONSOLIDATION';
$report_lines[] = str_repeat('-', 78);
foreach ($consolidation as $vb_id => $e)
{
	$target_name = $e['target_phpbb_id'] ? ($phpbb_groups[$e['target_phpbb_id']] ?? '?') : '(none)';
	$report_lines[] = sprintf("vB %3d  %-30s  → %-25s  %s",
		$vb_id, $e['vb_title'], $target_name, $e['note']);
}

// Per-forum permission plan, grouped by forum for readability
$report_lines[] = '';
$report_lines[] = 'PER-FORUM PERMISSIONS (planned)';
$report_lines[] = str_repeat('-', 78);

// Pivot the plan: forum_id → [target_group_id => role_name]
$by_forum = [];
foreach ($forum_plan as $target => $forums)
{
	foreach ($forums as $forum_id => $role_name)
	{
		$by_forum[$forum_id][$target] = $role_name;
	}
}
ksort($by_forum);

$private_forums = []; // forums where any target group got NOACCESS

foreach ($by_forum as $forum_id => $group_roles)
{
	$forum_title = $phpbb_forums[$forum_id] ?? $vb_forums[$forum_id] ?? '(unknown)';
	$report_lines[] = sprintf("Forum %d: \"%s\"", $forum_id, $forum_title);

	foreach ($group_roles as $target => $role_name)
	{
		$gname = $phpbb_groups[$target] ?? "group_id $target";
		$meta = $forum_plan_meta[$target][$forum_id] ?? null;
		$vb_perms_str = '';
		if ($meta && !empty($meta['vb_perms_seen']))
		{
			$unique_perms = array_unique($meta['vb_perms_seen']);
			$vb_perms_str = ' (vB ' . implode(',', $unique_perms) . ')';
			if (count($meta['source_vb_groups']) > 1)
			{
				$vb_perms_str .= ' [merged from vB groups: ' . implode(',', $meta['source_vb_groups']) . ']';
			}
		}
		$marker = ($role_name === 'ROLE_FORUM_NOACCESS') ? '  ⚠ PRIVATE' : '';
		$report_lines[] = sprintf("  %-22s  → %s%s%s",
			$gname, $role_name, $vb_perms_str, $marker);

		if ($role_name === 'ROLE_FORUM_NOACCESS')
		{
			$private_forums[$forum_id][] = $gname;
		}
	}
	$report_lines[] = '';
}

// Private/hidden forum summary
if (!empty($private_forums))
{
	$report_lines[] = 'PRIVATE / HIDDEN FORUMS (NOACCESS applied)';
	$report_lines[] = str_repeat('-', 78);
	foreach ($private_forums as $forum_id => $blocked_groups)
	{
		$forum_title = $phpbb_forums[$forum_id] ?? $vb_forums[$forum_id] ?? '(unknown)';
		$report_lines[] = sprintf("Forum %d \"%s\": NOACCESS for %s",
			$forum_id, $forum_title, implode(', ', $blocked_groups));
	}
	$report_lines[] = '';

	// Build a list of warnings: cases worth manual review
	$warnings = [];
	foreach ($private_forums as $forum_id => $blocked_groups)
	{
		$forum_title = $phpbb_forums[$forum_id] ?? $vb_forums[$forum_id] ?? '';
		$title_lower = strtolower($forum_title);

		// Warn if a forum whose name suggests mod/admin scope is locking out mods
		$suggests_mod_scope = (
			str_contains($title_lower, 'admin')      ||
			str_contains($title_lower, 'supermod')   ||
			str_contains($title_lower, 'moderator')  ||
			str_contains($title_lower, 'staff')
		);
		if ($suggests_mod_scope && in_array('GLOBAL_MODERATORS', $blocked_groups, true))
		{
			$warnings[] = sprintf("Forum %d \"%s\": name suggests mod scope but GLOBAL_MODERATORS is locked out (vB had 131072 'canvote-only' for that group). REVIEW: should mods have read access here?",
				$forum_id, $forum_title);
		}
	}

	if (!empty($warnings))
	{
		$report_lines[] = 'WARNINGS — manual review recommended';
		$report_lines[] = str_repeat('-', 78);
		$report_lines[] = 'The following permissions look ambiguous. vB used the canvote bit (131072)';
		$report_lines[] = 'inconsistently — some boards used it as "read-only" and others as "private".';
		$report_lines[] = 'These cases were mapped to NOACCESS by default; review in ACP and switch';
		$report_lines[] = 'to ROLE_FORUM_READONLY if the affected group should have read access.';
		$report_lines[] = '';
		foreach ($warnings as $w)
		{
			$report_lines[] = "  - $w";
		}
		$report_lines[] = '';
	}
}

// Moderator assignments
if (!$opts['skip-moderators'])
{
	$report_lines[] = 'MODERATOR ASSIGNMENTS';
	$report_lines[] = str_repeat('-', 78);

	if (!empty($mod_plan_global))
	{
		$report_lines[] = 'Global super-moderators (across all forums):';
		// Get usernames
		$mod_uids = implode(',', array_map('intval', array_keys($mod_plan_global)));
		$usernames = [];
		if ($mod_uids)
		{
			$sql = 'SELECT user_id, username FROM ' . USERS_TABLE . " WHERE user_id IN ($mod_uids)";
			$result = $db->sql_query($sql);
			while ($row = $db->sql_fetchrow($result))
			{
				$usernames[(int) $row['user_id']] = $row['username'];
			}
			$db->sql_freeresult($result);
		}
		foreach ($mod_plan_global as $uid => $role)
		{
			$uname = $usernames[$uid] ?? "user_id $uid";
			$report_lines[] = sprintf("  %-30s (user_id %d)  → %s",
				$uname, $uid, $role);
		}
		$report_lines[] = '';
	}

	if (!empty($mod_plan_forum))
	{
		$report_lines[] = 'Per-forum moderators:';
		// Collect all uids we need names for
		$all_uids = [];
		foreach ($mod_plan_forum as $uid => $f) $all_uids[$uid] = true;
		$mod_uids = implode(',', array_map('intval', array_keys($all_uids)));
		$usernames = [];
		if ($mod_uids)
		{
			$sql = 'SELECT user_id, username FROM ' . USERS_TABLE . " WHERE user_id IN ($mod_uids)";
			$result = $db->sql_query($sql);
			while ($row = $db->sql_fetchrow($result))
			{
				$usernames[(int) $row['user_id']] = $row['username'];
			}
			$db->sql_freeresult($result);
		}

		// Print sorted by forum for readability
		$by_forum_mods = [];
		foreach ($mod_plan_forum as $uid => $forums)
		{
			foreach ($forums as $fid => $role)
			{
				$by_forum_mods[$fid][] = ['uid' => $uid, 'role' => $role];
			}
		}
		ksort($by_forum_mods);
		foreach ($by_forum_mods as $fid => $entries)
		{
			$forum_title = $phpbb_forums[$fid] ?? '(unknown)';
			$report_lines[] = sprintf("  Forum %d (%s):", $fid, $forum_title);
			foreach ($entries as $e)
			{
				$uname = $usernames[$e['uid']] ?? "user_id {$e['uid']}";
				$report_lines[] = sprintf("    %-30s → %s", $uname, $e['role']);
			}
		}
		$report_lines[] = '';
	}
}

$report_lines[] = 'SUMMARY';
$report_lines[] = str_repeat('-', 78);
$report_lines[] = "Group consolidation:";
$report_lines[] = "  user_group rows moved: {$consolidation_summary['moved']}";
$report_lines[] = "  duplicate groups dropped: {$consolidation_summary['dropped']}";
$report_lines[] = "Permission mapping:";
$report_lines[] = "  Forum permission rules: $forum_rules_count";
$report_lines[] = "  Private forums (NOACCESS applied): " . count($private_forums);
$report_lines[] = "  Global moderator assignments: " . count($mod_plan_global);
$per_forum_mods = 0;
foreach ($mod_plan_forum as $u => $f) $per_forum_mods += count($f);
$report_lines[] = "  Per-forum moderator assignments: $per_forum_mods";
$report_lines[] = '';

if ($opts['dry-run'])
{
	$report_lines[] = 'To apply: re-run without --dry-run';
}
else
{
	$report_lines[] = "Applied: {$applied['acl_groups']} acl_groups rows, {$applied['acl_users']} acl_users rows";
}

$report_text = implode("\n", $report_lines) . "\n";

if (@file_put_contents($opts['report-file'], $report_text) === false)
{
	fwrite(STDERR, "Warning: could not write report to {$opts['report-file']}\n");
}
else
{
	echo "Report written to: {$opts['report-file']}\n";
}

echo "\n" . str_repeat('=', 78) . "\n";
echo "Done.\n";
if ($opts['dry-run'])
{
	echo "DRY-RUN MODE — no changes were written. Re-run without --dry-run to apply.\n";
}

$src_db->close();
exit(0);
