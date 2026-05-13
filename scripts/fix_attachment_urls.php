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
	'skip-posts'       => false,
	'skip-signatures'  => false,
	'skip-pms'         => false,
	'skip-postid'      => false,   // skip Pattern D (postid=N URL lookups)
	'report-file'      => '/tmp/vb4_attachment_fix_report.txt',
	'phpbb-root'       => __DIR__ . '/..',
	'vb-url'           => [],   // array - can be specified multiple times
	// Source DB - optional, only needed for Pattern D (postid=N URLs)
	'src-host'         => null,
	'src-port'         => null,
	'src-user'         => null,
	'src-pass'         => null,
	'src-name'         => null,
	'src-prefix'       => '',
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

Rewrites broken vBulletin attachment markup in three places:
  - phpbb_posts.post_text       (rewrites to [attachment=I]filename[/attachment])
  - phpbb_users.user_sig        (rewrites URL to ./download/file.php?id=N)
  - phpbb_privmsgs.message_text (rewrites URL to ./download/file.php?id=N)

Three patterns are fixed in each:
  - [ATTACH]N[/ATTACH] (old vB BBCode)
  - URL embeds pointing at /attachment.php?attachmentid=N
  - URL embeds pointing at /attachment.php?...postid=N (older vB2/3 form;
    requires source-DB access to resolve postid→attach_id)

External attachment URLs (pointing at other forums) are left untouched.

Usage:
  php scripts/fix_attachment_urls.php [options]

Options:
  --dry-run                 Preview changes without applying.
  -y, --yes                 Skip the interactive confirmation prompt.
  --reparse                 After fixing, invoke phpBB's textformatter reparser.
                            Takes 15-30 minutes on a 250k-post board (does
                            posts, signatures, AND PMs).
  --skip-posts              Skip the post_text scan/fix.
  --skip-signatures         Skip the user_sig scan/fix.
  --skip-pms                Skip the privmsg message_text scan/fix.
  --skip-postid             Skip Pattern D (postid=N URL handling). Use this
                            if you don't have source-DB access or don't need
                            legacy URL handling.
  --vb-url=<url>            A vBulletin URL to treat as "local" - URLs at this
                            host are rewritten, others are left alone. Specify
                            multiple times for multiple paths:
                              --vb-url=http://example.com/forums
                              --vb-url=http://example.com/forum
                              --vb-url=http://example.com
                            If not specified, the script attempts to detect a
                            sensible default from phpbb_config[server_name].
  --src-host=<host>         Source (vB) DB host. Required for --skip-postid=false.
  --src-port=<port>         Source DB port (default 3306).
  --src-user=<user>         Source DB user.
  --src-pass=<pass>         Source DB password.
  --src-name=<dbname>       Source DB name (the vBulletin database).
  --src-prefix=<prefix>     Source table prefix (default empty).
  --report-file=<path>      Where to write the report (default
                            /tmp/vb4_attachment_fix_report.txt).
  --phpbb-root=<path>       Path to phpBB install root (default: parent of script).
  -h, --help                Show this help.

The script is idempotent: rows already pointing at ./download/file.php?id=N or
containing [attachment=I] BBCode are skipped. Re-running is safe.

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
// Optional: build postid → attach_id map from source vB DB
// -----------------------------------------------------------------------
//
// Older vBulletin versions used attachment.php?postid=N URLs (where N was a
// vB post id, not an attachment id). To rewrite these to phpBB's
// download/file.php?id=N format, we need to look up which attachment
// belongs to each vB post. The mapping comes from vB.attachment.contentid
// (the post id) → vB.attachment.attachmentid (which equals phpBB attach_id).

$postid_to_attach_id = [];   // vb_postid => phpbb_attach_id

