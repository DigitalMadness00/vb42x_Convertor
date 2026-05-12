<?php
/**
* vBulletin 4 → phpBB 3.3.x post-conversion wrapper
*
* Runs all standard post-conversion cleanups in one command:
*   1. Verify the conversion looks complete
*   2. Reassign orphan posts to anonymous (phpbb_posts.poster_id, phpbb_topics.topic_poster)
*   3. Map vB permissions to phpBB ACL (shells out to map_permissions.php)
*   4. Resync forum/topic statistics (slow on big boards; can be skipped)
*
* Default UX: dry-run all steps first, print a summary, prompt for confirmation,
* then apply. Use --yes to skip the prompt (for automation / CI).
*
* Usage:
*   php scripts/post_convert.php [options]
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
	'yes'                  => false,
	'skip-orphans'         => false,
	'skip-permissions'     => false,
	'skip-stats'           => false,
	'aggressive'           => false,
	'report-file'          => '/tmp/vb4_post_convert_report.txt',
	'perm-report-file'     => '/tmp/vb4_perm_report.txt',
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

	if ($arg === '-y')
	{
		$opts['yes'] = true;
		continue;
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
vBulletin 4 → phpBB 3.3.x post-conversion wrapper

Runs the standard post-conversion cleanups in order:
  1. Verify conversion completed
  2. Reassign orphan posts to anonymous
  3. Map vB permissions to phpBB ACL (via map_permissions.php)
  4. Resync forum/topic statistics (slow)

Usage:
  php scripts/post_convert.php [options]

Options:
  --dry-run                 Preview all changes without applying.
  -y, --yes                 Skip the interactive confirmation prompt
                            (apply everything immediately after analysis).
  --skip-orphans            Skip orphan-poster cleanup.
  --skip-permissions        Skip permission mapping.
  --skip-stats              Skip the forum/topic stats resync.
  --aggressive              Forwarded to map_permissions.php.
  --report-file=<path>      Wrapper report path (default /tmp/vb4_post_convert_report.txt)
  --perm-report-file=<path> Permission report path (default /tmp/vb4_perm_report.txt)
  --phpbb-root=<path>       Path to phpBB install (default: parent of script)
  --src-host=<host>         Source DB host (forwarded to map_permissions.php)
  --src-port=<port>         Source DB port
  --src-user=<user>         Source DB user
  --src-pass=<pass>         Source DB password
  --src-name=<dbname>       Source vBulletin database name
  --src-prefix=<prefix>     Source table prefix
  -h, --help                Show this help.

Default behavior:
  - Runs all phases in dry-run analysis mode
  - Prints summary
  - Prompts: "Apply all these changes? [y/N]"
  - On 'y', re-runs everything in apply mode
  - On anything else, exits without writes

The --yes flag skips the prompt for automation.

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

if (!isset($_SERVER['REMOTE_ADDR']))     $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
if (!isset($_SERVER['HTTP_HOST']))        $_SERVER['HTTP_HOST'] = 'cli';
if (!isset($_SERVER['REQUEST_METHOD']))   $_SERVER['REQUEST_METHOD'] = 'GET';
if (!isset($_SERVER['REQUEST_URI']))      $_SERVER['REQUEST_URI'] = '/cli';

require($phpbb_root_path . 'common.' . $phpEx);

/** @var \phpbb\db\driver\driver_interface $db */
/** @var \phpbb\config\config              $config */
/** @var \phpbb\cache\service              $cache */
/** @var \phpbb\user                       $user */

global $db, $config, $cache, $user, $phpbb_root_path, $phpEx;

$user->session_begin();

echo "vBulletin 4 → phpBB 3.3.x post-conversion wrapper\n";
$mode = $opts['dry-run'] ? 'DRY-RUN' : ($opts['yes'] ? 'APPLY (--yes)' : 'INTERACTIVE');
echo "Mode: $mode\n";
echo "phpBB root: $phpbb_root_path\n";
echo str_repeat('=', 78) . "\n\n";

