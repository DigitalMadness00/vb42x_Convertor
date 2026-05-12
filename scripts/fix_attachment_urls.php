<?php
/**
* vBulletin → phpBB 3.3 attachment-URL fixer
*
* Two patterns are commonly broken after conversion:
*
*   1. [ATTACH]N[/ATTACH] - vBulletin's old inline-attachment BBCode (no =CONFIG)
*      that the convertor's vb_reformat_inline_attach() doesn't handle. phpBB
*      doesn't recognize [ATTACH] as a tag so this renders as literal text.
*
*   2. [IMG]http://<vb_host>/<vb_path>/attachment.php?attachmentid=N[/IMG]
*      where users manually pasted the vB attachment URL. phpBB has no
*      attachment.php endpoint so these images render broken.
*
* Both patterns are rewritten to phpBB's native [attachment=I]filename[/attachment]
* BBCode where I is a per-post sequential index and filename is looked up from
* phpbb_attachments. The script can then invoke phpBB's built-in textformatter
* reparser to update the stored XML representation.
*
*   php scripts/fix_attachment_urls.php --dry-run     # preview, no writes
*   php scripts/fix_attachment_urls.php               # apply (interactive prompt)
*   php scripts/fix_attachment_urls.php --yes         # apply, skip prompt
*   php scripts/fix_attachment_urls.php --reparse     # also run the phpBB reparser
*
* See docs/permission_mapping_design.md for the broader post-conversion design.
*
* @copyright (c) 2026 DigitalMadness00
* @license   GPL-2.0
* @version   0.0.16
*/

// -----------------------------------------------------------------------
// CLI argument parsing
// -----------------------------------------------------------------------