if (!$opts['skip-postid'])
{
	// Need source DB credentials.
	if (!$opts['src-host'] || !$opts['src-user'] || $opts['src-pass'] === null || !$opts['src-name'])
	{
		echo "Postid URL handling enabled but source DB credentials not provided.\n";
		echo "Pass --src-host, --src-user, --src-pass, --src-name to enable Pattern D.\n";
		echo "Or pass --skip-postid to suppress this message.\n\n";
		echo "Continuing WITHOUT postid lookup (legacy URLs will be left alone)...\n\n";
	}
	else
	{
		echo "Connecting to source vB DB at {$opts['src-host']} ({$opts['src-name']})...\n";

		$src_db = new mysqli(
			$opts['src-host'],
			$opts['src-user'],
			$opts['src-pass'],
			$opts['src-name'],
			(int) ($opts['src-port'] ?: 3306)
		);
		if ($src_db->connect_error)
		{
			fwrite(STDERR, "Source DB connection failed: {$src_db->connect_error}\n");
			fwrite(STDERR, "Pass --skip-postid to continue without postid URL handling.\n");
			exit(1);
		}
		$src_db->set_charset('utf8mb4');

		// First, gather every postid referenced in the destination tables
		echo "  Scanning for postid references in posts/sigs/PMs...\n";

		$postid_set = [];
		$collect_postids = function ($table, $column, $pk) use ($db, &$postid_set) {
			$sql = "SELECT $pk, $column FROM $table
			        WHERE $column LIKE '%attachment.php?%postid=%'
			        OR $column LIKE '%attachment.php?postid=%'";
			$result = $db->sql_query($sql);
			while ($row = $db->sql_fetchrow($result))
			{
				if (preg_match_all('/attachment\.php\?[^"]*postid=(\d+)/i', $row[$column], $matches))
				{
					foreach ($matches[1] as $pid)
					{
						$postid_set[(int) $pid] = true;
					}
				}
			}
			$db->sql_freeresult($result);
		};

		if (!$opts['skip-posts'])      $collect_postids(POSTS_TABLE,    'post_text',    'post_id');
		if (!$opts['skip-signatures']) $collect_postids(USERS_TABLE,    'user_sig',     'user_id');
		if (!$opts['skip-pms'])        $collect_postids(PRIVMSGS_TABLE, 'message_text', 'msg_id');

		echo "  Found " . count($postid_set) . " distinct postid references.\n";

		// Now look up each postid in vB.attachment to find its attachmentid.
		// If a post had multiple attachments, pick the smallest attachmentid
		// (matches vB's default "render first attachment" behavior).
		if (!empty($postid_set))
		{
			$src_prefix = $opts['src-prefix'];
			$postid_list = implode(',', array_map('intval', array_keys($postid_set)));
			$sql = "SELECT contentid AS vb_postid, MIN(attachmentid) AS attach_id
			        FROM `{$src_prefix}attachment`
			        WHERE contenttypeid = 1
			          AND contentid IN ($postid_list)
			        GROUP BY contentid";
			$res = $src_db->query($sql);
			if (!$res)
			{
				fwrite(STDERR, "Source-DB lookup failed: {$src_db->error}\n");
				fwrite(STDERR, "Continuing without postid resolution.\n");
			}
			else
			{
				while ($row = $res->fetch_assoc())
				{
					$attach_id = (int) $row['attach_id'];
					// Only accept the mapping if the destination has this attach_id
					if (isset($attachments[$attach_id]))
					{
						$postid_to_attach_id[(int) $row['vb_postid']] = $attach_id;
					}
				}
				$res->free();
			}

			$resolved = count($postid_to_attach_id);
			$unresolved = count($postid_set) - $resolved;
			echo "  Resolved: $resolved postids → attach_ids\n";
			echo "  Unresolved: $unresolved (attachment missing from source vB or destination)\n\n";
		}

		$src_db->close();
	}
}

// -----------------------------------------------------------------------
// Scan post_text / user_sig / privmsgs.message_text for fixable patterns
// -----------------------------------------------------------------------

// Pattern A: [ATTACH]N[/ATTACH] - vB old format BBCode
//   Note: vB-style XML in stored text uses [ATTACH] inside <t>...</t>
$pattern_attach = '/\[ATTACH\](\d+)\[\/ATTACH\]/';

// Pattern B: [IMG]http://<local>/attachment.php?attachmentid=N[...][/IMG]
//   This catches the raw BBCode form (appears in <t>-wrapped plaintext rows).
$pattern_img = '/\[IMG\](?:https?:\/\/(?:' . $url_pattern . ')\/attachment\.php\?attachmentid=(\d+)[^\[]*)\[\/IMG\]/i';

// Pattern C: phpBB's parsed XML form of an [IMG] tag that wraps an
// attachment.php URL. The textformatter produces something like:
//   <IMG src="http://..."><s>[IMG]</s><URL url="http://..."><LINK_TEXT text="...">http://...</LINK_TEXT></URL><e>[/IMG]</e></IMG>
// We match the whole <IMG>...</IMG> block where the URL points at the local
// attachment.php endpoint, capturing the attachmentid. The non-greedy [\s\S]
// inside lets us span across the entire structure.
$pattern_img_xml = '/<IMG\s+src="https?:\/\/(?:' . $url_pattern . ')\/attachment\.php\?attachmentid=(\d+)[^"]*"[^>]*>[\s\S]*?<\/IMG>/i';