// -----------------------------------------------------------------------
// Analysis phase - gather counts and plans WITHOUT writing
// -----------------------------------------------------------------------

$analysis = [
	'verify'      => null,
	'orphans'     => null,
	'permissions' => null,
	'stats'       => null,
];

// ----- 1. Verify conversion ----------------------------------------------

echo "[1/4] Verifying conversion completed\n";
echo str_repeat('-', 78) . "\n";

$verify = ['ok' => true, 'reasons' => [], 'counts' => []];

$sql = 'SELECT COUNT(*) AS n FROM ' . USERS_TABLE . ' WHERE user_type IN (0, 1, 3)';
$result = $db->sql_query($sql);
$verify['counts']['human_users'] = (int) $db->sql_fetchfield('n');
$db->sql_freeresult($result);

$sql = 'SELECT COUNT(*) AS n FROM ' . POSTS_TABLE;
$result = $db->sql_query($sql);
$verify['counts']['posts'] = (int) $db->sql_fetchfield('n');
$db->sql_freeresult($result);

$sql = 'SELECT COUNT(*) AS n FROM ' . TOPICS_TABLE;
$result = $db->sql_query($sql);
$verify['counts']['topics'] = (int) $db->sql_fetchfield('n');
$db->sql_freeresult($result);

$sql = 'SELECT COUNT(*) AS n FROM ' . FORUMS_TABLE;
$result = $db->sql_query($sql);
$verify['counts']['forums'] = (int) $db->sql_fetchfield('n');
$db->sql_freeresult($result);

if ($verify['counts']['human_users'] < 2)
{
	$verify['ok'] = false;
	$verify['reasons'][] = 'Fewer than 2 human users found - did the conversion actually run?';
}
if ($verify['counts']['posts'] < 1)
{
	$verify['ok'] = false;
	$verify['reasons'][] = 'No posts found - the conversion may have failed.';
}

printf("  Human users: %s\n", number_format($verify['counts']['human_users']));
printf("  Posts:       %s\n", number_format($verify['counts']['posts']));
printf("  Topics:      %s\n", number_format($verify['counts']['topics']));
printf("  Forums:      %s\n", number_format($verify['counts']['forums']));

if (!$verify['ok'])
{
	echo "\nVerification FAILED:\n";
	foreach ($verify['reasons'] as $r) echo "  - $r\n";
	echo "\nRefusing to proceed. Run conversion first.\n";
	exit(1);
}
echo "  Status: OK\n\n";

$analysis['verify'] = $verify;

// ----- 2. Orphan poster analysis -----------------------------------------

if (!$opts['skip-orphans'])
{
	echo "[2/4] Orphan poster analysis (dry-run)\n";
	echo str_repeat('-', 78) . "\n";

	// Posts with poster_id pointing at non-existent users
	$sql = 'SELECT COUNT(*) AS n FROM ' . POSTS_TABLE . '
	        WHERE poster_id NOT IN (SELECT user_id FROM ' . USERS_TABLE . ')
	        AND poster_id <> ' . ANONYMOUS;
	$result = $db->sql_query($sql);
	$orphan_posts = (int) $db->sql_fetchfield('n');
	$db->sql_freeresult($result);

	// Topics with topic_poster pointing at non-existent users
	$sql = 'SELECT COUNT(*) AS n FROM ' . TOPICS_TABLE . '
	        WHERE topic_poster NOT IN (SELECT user_id FROM ' . USERS_TABLE . ')
	        AND topic_poster <> ' . ANONYMOUS;
	$result = $db->sql_query($sql);
	$orphan_topics = (int) $db->sql_fetchfield('n');
	$db->sql_freeresult($result);

	$analysis['orphans'] = [
		'posts'  => $orphan_posts,
		'topics' => $orphan_topics,
	];

	printf("  Posts with orphan poster_id:    %s (would reassign to ANONYMOUS)\n",
		number_format($orphan_posts));
	printf("  Topics with orphan topic_poster: %s (would reassign to ANONYMOUS)\n",
		number_format($orphan_topics));
	echo "\n  Note: post_username and topic_first_poster_name strings are\n";
	echo "  preserved on each row, so attribution survives the reassignment.\n\n";
}
else
{
	echo "[2/4] SKIPPED (--skip-orphans)\n\n";
}