$opts = [
	'dry-run'          => false,
	'yes'              => false,
	'reparse'          => false,
	'report-file'      => '/tmp/vb4_attachment_fix_report.txt',
	'phpbb-root'       => __DIR__ . '/..',
	'vb-url'           => [],   // array - can be specified multiple times
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

		// --vb-url is repeatable
		if ($key === 'vb-url')
		{
			$opts['vb-url'][] = $value;
			continue;
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
vBulletin → phpBB 3.3 attachment-URL fixer

Rewrites broken vBulletin attachment markup in post_text:
  - [ATTACH]N[/ATTACH] (old vB BBCode)
  - [IMG]<local_url>/attachment.php?attachmentid=N[/IMG] (raw URLs)
Both get rewritten as phpBB's native [attachment=I]filename[/attachment] BBCode.

External attachment URLs (pointing at other forums) are left untouched.

Usage:
  php scripts/fix_attachment_urls.php [options]

Options:
  --dry-run                 Preview changes without applying.
  -y, --yes                 Skip the interactive confirmation prompt.
  --reparse                 After fixing, invoke phpBB's textformatter reparser
                            (php bin/phpbbcli.php reparser:reparse post_text).
                            This rebuilds the stored XML for ALL posts in the
                            board, not just the ones we touched. Takes 15-30
                            minutes on a 250k-post board.
  --vb-url=<url>            A vBulletin URL to treat as "local" - URLs at this
                            host are rewritten, others are left alone. Specify
                            multiple times for multiple historical paths:
                              --vb-url=http://example.com/forums
                              --vb-url=http://example.com/forum
                            If not specified, the script attempts to detect a
                            sensible default from phpbb_config[server_name].
  --report-file=<path>      Where to write the report (default
                            /tmp/vb4_attachment_fix_report.txt).
  --phpbb-root=<path>       Path to phpBB install root (default: parent of script).
  -h, --help                Show this help.

The script is idempotent: posts that already have [attachment=I]filename[/attachment]
markup are skipped. Re-running is safe.

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
/** @var \phpbb\user                       $user */

global $db, $config, $user, $phpbb_root_path, $phpEx;

$user->session_begin();

echo "vBulletin → phpBB 3.3 attachment-URL fixer\n";
$mode = $opts['dry-run'] ? 'DRY-RUN' : ($opts['yes'] ? 'APPLY (--yes)' : 'INTERACTIVE');
echo "Mode: $mode\n";
echo "phpBB root: $phpbb_root_path\n";
echo str_repeat('=', 78) . "\n\n";

// -----------------------------------------------------------------------
// Determine which URLs are "local" (to be rewritten)
// -----------------------------------------------------------------------

$local_urls = [];
foreach ($opts['vb-url'] as $url)
{
	$normalized = normalize_url($url);
	if ($normalized)
	{
		$local_urls[$normalized] = true;
	}
}

// Auto-detect: scan post_text for the most common attachment.php hosts and
// suggest them, but only if no --vb-url was passed and the user has phpbb_config.
if (empty($local_urls))
{
	$server_name = $config['server_name'] ?? '';
	$script_path = trim($config['script_path'] ?? '', '/');
	if ($server_name)
	{
		// Best-effort: guess that the vB lived at the same host. Suggest both
		// /forums/ and /forum/ paths since both are common historical roots.
		$base = "http://" . $server_name;
		$local_urls[normalize_url("$base/forums")] = true;
		$local_urls[normalize_url("$base/forum")]  = true;
		if ($script_path)
		{
			$local_urls[normalize_url("$base/$script_path")] = true;
		}
		echo "  Auto-detected local URL candidates (use --vb-url to override):\n";
		foreach (array_keys($local_urls) as $u) echo "    $u\n";
		echo "\n";
	}
}

if (empty($local_urls))
{
	fwrite(STDERR, "No local URLs configured and auto-detection failed.\n");
	fwrite(STDERR, "Use --vb-url=http://your-vb-host/your-vb-path to specify.\n");
	exit(1);
}

/**
* Normalize a vBulletin URL to "host/path" form (no scheme, no trailing slash).
* "http://www.example.com/forums/" → "www.example.com/forums"
*/
function normalize_url(string $url): ?string
{
	$url = trim($url);
	if (!$url) return null;
	$url = preg_replace('#^https?://#i', '', $url);
	$url = preg_replace('#/+$#', '', $url);
	return $url;
}

// Build regex alternation for matching local URLs. We accept http or https,
// optional www. prefix, and the host+path pair.
$url_alternation = [];
foreach (array_keys($local_urls) as $hostpath)
{
	// hostpath is like "www.example.com/forums" or "example.com/forum"
	// We want to match both with and without "www." prefix when applicable.
	$bare = preg_replace('#^www\.#', '', $hostpath);
	$variants = [$hostpath];
	if ($bare !== $hostpath) $variants[] = $bare;
	if (!str_starts_with($hostpath, 'www.')) $variants[] = "www.$hostpath";
	foreach (array_unique($variants) as $v)
	{
		$url_alternation[] = preg_quote($v, '/');
	}
}
$url_pattern = implode('|', array_unique($url_alternation));

// -----------------------------------------------------------------------
// Load attachment lookup table once
// -----------------------------------------------------------------------

echo "Loading attachment lookup table...\n";

$attachments = [];     // attach_id => ['filename' => str, 'post_msg_id' => int]
$sql = 'SELECT attach_id, post_msg_id, real_filename
        FROM ' . ATTACHMENTS_TABLE;
$result = $db->sql_query($sql);
while ($row = $db->sql_fetchrow($result))
{
	$attachments[(int) $row['attach_id']] = [
		'filename'    => $row['real_filename'],
		'post_msg_id' => (int) $row['post_msg_id'],
	];
}
$db->sql_freeresult($result);
echo "  Loaded " . count($attachments) . " attachment records.\n\n";

// -----------------------------------------------------------------------
// Scan post_text for fixable patterns
// -----------------------------------------------------------------------

echo "Scanning posts for fixable patterns...\n";

// Pattern A: [ATTACH]N[/ATTACH] - vB old format BBCode
//   Note: vB-style XML in stored post_text uses [ATTACH] inside <t>...</t>
$pattern_attach = '/\[ATTACH\](\d+)\[\/ATTACH\]/';

// Pattern B: [IMG]http://<local>/attachment.php?attachmentid=N[...][/IMG]
//   This catches the raw BBCode form (appears in <t>-wrapped plaintext posts).
$pattern_img = '/\[IMG\](?:https?:\/\/(?:' . $url_pattern . ')\/attachment\.php\?attachmentid=(\d+)[^\[]*)\[\/IMG\]/i';

// Pattern C: phpBB's parsed XML form of an [IMG] tag that wraps an
// attachment.php URL. The textformatter produces something like:
//   <IMG src="http://..."><s>[IMG]</s><URL url="http://..."><LINK_TEXT text="...">http://...</LINK_TEXT></URL><e>[/IMG]</e></IMG>
// We match the whole <IMG>...</IMG> block where the URL points at the local
// attachment.php endpoint, capturing the attachmentid. The non-greedy [\s\S]
// inside lets us span across the entire structure.
$pattern_img_xml = '/<IMG\s+src="https?:\/\/(?:' . $url_pattern . ')\/attachment\.php\?attachmentid=(\d+)[^"]*"[^>]*>[\s\S]*?<\/IMG>/i';

$query_chunk = 500;
$total_posts_scanned = 0;
$total_attach_fixes = 0;
$total_img_fixes    = 0;
$posts_to_update = [];   // post_id => new_post_text
$lookup_misses = [];
$sample_changes = [];

$sql = 'SELECT COUNT(*) AS n FROM ' . POSTS_TABLE . "
        WHERE post_text LIKE '%[ATTACH]%'
        OR post_text LIKE '%attachment.php?attachmentid=%'";
$result = $db->sql_query($sql);
$candidate_post_count = (int) $db->sql_fetchfield('n');
$db->sql_freeresult($result);

echo "  Candidate posts (containing [ATTACH] or attachment.php): " . number_format($candidate_post_count) . "\n";

$offset = 0;
while (true)
{
	$sql = 'SELECT post_id, post_text
	        FROM ' . POSTS_TABLE . "
	        WHERE post_text LIKE '%[ATTACH]%'
	        OR post_text LIKE '%attachment.php?attachmentid=%'
	        ORDER BY post_id
	        LIMIT $offset, $query_chunk";
	$result = $db->sql_query($sql);

	$batch_count = 0;
	while ($row = $db->sql_fetchrow($result))
	{
		$batch_count++;
		$total_posts_scanned++;
		$post_id = (int) $row['post_id'];
		$post_text = $row['post_text'];
		$original_post_text = $post_text;

		// Per-post counter: assign sequential [attachment=I] indices for
		// each unique attachment_id we substitute into this post.
		$post_index = 0;
		$index_for_attach = [];

		// --- Pattern A: [ATTACH]N[/ATTACH] ---
		$post_text = preg_replace_callback(
			$pattern_attach,
			function ($m) use ($attachments, &$post_index, &$index_for_attach, &$lookup_misses, $post_id, &$total_attach_fixes)
			{
				$attach_id = (int) $m[1];
				if (!isset($attachments[$attach_id]))
				{
					$lookup_misses[] = ['post_id' => $post_id, 'attach_id' => $attach_id, 'pattern' => 'ATTACH'];
					return $m[0]; // leave as-is
				}
				$filename = $attachments[$attach_id]['filename'];
				if (!isset($index_for_attach[$attach_id]))
				{
					$index_for_attach[$attach_id] = $post_index++;
				}
				$index = $index_for_attach[$attach_id];
				$total_attach_fixes++;
				return "[attachment=$index]$filename" . "[/attachment]";
			},
			$post_text
		);

		// --- Pattern B: [IMG]<local-url>/attachment.php?attachmentid=N[...][/IMG] (raw BBCode form) ---
		$post_text = preg_replace_callback(
			$pattern_img,
			function ($m) use ($attachments, &$post_index, &$index_for_attach, &$lookup_misses, $post_id, &$total_img_fixes)
			{
				$attach_id = (int) $m[1];
				if (!isset($attachments[$attach_id]))
				{
					$lookup_misses[] = ['post_id' => $post_id, 'attach_id' => $attach_id, 'pattern' => 'IMG-local'];
					return $m[0];
				}
				$filename = $attachments[$attach_id]['filename'];
				if (!isset($index_for_attach[$attach_id]))
				{
					$index_for_attach[$attach_id] = $post_index++;
				}
				$index = $index_for_attach[$attach_id];
				$total_img_fixes++;
				return "[attachment=$index]$filename" . "[/attachment]";
			},
			$post_text
		);

		// --- Pattern C: phpBB's parsed XML <IMG ...>...</IMG> wrapping a local attachment.php URL ---
		$post_text = preg_replace_callback(
			$pattern_img_xml,
			function ($m) use ($attachments, &$post_index, &$index_for_attach, &$lookup_misses, $post_id, &$total_img_fixes)
			{
				$attach_id = (int) $m[1];
				if (!isset($attachments[$attach_id]))
				{
					$lookup_misses[] = ['post_id' => $post_id, 'attach_id' => $attach_id, 'pattern' => 'IMG-xml'];
					return $m[0];
				}
				$filename = $attachments[$attach_id]['filename'];
				if (!isset($index_for_attach[$attach_id]))
				{
					$index_for_attach[$attach_id] = $post_index++;
				}
				$index = $index_for_attach[$attach_id];
				$total_img_fixes++;
				return "[attachment=$index]$filename" . "[/attachment]";
			},
			$post_text
		);

		// Also clean up the XML-wrapped URLs from phpBB's textformatter parse.
		// These would otherwise remain in <r>/<t> form with the old attachment.php URL.
		// We don't replace these directly - we let phpBB's reparser handle them when
		// it sees the new [attachment] BBCode.

		if ($post_text !== $original_post_text)
		{
			$posts_to_update[$post_id] = $post_text;

			if (count($sample_changes) < 5)
			{
				// Find the first position where original and new diverge,
				// then show 80 chars of context before + 200 after, so the
				// change is always visible in the sample (not lost beyond
				// a fixed-position truncation window).
				$diff_pos = 0;
				$min_len = min(strlen($original_post_text), strlen($post_text));
				for ($i = 0; $i < $min_len; $i++)
				{
					if ($original_post_text[$i] !== $post_text[$i])
					{
						$diff_pos = $i;
						break;
					}
				}
				$start = max(0, $diff_pos - 80);

				$sample_changes[] = [
					'post_id'  => $post_id,
					'before'   => mb_substr($original_post_text, $start, 280),
					'after'    => mb_substr($post_text,           $start, 280),
				];
			}
		}
	}

	$db->sql_freeresult($result);

	if ($batch_count < $query_chunk) break;
	$offset += $query_chunk;
	if ($offset % 5000 === 0)
	{
		echo "  Scanned " . number_format($offset) . " posts...\n";
	}
}

echo "\n";
echo "Scan complete:\n";
echo "  Posts scanned:                " . number_format($total_posts_scanned) . "\n";
echo "  Posts to update:              " . number_format(count($posts_to_update)) . "\n";
echo "  [ATTACH]N[/ATTACH] fixes:     " . number_format($total_attach_fixes) . "\n";
echo "  [IMG] URL fixes:              " . number_format($total_img_fixes) . "\n";
echo "  Attachment lookup misses:     " . count($lookup_misses) . "\n";
echo "\n";

if (count($sample_changes))
{
	echo "Sample rewrites (first 5):\n";
	echo str_repeat('-', 78) . "\n";
	foreach ($sample_changes as $i => $c)
	{
		printf("\n  Post %d:\n", $c['post_id']);
		echo "    BEFORE: " . preg_replace('/\s+/', ' ', $c['before']) . "...\n";
		echo "    AFTER:  " . preg_replace('/\s+/', ' ', $c['after']) . "...\n";
	}
	echo "\n";
}

if (count($lookup_misses))
{
	echo "Lookup misses (attach_id referenced in post but not in phpbb_attachments):\n";
	$samples = array_slice($lookup_misses, 0, 10);
	foreach ($samples as $m)
	{
		printf("  Post %d, attach_id %d (%s)\n", $m['post_id'], $m['attach_id'], $m['pattern']);
	}
	if (count($lookup_misses) > 10) echo "  ... and " . (count($lookup_misses) - 10) . " more\n";
	echo "\n";
}

// -----------------------------------------------------------------------
// Confirmation gate
// -----------------------------------------------------------------------

if ($opts['dry-run'])
{
	echo str_repeat('=', 78) . "\n";
	echo "DRY-RUN MODE — no changes were written.\n";
	echo "To apply: re-run without --dry-run\n";
	write_report($opts, $total_posts_scanned, $posts_to_update, $total_attach_fixes, $total_img_fixes, $lookup_misses, $sample_changes, false);
	exit(0);
}

if (count($posts_to_update) === 0)
{
	echo "Nothing to do. Exiting.\n";
	exit(0);
}

if (!$opts['yes'])
{
	echo str_repeat('=', 78) . "\n";
	echo "The above shows what would be updated. Apply these changes?\n";
	if ($opts['reparse'])
	{
		echo "After applying, phpBB's reparser will run (could take 15-30 minutes).\n";
	}
	echo "Type 'yes' to proceed, anything else to abort: ";

	$response = trim((string) fgets(STDIN));
	if (strtolower($response) !== 'yes' && strtolower($response) !== 'y')
	{
		echo "\nAborted. No changes were written.\n";
		exit(0);
	}
	echo "\nProceeding...\n\n";
}

// -----------------------------------------------------------------------
// Apply phase
// -----------------------------------------------------------------------

echo "Applying " . count($posts_to_update) . " post updates...\n";

$applied = 0;
foreach ($posts_to_update as $post_id => $new_text)
{
	$db->sql_query('UPDATE ' . POSTS_TABLE . '
	                SET post_text = \'' . $db->sql_escape($new_text) . '\'
	                WHERE post_id = ' . (int) $post_id);
	$applied++;

	if ($applied % 500 === 0)
	{
		echo "  Updated " . number_format($applied) . " posts...\n";
	}
}

echo "  Updated " . number_format($applied) . " posts.\n";

// Update post_attachment flag - mark posts that now have [attachment=...] BBCode
echo "\nUpdating post_attachment flags...\n";
$sql = "UPDATE " . POSTS_TABLE . "
        SET post_attachment = 1
        WHERE post_id IN (" . implode(',', array_map('intval', array_keys($posts_to_update))) . ")
        AND post_attachment = 0";
$db->sql_query($sql);
echo "  Marked " . (int) $db->sql_affectedrows() . " posts as having attachments.\n";

echo "\n";

// -----------------------------------------------------------------------
// Optional: invoke phpBB's textformatter reparser
// -----------------------------------------------------------------------

if ($opts['reparse'])
{
	echo str_repeat('=', 78) . "\n";
	echo "Running phpBB's textformatter reparser...\n";
	echo "This rebuilds the stored XML for ALL posts and may take 15-30 minutes.\n";
	echo str_repeat('-', 78) . "\n";
	echo "Started at " . date('H:i:s') . "...\n";

	$cli = $phpbb_root_path . 'bin/phpbbcli.' . $phpEx;
	if (!is_file($cli))
	{
		fwrite(STDERR, "Cannot find phpBB CLI at: $cli\n");
		fwrite(STDERR, "Skipping reparser. You can run it manually:\n");
		fwrite(STDERR, "  cd $phpbb_root_path && php bin/phpbbcli.php reparser:reparse post_text\n");
	}
	else
	{
		@set_time_limit(0);
		$cmd = 'php ' . escapeshellarg($cli) . ' reparser:reparse post_text 2>&1';
		passthru($cmd, $reparse_exit_code);
		echo "Completed at " . date('H:i:s') . " (exit code $reparse_exit_code).\n";
	}
	echo "\n";
}
else
{
	echo "Reparser NOT invoked (use --reparse to run it).\n";
	echo "To rebuild post XML manually:\n";
	echo "  cd $phpbb_root_path && php bin/phpbbcli.php reparser:reparse post_text\n";
	echo "\n";
}

// -----------------------------------------------------------------------
// Final summary
// -----------------------------------------------------------------------

echo str_repeat('=', 78) . "\n";
echo "Attachment fix complete.\n";
echo str_repeat('=', 78) . "\n\n";

echo "Applied:\n";
echo "  Posts updated:                " . number_format($applied) . "\n";
echo "  [ATTACH]N[/ATTACH] fixes:     " . number_format($total_attach_fixes) . "\n";
echo "  [IMG] URL fixes:              " . number_format($total_img_fixes) . "\n";
echo "  Lookup misses (skipped):      " . count($lookup_misses) . "\n";
if ($opts['reparse'])
{
	echo "  Textformatter reparser:       ran\n";
}
echo "\n";

write_report($opts, $total_posts_scanned, $posts_to_update, $total_attach_fixes, $total_img_fixes, $lookup_misses, $sample_changes, true);
echo "Report written to: {$opts['report-file']}\n";

exit(0);

// =======================================================================
// HELPERS
// =======================================================================

function write_report(array $opts, int $scanned, array $updates, int $attach_fixes, int $img_fixes, array $misses, array $samples, bool $applied): void
{
	$lines = [];
	$lines[] = str_repeat('=', 78);
	$lines[] = 'vBulletin → phpBB 3.3 attachment-URL fix report';
	$lines[] = 'Generated: ' . date('Y-m-d H:i:s');
	$lines[] = 'Mode: ' . ($applied ? 'APPLIED' : 'DRY-RUN (no changes written)');
	$lines[] = str_repeat('=', 78);
	$lines[] = '';
	$lines[] = 'SCAN RESULTS';
	$lines[] = str_repeat('-', 78);
	$lines[] = "  Posts scanned:                " . number_format($scanned);
	$lines[] = "  Posts to update:              " . number_format(count($updates));
	$lines[] = "  [ATTACH]N[/ATTACH] fixes:     " . number_format($attach_fixes);
	$lines[] = "  [IMG] URL fixes:              " . number_format($img_fixes);
	$lines[] = "  Lookup misses:                " . count($misses);
	$lines[] = '';

	if (!empty($samples))
	{
		$lines[] = 'SAMPLE REWRITES';
		$lines[] = str_repeat('-', 78);
		foreach ($samples as $c)
		{
			$lines[] = "Post {$c['post_id']}:";
			$lines[] = "  BEFORE: " . preg_replace('/\s+/', ' ', $c['before']);
			$lines[] = "  AFTER:  " . preg_replace('/\s+/', ' ', $c['after']);
			$lines[] = '';
		}
	}

	if (!empty($misses))
	{
		$lines[] = 'LOOKUP MISSES';
		$lines[] = str_repeat('-', 78);
		$lines[] = '(attach_id was referenced in post_text but does not exist in phpbb_attachments)';
		foreach (array_slice($misses, 0, 50) as $m)
		{
			$lines[] = sprintf("  Post %d: attach_id %d (%s)", $m['post_id'], $m['attach_id'], $m['pattern']);
		}
		if (count($misses) > 50) $lines[] = "  ... and " . (count($misses) - 50) . " more";
		$lines[] = '';
	}

	$lines[] = 'NEXT STEPS';
	$lines[] = str_repeat('-', 78);
	if (!$applied)
	{
		$lines[] = '  Re-run without --dry-run to apply.';
	}
	else
	{
		if (!$opts['reparse'])
		{
			$lines[] = '  Run phpBB\'s textformatter reparser to rebuild post XML:';
			$lines[] = "    cd {$opts['phpbb-root']} && php bin/phpbbcli.php reparser:reparse post_text";
			$lines[] = '  This may take 15-30 minutes on large boards.';
		}
		else
		{
			$lines[] = '  Reparser was run. Test post rendering in the browser.';
		}
	}
	$lines[] = '';

	@file_put_contents($opts['report-file'], implode("\n", $lines) . "\n");
}