// Pattern D (legacy): older vB postid=N URLs.
// vB2/vB3 used attachment.php?postid=N (or ?s=&postid=N) to reference the
// first attachment of a post. Requires postid_to_attach_id lookup table.
// Match both the raw [IMG] BBCode form and the parsed XML form.
$pattern_img_postid     = '/\[IMG\](?:https?:\/\/(?:' . $url_pattern . ')\/attachment\.php\?[^\[]*?postid=(\d+)[^\[]*)\[\/IMG\]/i';
$pattern_img_xml_postid = '/<IMG\s+src="https?:\/\/(?:' . $url_pattern . ')\/attachment\.php\?[^"]*?postid=(\d+)[^"]*"[^>]*>[\s\S]*?<\/IMG>/i';

/**
* Scan a text column and produce rewrites.
*
* @param array  $context Lookup data and accumulators (passed by reference for stats)
* @param string $text    The current row's text
* @param int    $row_id  The row's primary key (for misses logging)
* @param string $row_type 'post', 'sig', or 'pm'
* @param string $mode    'bbcode' (produces [attachment=I]filename[/attachment]) or
*                        'url' (produces <IMG src="./download/file.php?id=N">)
* @return string|null Rewritten text, or null if nothing changed
*/
function rewrite_text(array &$context, string $text, int $row_id, string $row_type, string $mode): ?string
{
	global $pattern_attach, $pattern_img, $pattern_img_xml;
	global $pattern_img_postid, $pattern_img_xml_postid;

	$original = $text;
	$post_index = 0;
	$index_for_attach = [];
	$row_label = "$row_type $row_id";

	// Pre-compute set of filenames that ALREADY have <ATTACHMENT> blocks
	// in this row's text. These were created by phpBB's reparser from prior
	// runs. We must skip substitutions whose filename matches, otherwise
	// we'd double-render the same attachment.
	$existing_attachments = [];
	if (preg_match_all('/<ATTACHMENT\s+filename="([^"]+)"/', $text, $matches))
	{
		foreach ($matches[1] as $fn)
		{
			$existing_attachments[$fn] = true;
		}
	}

	$is_duplicate = function ($attach_id) use ($context, $existing_attachments, $row_id, $row_type) {
		if (!isset($context['attachments'][$attach_id])) return false;
		$filename = $context['attachments'][$attach_id]['filename'];
		if (isset($existing_attachments[$filename]))
		{
			$GLOBALS['_skipped_duplicates'][] = ['row_id' => $row_id, 'attach_id' => $attach_id, 'filename' => $filename, 'row_type' => $row_type];
			return true;
		}
		return false;
	};

	$make_replacement = function ($attach_id, $filename) use (&$post_index, &$index_for_attach, $mode, $context, $row_id, $row_type) {
		// Decide whether to produce [attachment=I]filename[/attachment] BBCode
		// (works only when phpbb_attachments has post_msg_id == $row_id for
		// this attach_id) or the URL form ./download/file.php?id=N (works
		// universally since download/file.php enforces permission checks at
		// serve time).
		//
		// The BBCode form gives proper phpBB inline-attachment rendering
		// (image plus attachment-list entry). The URL form just renders
		// the image. We prefer BBCode when possible.
		$attach_owner = $context['attachments'][$attach_id]['post_msg_id'] ?? 0;
		$use_bbcode = ($mode === 'bbcode' && $row_type === 'post' && $attach_owner === $row_id);

		if ($use_bbcode)
		{
			if (!isset($index_for_attach[$attach_id]))
			{
				$index_for_attach[$attach_id] = $post_index++;
			}
			$index = $index_for_attach[$attach_id];
			return "[attachment=$index]$filename" . "[/attachment]";
		}

		// URL mode: produce a phpBB-flavored <IMG> XML pointing at
		// download/file.php. The reparser will normalize this XML to canonical
		// form afterward.
		return '<IMG src="./download/file.php?id=' . (int) $attach_id . '"><s>[img]</s><URL url="./download/file.php?id=' . (int) $attach_id . '"><LINK_TEXT text="./download/file.php?id=' . (int) $attach_id . '">./download/file.php?id=' . (int) $attach_id . '</LINK_TEXT></URL><e>[/img]</e></IMG>';
	};

	// --- Pattern A: [ATTACH]N[/ATTACH] ---
	$text = preg_replace_callback(
		$pattern_attach,
		function ($m) use ($context, $make_replacement, $is_duplicate, $row_id, $row_label, $row_type) {
			$attach_id = (int) $m[1];
			if (!isset($context['attachments'][$attach_id]))
			{
				$GLOBALS['_lookup_misses'][] = ['row_id' => $row_id, 'attach_id' => $attach_id, 'pattern' => 'ATTACH', 'row_type' => $row_type];
				return $m[0];
			}
			if ($is_duplicate($attach_id))
			{
				return $m[0];  // leave the raw [ATTACH] as-is; reparser may handle it
			}
			$filename = $context['attachments'][$attach_id]['filename'];
			$GLOBALS['_attach_fixes_' . $row_type] = ($GLOBALS['_attach_fixes_' . $row_type] ?? 0) + 1;
			return $make_replacement($attach_id, $filename);
		},
		$text
	);

	// --- Pattern B: [IMG]<local-url>/attachment.php?attachmentid=N[...][/IMG] ---
	$text = preg_replace_callback(
		$pattern_img,
		function ($m) use ($context, $make_replacement, $is_duplicate, $row_id, $row_label, $row_type) {
			$attach_id = (int) $m[1];
			if (!isset($context['attachments'][$attach_id]))
			{
				$GLOBALS['_lookup_misses'][] = ['row_id' => $row_id, 'attach_id' => $attach_id, 'pattern' => 'IMG-local', 'row_type' => $row_type];
				return $m[0];
			}
			if ($is_duplicate($attach_id))
			{
				return $m[0];
			}
			$filename = $context['attachments'][$attach_id]['filename'];
			$GLOBALS['_img_fixes_' . $row_type] = ($GLOBALS['_img_fixes_' . $row_type] ?? 0) + 1;
			return $make_replacement($attach_id, $filename);
		},
		$text
	);

	// --- Pattern C: phpBB's parsed XML <IMG ...>...</IMG> wrapping a local attachment.php URL ---
	$text = preg_replace_callback(
		$pattern_img_xml,
		function ($m) use ($context, $make_replacement, $is_duplicate, $row_id, $row_label, $row_type) {
			$attach_id = (int) $m[1];
			if (!isset($context['attachments'][$attach_id]))
			{
				$GLOBALS['_lookup_misses'][] = ['row_id' => $row_id, 'attach_id' => $attach_id, 'pattern' => 'IMG-xml', 'row_type' => $row_type];
				return $m[0];
			}
			if ($is_duplicate($attach_id))
			{
				// The <IMG src="http://...attachment.php?N"> is leftover XML
				// from before; the canonical <ATTACHMENT> block already exists
				// elsewhere in the post. Remove this stale XML rather than
				// leaving a phantom broken-link element.
				return '';
			}
			$filename = $context['attachments'][$attach_id]['filename'];
			$GLOBALS['_img_fixes_' . $row_type] = ($GLOBALS['_img_fixes_' . $row_type] ?? 0) + 1;
			return $make_replacement($attach_id, $filename);
		},
		$text
	);

	// --- Pattern D (legacy): raw [IMG]<local>/attachment.php?...postid=N...[/IMG] ---
	// Requires postid_to_attach_id lookup table populated from source DB.
	$text = preg_replace_callback(
		$pattern_img_postid,
		function ($m) use ($context, $make_replacement, $is_duplicate, $row_id, $row_type) {
			$postid = (int) $m[1];
			$attach_id = $context['postid_to_attach_id'][$postid] ?? null;
			if ($attach_id === null)
			{
				$GLOBALS['_lookup_misses'][] = ['row_id' => $row_id, 'attach_id' => 0, 'pattern' => "postid=$postid (unresolved)", 'row_type' => $row_type];
				return $m[0];
			}
			if (!isset($context['attachments'][$attach_id]))
			{
				$GLOBALS['_lookup_misses'][] = ['row_id' => $row_id, 'attach_id' => $attach_id, 'pattern' => "postid=$postid → IMG-postid", 'row_type' => $row_type];
				return $m[0];
			}
			if ($is_duplicate($attach_id))
			{
				return $m[0];
			}
			$filename = $context['attachments'][$attach_id]['filename'];
			$GLOBALS['_img_fixes_' . $row_type] = ($GLOBALS['_img_fixes_' . $row_type] ?? 0) + 1;
			return $make_replacement($attach_id, $filename);
		},
		$text
	);

	// --- Pattern D (legacy): XML <IMG src="...attachment.php?...postid=N..."> ---
	$text = preg_replace_callback(
		$pattern_img_xml_postid,
		function ($m) use ($context, $make_replacement, $is_duplicate, $row_id, $row_type) {
			$postid = (int) $m[1];
			$attach_id = $context['postid_to_attach_id'][$postid] ?? null;
			if ($attach_id === null)
			{
				$GLOBALS['_lookup_misses'][] = ['row_id' => $row_id, 'attach_id' => 0, 'pattern' => "postid=$postid (unresolved)", 'row_type' => $row_type];
				return $m[0];
			}
			if (!isset($context['attachments'][$attach_id]))
			{
				$GLOBALS['_lookup_misses'][] = ['row_id' => $row_id, 'attach_id' => $attach_id, 'pattern' => "postid=$postid → IMG-xml-postid", 'row_type' => $row_type];
				return $m[0];
			}
			if ($is_duplicate($attach_id))
			{
				return '';
			}
			$filename = $context['attachments'][$attach_id]['filename'];
			$GLOBALS['_img_fixes_' . $row_type] = ($GLOBALS['_img_fixes_' . $row_type] ?? 0) + 1;
			return $make_replacement($attach_id, $filename);
		},
		$text
	);

	if ($text === $original) return null;
	return $text;
}