// ----- 3. Permission mapping analysis ------------------------------------

if (!$opts['skip-permissions'])
{
	echo "[3/4] Permission mapping analysis (calls map_permissions.php)\n";
	echo str_repeat('-', 78) . "\n";

	$perm_script = __DIR__ . '/map_permissions.php';
	if (!is_file($perm_script))
	{
		fwrite(STDERR, "Cannot find map_permissions.php at: $perm_script\n");
		fwrite(STDERR, "Pass --skip-permissions to skip this phase.\n");
		exit(1);
	}

	$cmd = build_map_permissions_cmd($opts, true /* dry-run */);
	echo "  Running: $cmd\n\n";

	// Capture stdout summary lines, discard the full per-forum dump
	passthru($cmd, $perm_exit_code);

	if ($perm_exit_code !== 0)
	{
		fwrite(STDERR, "\nmap_permissions.php dry-run failed (exit $perm_exit_code).\n");
		fwrite(STDERR, "Investigate the error before continuing.\n");
		exit($perm_exit_code);
	}

	// Pull a summary from the perm report
	$analysis['permissions'] = parse_perm_report($opts['perm-report-file']);

	echo "\n  Permission plan summary:\n";
	if ($analysis['permissions']['warnings'])
	{
		printf("    ⚠ WARNINGS: %d (review %s)\n",
			$analysis['permissions']['warnings'], $opts['perm-report-file']);
	}
	echo "\n";
}
else
{
	echo "[3/4] SKIPPED (--skip-permissions)\n\n";
}

// ----- 4. Stats resync planning ------------------------------------------

if (!$opts['skip-stats'])
{
	echo "[4/4] Forum/topic statistics resync\n";
	echo str_repeat('-', 78) . "\n";

	$posts = $analysis['verify']['counts']['posts'];
	if ($posts > 100000)
	{
		echo "  ⏱ Estimated 5-15 minutes for boards over 100k posts.\n";
		echo "    This board has " . number_format($posts) . " posts.\n";
		echo "    Set --skip-stats to skip; resync manually later in ACP if you prefer.\n\n";
	}
	elseif ($posts > 10000)
	{
		echo "  ⏱ Estimated 1-3 minutes for this board size.\n\n";
	}
	else
	{
		echo "  Small board — should complete in under a minute.\n\n";
	}

	$analysis['stats'] = ['will_run' => true, 'posts' => $posts];
}
else
{
	echo "[4/4] SKIPPED (--skip-stats)\n\n";
}

// -----------------------------------------------------------------------
// Confirmation gate
// -----------------------------------------------------------------------

if ($opts['dry-run'])
{
	echo str_repeat('=', 78) . "\n";
	echo "DRY-RUN MODE — no changes were written.\n";
	echo "To apply: re-run without --dry-run\n";
	write_wrapper_report($opts, $analysis, false);
	exit(0);
}

if (!$opts['yes'])
{
	echo str_repeat('=', 78) . "\n";
	echo "The above is the dry-run analysis. Apply all these changes?\n";
	echo "Type 'yes' to proceed, anything else to abort: ";

	$response = trim((string) fgets(STDIN));
	if (strtolower($response) !== 'yes' && strtolower($response) !== 'y')
	{
		echo "\nAborted. No changes were written.\n";
		write_wrapper_report($opts, $analysis, false);
		exit(0);
	}
	echo "\nProceeding...\n\n";
}

// -----------------------------------------------------------------------
// Apply phase
// -----------------------------------------------------------------------