/**
* Capture context-aware before/after sample for the report.
*/
function capture_sample(array &$samples, int $row_id, string $row_type, string $before, string $after, int $max = 5): void
{
	if (count($samples) >= $max) return;

	$diff_pos = 0;
	$min_len = min(strlen($before), strlen($after));
	for ($i = 0; $i < $min_len; $i++)
	{
		if ($before[$i] !== $after[$i])
		{
			$diff_pos = $i;
			break;
		}
	}
	$start = max(0, $diff_pos - 80);

	$samples[] = [
		'row_id'   => $row_id,
		'row_type' => $row_type,
		'before'   => mb_substr($before, $start, 280),
		'after'    => mb_substr($after,  $start, 280),
	];
}

// Initialise global accumulators (used by the rewriter callbacks)
$GLOBALS['_lookup_misses']     = [];
$GLOBALS['_skipped_duplicates'] = [];
$GLOBALS['_attach_fixes_post'] = 0;
$GLOBALS['_attach_fixes_sig']  = 0;
$GLOBALS['_attach_fixes_pm']   = 0;
$GLOBALS['_img_fixes_post']    = 0;
$GLOBALS['_img_fixes_sig']     = 0;
$GLOBALS['_img_fixes_pm']      = 0;

$context = [
	'attachments' => $attachments,
	'postid_to_attach_id' => $postid_to_attach_id,
];

$updates = [
	'post' => [],   // post_id => new_text
	'sig'  => [],   // user_id => new_text
	'pm'   => [],   // msg_id  => new_text
];
$samples = [];

$query_chunk = 500;

// ----- Scan posts -----
if (!$opts['skip-posts'])
{
	echo "Scanning posts (post_text) for fixable patterns...\n";

	$sql = 'SELECT COUNT(*) AS n FROM ' . POSTS_TABLE . "
	        WHERE post_text LIKE '%[ATTACH]%'
	        OR post_text LIKE '%attachment.php?attachmentid=%'
	        OR post_text LIKE '%attachment.php?%postid=%'";
	$result = $db->sql_query($sql);
	$candidate_count = (int) $db->sql_fetchfield('n');
	$db->sql_freeresult($result);

	echo "  Candidate posts: " . number_format($candidate_count) . "\n";

	$offset = 0;
	while (true)
	{
		$sql = 'SELECT post_id, post_text
		        FROM ' . POSTS_TABLE . "
		        WHERE post_text LIKE '%[ATTACH]%'
		        OR post_text LIKE '%attachment.php?attachmentid=%'
	        OR post_text LIKE '%attachment.php?%postid=%'
		        ORDER BY post_id
		        LIMIT $offset, $query_chunk";
		$result = $db->sql_query($sql);

		$batch_count = 0;
		while ($row = $db->sql_fetchrow($result))
		{
			$batch_count++;
			$post_id = (int) $row['post_id'];
			$new_text = rewrite_text($context, $row['post_text'], $post_id, 'post', 'bbcode');
			if ($new_text !== null)
			{
				$updates['post'][$post_id] = $new_text;
				capture_sample($samples, $post_id, 'post', $row['post_text'], $new_text);
			}
		}
		$db->sql_freeresult($result);

		if ($batch_count < $query_chunk) break;
		$offset += $query_chunk;
		if ($offset % 5000 === 0) echo "  Scanned " . number_format($offset) . " posts...\n";
	}

	echo "  Posts to update: " . number_format(count($updates['post'])) . "\n";
	echo "\n";
}