$applied = [
	'orphan_posts'    => 0,
	'orphan_topics'   => 0,
	'acl_groups'      => 0,
	'acl_users'       => 0,
	'stats_synced'    => false,
];

// ----- 1. Apply orphan cleanup -------------------------------------------

if (!$opts['skip-orphans'])
{
	echo "[APPLY 2/4] Reassigning orphan posts\n";
	echo str_repeat('-', 78) . "\n";

	$sql = 'UPDATE ' . POSTS_TABLE . ' SET poster_id = ' . ANONYMOUS . '
	        WHERE poster_id NOT IN (SELECT user_id FROM ' . USERS_TABLE . ')
	        AND poster_id <> ' . ANONYMOUS;
	$db->sql_query($sql);
	$applied['orphan_posts'] = (int) $db->sql_affectedrows();

	$sql = 'UPDATE ' . TOPICS_TABLE . ' SET topic_poster = ' . ANONYMOUS . '
	        WHERE topic_poster NOT IN (SELECT user_id FROM ' . USERS_TABLE . ')
	        AND topic_poster <> ' . ANONYMOUS;
	$db->sql_query($sql);
	$applied['orphan_topics'] = (int) $db->sql_affectedrows();

	printf("  Reassigned %s post(s) and %s topic(s) to ANONYMOUS\n\n",
		number_format($applied['orphan_posts']),
		number_format($applied['orphan_topics'])
	);
}

// ----- 2. Apply permission mapping ---------------------------------------

if (!$opts['skip-permissions'])
{
	echo "[APPLY 3/4] Applying permission mapping\n";
	echo str_repeat('-', 78) . "\n";

	$cmd = build_map_permissions_cmd($opts, false /* not dry-run */);
	echo "  Running: $cmd\n\n";
	passthru($cmd, $perm_exit_code);

	if ($perm_exit_code !== 0)
	{
		fwrite(STDERR, "\nmap_permissions.php apply failed (exit $perm_exit_code).\n");
		fwrite(STDERR, "Check the report at {$opts['perm-report-file']} and the apply log.\n");
		exit($perm_exit_code);
	}

	// Pull final counts from report
	$final_perm = parse_perm_report($opts['perm-report-file']);
	$applied['acl_groups'] = $final_perm['acl_groups'] ?? 0;
	$applied['acl_users']  = $final_perm['acl_users']  ?? 0;
	echo "\n";
}

// ----- 3. Stats resync ---------------------------------------------------

if (!$opts['skip-stats'])
{
	echo "[APPLY 4/4] Running forum/topic statistics resync\n";
	echo str_repeat('-', 78) . "\n";
	echo "  Starting at " . date('H:i:s') . "...\n";

	// phpBB's sync() function lives in includes/functions_admin.php
	if (!function_exists('sync'))
	{
		require($phpbb_root_path . 'includes/functions_admin.' . $phpEx);
	}

	// Disable execution time limit for the duration
	@set_time_limit(0);

	// Sync forums: recompute post counts, topic counts, last-post pointers
	sync('forum', '', '', false, true);

	// Sync topics: recompute reply counts and last-post pointers
	sync('topic', '', '', false, true);

	$applied['stats_synced'] = true;
	echo "  Completed at " . date('H:i:s') . ".\n\n";
}

// -----------------------------------------------------------------------
// Final summary and reminders
// -----------------------------------------------------------------------

echo str_repeat('=', 78) . "\n";
echo "Post-conversion complete.\n";
echo str_repeat('=', 78) . "\n\n";

echo "Applied:\n";
if (!$opts['skip-orphans'])
{
	printf("  Orphan posts reassigned:    %s\n", number_format($applied['orphan_posts']));
	printf("  Orphan topics reassigned:   %s\n", number_format($applied['orphan_topics']));
}
if (!$opts['skip-permissions'])
{
	printf("  ACL group rows written:     %s\n", number_format($applied['acl_groups']));
	printf("  ACL user rows written:      %s\n", number_format($applied['acl_users']));
}
if (!$opts['skip-stats'])
{
	echo "  Forum/topic stats synced:   yes\n";
}
echo "\n";