// ----- Scan signatures -----
if (!$opts['skip-signatures'])
{
	echo "Scanning user signatures (user_sig) for fixable patterns...\n";

	$sql = 'SELECT COUNT(*) AS n FROM ' . USERS_TABLE . "
	        WHERE user_sig LIKE '%[ATTACH]%'
	        OR user_sig LIKE '%attachment.php?attachmentid=%'
	        OR user_sig LIKE '%attachment.php?%postid=%'";
	$result = $db->sql_query($sql);
	$candidate_count = (int) $db->sql_fetchfield('n');
	$db->sql_freeresult($result);

	echo "  Candidate signatures: " . number_format($candidate_count) . "\n";

	$sql = 'SELECT user_id, user_sig
	        FROM ' . USERS_TABLE . "
	        WHERE user_sig LIKE '%[ATTACH]%'
	        OR user_sig LIKE '%attachment.php?attachmentid=%'
	        OR user_sig LIKE '%attachment.php?%postid=%'";
	$result = $db->sql_query($sql);

	while ($row = $db->sql_fetchrow($result))
	{
		$user_id = (int) $row['user_id'];
		$new_text = rewrite_text($context, $row['user_sig'], $user_id, 'sig', 'url');
		if ($new_text !== null)
		{
			$updates['sig'][$user_id] = $new_text;
			capture_sample($samples, $user_id, 'sig', $row['user_sig'], $new_text);
		}
	}
	$db->sql_freeresult($result);

	echo "  Signatures to update: " . number_format(count($updates['sig'])) . "\n";
	echo "\n";
}

// ----- Scan private messages -----
if (!$opts['skip-pms'])
{
	echo "Scanning private messages (privmsgs.message_text) for fixable patterns...\n";

	$sql = 'SELECT COUNT(*) AS n FROM ' . PRIVMSGS_TABLE . "
	        WHERE message_text LIKE '%[ATTACH]%'
	        OR message_text LIKE '%attachment.php?attachmentid=%'
	        OR message_text LIKE '%attachment.php?%postid=%'";
	$result = $db->sql_query($sql);
	$candidate_count = (int) $db->sql_fetchfield('n');
	$db->sql_freeresult($result);

	echo "  Candidate PMs: " . number_format($candidate_count) . "\n";

	$sql = 'SELECT msg_id, message_text
	        FROM ' . PRIVMSGS_TABLE . "
	        WHERE message_text LIKE '%[ATTACH]%'
	        OR message_text LIKE '%attachment.php?attachmentid=%'
	        OR message_text LIKE '%attachment.php?%postid=%'";
	$result = $db->sql_query($sql);

	while ($row = $db->sql_fetchrow($result))
	{
		$msg_id = (int) $row['msg_id'];
		$new_text = rewrite_text($context, $row['message_text'], $msg_id, 'pm', 'url');
		if ($new_text !== null)
		{
			$updates['pm'][$msg_id] = $new_text;
			capture_sample($samples, $msg_id, 'pm', $row['message_text'], $new_text);
		}
	}
	$db->sql_freeresult($result);

	echo "  PMs to update: " . number_format(count($updates['pm'])) . "\n";
	echo "\n";
}

// Aggregate stats for report
$total_attach_fixes = $GLOBALS['_attach_fixes_post'] + $GLOBALS['_attach_fixes_sig'] + $GLOBALS['_attach_fixes_pm'];
$total_img_fixes    = $GLOBALS['_img_fixes_post']    + $GLOBALS['_img_fixes_sig']    + $GLOBALS['_img_fixes_pm'];
$lookup_misses      = $GLOBALS['_lookup_misses'];

$total_to_update = count($updates['post']) + count($updates['sig']) + count($updates['pm']);

echo "Scan complete:\n";
echo "  Rows to update (total):       " . number_format($total_to_update) . "\n";
echo "    posts:                      " . number_format(count($updates['post'])) . "\n";
echo "    signatures:                 " . number_format(count($updates['sig']))  . "\n";
echo "    private messages:           " . number_format(count($updates['pm']))   . "\n";
echo "  [ATTACH]N[/ATTACH] fixes:     " . number_format($total_attach_fixes) . "\n";
echo "    posts: {$GLOBALS['_attach_fixes_post']}, sigs: {$GLOBALS['_attach_fixes_sig']}, pms: {$GLOBALS['_attach_fixes_pm']}\n";
echo "  [IMG]/URL fixes:              " . number_format($total_img_fixes) . "\n";
echo "    posts: {$GLOBALS['_img_fixes_post']}, sigs: {$GLOBALS['_img_fixes_sig']}, pms: {$GLOBALS['_img_fixes_pm']}\n";
echo "  Attachment lookup misses:     " . count($lookup_misses) . "\n";
echo "  Skipped duplicates:           " . count($GLOBALS['_skipped_duplicates']) . "\n";
echo "    (already had <ATTACHMENT> block for same filename — left alone to avoid double-render)\n";
echo "\n";

if (count($samples))
{
	echo "Sample rewrites (first 5):\n";
	echo str_repeat('-', 78) . "\n";
	foreach ($samples as $c)
	{
		printf("\n  %s %d:\n", strtoupper($c['row_type']), $c['row_id']);
		echo "    BEFORE: " . preg_replace('/\s+/', ' ', $c['before']) . "...\n";
		echo "    AFTER:  " . preg_replace('/\s+/', ' ', $c['after']) . "...\n";
	}
	echo "\n";
}

if (count($lookup_misses))
{
	echo "Lookup misses (attach_id referenced but not in phpbb_attachments):\n";
	$shown = array_slice($lookup_misses, 0, 10);
	foreach ($shown as $m)
	{
		printf("  %s %d, attach_id %d (%s)\n", $m['row_type'], $m['row_id'], $m['attach_id'], $m['pattern']);
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
	write_report($opts, $updates, $total_attach_fixes, $total_img_fixes, $lookup_misses, $samples, false, []);
	exit(0);
}

if ($total_to_update === 0)
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

$applied = ['post' => 0, 'sig' => 0, 'pm' => 0, 'post_flag' => 0];

// Apply post updates
if (!empty($updates['post']))
{
	echo "Applying " . count($updates['post']) . " post updates...\n";
	foreach ($updates['post'] as $post_id => $new_text)
	{
		$db->sql_query('UPDATE ' . POSTS_TABLE . '
		                SET post_text = \'' . $db->sql_escape($new_text) . '\'
		                WHERE post_id = ' . (int) $post_id);
		$applied['post']++;
		if ($applied['post'] % 500 === 0) echo "  Updated " . number_format($applied['post']) . " posts...\n";
	}
	echo "  Updated " . number_format($applied['post']) . " posts.\n";

	// Mark posts that now have inline attachments. Only posts whose updated
	// text contains [attachment=N] BBCode count; URL-form rewrites point at
	// download/file.php and don't trigger phpBB's attachment-list rendering.
	$posts_with_bbcode = [];
	foreach ($updates['post'] as $post_id => $new_text)
	{
		if (strpos($new_text, '[attachment=') !== false)
		{
			$posts_with_bbcode[] = (int) $post_id;
		}
	}
	if (!empty($posts_with_bbcode))
	{
		$ids = implode(',', $posts_with_bbcode);
		$db->sql_query("UPDATE " . POSTS_TABLE . "
		                SET post_attachment = 1
		                WHERE post_id IN ($ids) AND post_attachment = 0");
		$applied['post_flag'] = (int) $db->sql_affectedrows();
	}
	else
	{
		$applied['post_flag'] = 0;
	}
	echo "  Marked " . $applied['post_flag'] . " posts as having attachments (" . count($posts_with_bbcode) . " had BBCode form).\n";
	echo "\n";
}

// Apply signature updates
if (!empty($updates['sig']))
{
	echo "Applying " . count($updates['sig']) . " signature updates...\n";
	foreach ($updates['sig'] as $user_id => $new_text)
	{
		$db->sql_query('UPDATE ' . USERS_TABLE . '
		                SET user_sig = \'' . $db->sql_escape($new_text) . '\'
		                WHERE user_id = ' . (int) $user_id);
		$applied['sig']++;
	}
	echo "  Updated " . number_format($applied['sig']) . " signatures.\n\n";
}

// Apply PM updates
if (!empty($updates['pm']))
{
	echo "Applying " . count($updates['pm']) . " PM updates...\n";
	foreach ($updates['pm'] as $msg_id => $new_text)
	{
		$db->sql_query('UPDATE ' . PRIVMSGS_TABLE . '
		                SET message_text = \'' . $db->sql_escape($new_text) . '\'
		                WHERE msg_id = ' . (int) $msg_id);
		$applied['pm']++;
	}
	echo "  Updated " . number_format($applied['pm']) . " PMs.\n\n";
}

// -----------------------------------------------------------------------
// Optional: invoke phpBB's textformatter reparser
// -----------------------------------------------------------------------

if ($opts['reparse'])
{
	echo str_repeat('=', 78) . "\n";
	echo "Running phpBB's textformatter reparser...\n";
	echo "Rebuilds the stored XML for posts, signatures, AND private messages.\n";
	echo "May take 15-30 minutes on a 250k-post board.\n";
	echo str_repeat('-', 78) . "\n";
	echo "Started at " . date('H:i:s') . "...\n";

	$cli = $phpbb_root_path . 'bin/phpbbcli.' . $phpEx;
	if (!is_file($cli))
	{
		fwrite(STDERR, "Cannot find phpBB CLI at: $cli\n");
		fwrite(STDERR, "Skipping reparser. You can run it manually:\n");
		fwrite(STDERR, "  cd $phpbb_root_path && php bin/phpbbcli.php reparser:reparse\n");
	}
	else
	{
		@set_time_limit(0);
		// No argument = reparse all rich-text fields (post_text, user_sig, pm_text, etc.)
		$cmd = 'php ' . escapeshellarg($cli) . ' reparser:reparse 2>&1';
		passthru($cmd, $reparse_exit_code);
		echo "Completed at " . date('H:i:s') . " (exit code $reparse_exit_code).\n";
	}
	echo "\n";
}
else
{
	echo "Reparser NOT invoked (use --reparse to run it).\n";
	echo "To rebuild stored XML manually:\n";
	echo "  cd $phpbb_root_path && php bin/phpbbcli.php reparser:reparse\n";
	echo "\n";
}

// -----------------------------------------------------------------------
// Final summary
// -----------------------------------------------------------------------

echo str_repeat('=', 78) . "\n";
echo "Attachment fix complete.\n";
echo str_repeat('=', 78) . "\n\n";

echo "Applied:\n";
echo "  Posts updated:                " . number_format($applied['post']) . "\n";
echo "  Signatures updated:           " . number_format($applied['sig']) . "\n";
echo "  PMs updated:                  " . number_format($applied['pm']) . "\n";
echo "  post_attachment flag flips:   " . number_format($applied['post_flag']) . "\n";
echo "  [ATTACH]N[/ATTACH] fixes:     " . number_format($total_attach_fixes) . "\n";
echo "  [IMG]/URL fixes:              " . number_format($total_img_fixes) . "\n";
echo "  Lookup misses (skipped):      " . count($lookup_misses) . "\n";
if ($opts['reparse'])
{
	echo "  Textformatter reparser:       ran\n";
}
echo "\n";

write_report($opts, $updates, $total_attach_fixes, $total_img_fixes, $lookup_misses, $samples, true, $applied);
echo "Report written to: {$opts['report-file']}\n";

exit(0);

// =======================================================================
// HELPERS
// =======================================================================

function write_report(array $opts, array $updates, int $attach_fixes, int $img_fixes, array $misses, array $samples, bool $applied, array $applied_counts): void
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
	$lines[] = "  Posts to update:              " . number_format(count($updates['post']));
	$lines[] = "  Signatures to update:         " . number_format(count($updates['sig']));
	$lines[] = "  PMs to update:                " . number_format(count($updates['pm']));
	$lines[] = "  [ATTACH]N[/ATTACH] fixes:     " . number_format($attach_fixes);
	$lines[] = "  [IMG]/URL fixes:              " . number_format($img_fixes);
	$lines[] = "  Lookup misses:                " . count($misses);
	$lines[] = '';

	if ($applied)
	{
		$lines[] = 'APPLIED';
		$lines[] = str_repeat('-', 78);
		$lines[] = "  Posts updated:               " . number_format($applied_counts['post'] ?? 0);
		$lines[] = "  Signatures updated:          " . number_format($applied_counts['sig']  ?? 0);
		$lines[] = "  PMs updated:                 " . number_format($applied_counts['pm']   ?? 0);
		$lines[] = "  post_attachment flag flips:  " . number_format($applied_counts['post_flag'] ?? 0);
		$lines[] = '';
	}

	if (!empty($samples))
	{
		$lines[] = 'SAMPLE REWRITES';
		$lines[] = str_repeat('-', 78);
		foreach ($samples as $c)
		{
			$lines[] = strtoupper($c['row_type']) . " {$c['row_id']}:";
			$lines[] = "  BEFORE: " . preg_replace('/\s+/', ' ', $c['before']);
			$lines[] = "  AFTER:  " . preg_replace('/\s+/', ' ', $c['after']);
			$lines[] = '';
		}
	}

	if (!empty($misses))
	{
		$lines[] = 'LOOKUP MISSES';
		$lines[] = str_repeat('-', 78);
		$lines[] = '(attach_id was referenced but does not exist in phpbb_attachments)';
		foreach (array_slice($misses, 0, 50) as $m)
		{
			$lines[] = sprintf("  %s %d: attach_id %d (%s)", $m['row_type'], $m['row_id'], $m['attach_id'], $m['pattern']);
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
			$lines[] = '  Run phpBB\'s textformatter reparser to rebuild stored XML:';
			$lines[] = "    cd {$opts['phpbb-root']} && php bin/phpbbcli.php reparser:reparse";
			$lines[] = '  This rebuilds posts, signatures, AND PMs. May take 15-30 minutes on large boards.';
		}
		else
		{
			$lines[] = '  Reparser was run. Test post/signature rendering in the browser.';
		}
	}
	$lines[] = '';

	@file_put_contents($opts['report-file'], implode("\n", $lines) . "\n");
}