echo "Remaining manual steps:\n";
echo "  1. Review " . $opts['perm-report-file'] . " for permission-mapping WARNINGS\n";
echo "     (forum names suggesting mod/admin scope where mods got locked out).\n";
echo "  2. ACP → Maintenance → Search index → Create index\n";
echo "     (search won't work until this is built; takes hours on large boards)\n";
echo "  3. Delete (or rename) phpBB's install/ directory for security:\n";
echo "       rm -rf $phpbb_root_path" . "install\n";
echo "     Or to keep the convertor available for re-runs:\n";
echo "       mv $phpbb_root_path" . "install $phpbb_root_path" . "old.install\n";
echo "  4. Test login flows with admin, moderator, and regular-user accounts.\n";
echo "\n";

write_wrapper_report($opts, $analysis, true, $applied);

echo "Wrapper report: {$opts['report-file']}\n";
echo "Permission report: {$opts['perm-report-file']}\n";

exit(0);

// =======================================================================
// HELPERS
// =======================================================================

/**
* Build the map_permissions.php command line with forwarded flags.
*/
function build_map_permissions_cmd(array $opts, bool $dry_run): string
{
	$cmd = 'php ' . escapeshellarg(__DIR__ . '/map_permissions.php');
	if ($dry_run)              $cmd .= ' --dry-run';
	if ($opts['aggressive'])   $cmd .= ' --aggressive';
	if (!empty($opts['report-file']))      $cmd .= ' --report-file=' . escapeshellarg($opts['perm-report-file']);
	if (!empty($opts['phpbb-root']))       $cmd .= ' --phpbb-root=' . escapeshellarg($opts['phpbb-root']);
	if (!empty($opts['src-host']))         $cmd .= ' --src-host=' . escapeshellarg($opts['src-host']);
	if (!empty($opts['src-port']))         $cmd .= ' --src-port=' . escapeshellarg($opts['src-port']);
	if (!empty($opts['src-user']))         $cmd .= ' --src-user=' . escapeshellarg($opts['src-user']);
	if (!empty($opts['src-pass']))         $cmd .= ' --src-pass=' . escapeshellarg($opts['src-pass']);
	if (!empty($opts['src-name']))         $cmd .= ' --src-name=' . escapeshellarg($opts['src-name']);
	if (!empty($opts['src-prefix']))       $cmd .= ' --src-prefix=' . escapeshellarg($opts['src-prefix']);
	return $cmd;
}

/**
* Parse the permission-mapping report file to extract summary counts.
* Returns null if the report doesn't exist or can't be parsed.
*/
function parse_perm_report(string $path): array
{
	$out = [
		'forum_rules'     => 0,
		'private_forums'  => 0,
		'global_mods'     => 0,
		'per_forum_mods'  => 0,
		'warnings'        => 0,
		'acl_groups'      => 0,
		'acl_users'       => 0,
	];

	if (!is_file($path)) return $out;
	$txt = @file_get_contents($path);
	if ($txt === false) return $out;

	if (preg_match('/Forum permission rules:\s+(\d+)/', $txt, $m))   $out['forum_rules']    = (int) $m[1];
	if (preg_match('/Private forums.*?:\s+(\d+)/', $txt, $m))         $out['private_forums'] = (int) $m[1];
	if (preg_match('/Global moderator assignments:\s+(\d+)/', $txt, $m)) $out['global_mods']  = (int) $m[1];
	if (preg_match('/Per-forum moderator assignments:\s+(\d+)/', $txt, $m)) $out['per_forum_mods'] = (int) $m[1];
	if (preg_match('/Applied:\s+(\d+)\s+acl_groups\s+rows,\s+(\d+)\s+acl_users/', $txt, $m))
	{
		$out['acl_groups'] = (int) $m[1];
		$out['acl_users']  = (int) $m[2];
	}

	// Count WARNINGS — lines starting with "  - Forum" inside the WARNINGS section
	if (preg_match('/WARNINGS.*?(?=MODERATOR ASSIGNMENTS|SUMMARY|$)/s', $txt, $m))
	{
		$out['warnings'] = preg_match_all('/^\s+- Forum /m', $m[0]);
	}

	return $out;
}

/**
* Write the wrapper-level report file.
*/
function write_wrapper_report(array $opts, array $analysis, bool $was_applied, array $applied = []): void
{
	$lines = [];
	$lines[] = str_repeat('=', 78);
	$lines[] = 'vBulletin 4 → phpBB 3.3.x post-conversion wrapper report';
	$lines[] = 'Generated: ' . date('Y-m-d H:i:s');
	$lines[] = 'Mode: ' . ($was_applied ? 'APPLIED' : 'DRY-RUN (no changes written)');
	$lines[] = str_repeat('=', 78);
	$lines[] = '';

	if (!empty($analysis['verify']))
	{
		$lines[] = 'VERIFICATION';
		$lines[] = str_repeat('-', 78);
		$c = $analysis['verify']['counts'];
		$lines[] = sprintf("  Human users: %s", number_format($c['human_users']));
		$lines[] = sprintf("  Posts:       %s", number_format($c['posts']));
		$lines[] = sprintf("  Topics:      %s", number_format($c['topics']));
		$lines[] = sprintf("  Forums:      %s", number_format($c['forums']));
		$lines[] = '';
	}

	if (!empty($analysis['orphans']))
	{
		$lines[] = 'ORPHAN POSTERS';
		$lines[] = str_repeat('-', 78);
		$lines[] = sprintf("  Posts to reassign:  %s",   number_format($analysis['orphans']['posts']));
		$lines[] = sprintf("  Topics to reassign: %s",   number_format($analysis['orphans']['topics']));
		if ($was_applied)
		{
			$lines[] = sprintf("  Posts reassigned:   %s",  number_format($applied['orphan_posts'] ?? 0));
			$lines[] = sprintf("  Topics reassigned:  %s",  number_format($applied['orphan_topics'] ?? 0));
		}
		$lines[] = '';
	}

	if (!empty($analysis['permissions']))
	{
		$p = $analysis['permissions'];
		$lines[] = 'PERMISSION MAPPING';
		$lines[] = str_repeat('-', 78);
		$lines[] = sprintf("  Forum permission rules:        %d", $p['forum_rules']);
		$lines[] = sprintf("  Private forums (NOACCESS):     %d", $p['private_forums']);
		$lines[] = sprintf("  Global moderator assignments:  %d", $p['global_mods']);
		$lines[] = sprintf("  Per-forum moderator assignments: %d", $p['per_forum_mods']);
		$lines[] = sprintf("  WARNINGS (review needed):      %d", $p['warnings']);
		$lines[] = sprintf("  Detailed report: %s", $opts['perm-report-file']);
		$lines[] = '';
	}

	if ($was_applied && !empty($applied['stats_synced']))
	{
		$lines[] = 'STATISTICS RESYNC';
		$lines[] = str_repeat('-', 78);
		$lines[] = '  Forum and topic statistics resynced.';
		$lines[] = '';
	}

	$lines[] = 'REMAINING MANUAL STEPS';
	$lines[] = str_repeat('-', 78);
	$lines[] = '  1. Review permission-mapping WARNINGS in ' . $opts['perm-report-file'];
	$lines[] = '  2. ACP → Maintenance → Search index → Create index';
	$lines[] = '  3. Delete or rename phpBB install/ directory';
	$lines[] = '  4. Test login flows';
	$lines[] = '';

	$report = implode("\n", $lines) . "\n";
	@file_put_contents($opts['report-file'], $report);
}
