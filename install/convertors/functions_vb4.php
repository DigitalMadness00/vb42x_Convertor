<?php
/** 
*
* @package install
* @version $Id:
* @copyright (c) 2006 phpBB Group 
* @copyright (c) Dicky 2008
* @copyright (c) prototech 2013
* @license http://opensource.org/licenses/gpl-license.php GNU Public License 
*
*/

/**
* Helper functions for vBulletin 4.x.x to phpBB 3.3.x conversion
*/

/**
* ============================================================================
*  DEBUG TRACING
* ============================================================================
*
*  Set VB4_DEBUG to true to write a detailed runtime trace to a log file
*  during conversion.  This is useful for diagnosing "users not converted"
*  or "X tables silently skipped" problems where phpBB's own logs are
*  empty because the framework swallowed the error.
*
*  Trace coverage:
*    - vb_set_phpbb_config()             every call, both API branches
*    - phpbb_user_id()                   every input/output, including
*                                        the lazy-init block and remap path
*    - vb_set_user_type()                proves the user-table block ran
*    - phpbb_check_username_collisions() ENTER/EXIT
*    - vb_add_user_salt_field()          ENTER/EXIT and which columns added
*
*  When VB4_DEBUG is false (the default), every vb_trace() call short-
*  circuits in O(1) and writes nothing.  Safe to leave shipped this way
*  in production.
*
*  HOW TO ENABLE:
*    1. Edit this file and change the line below to:  define('VB4_DEBUG', true);
*    2. Optionally adjust VB4_TRACE_FILE if /tmp isn't writable on your host
*       (try /var/www/html/phpBBxxxx/store/vb4_trace.log on shared hosting).
*    3. Run the conversion.
*    4. Inspect the trace file - it'll have one line per traced call.
*    5. Set the flag back to false before going to production.
*
*  Apache/PHP-FPM must be able to write to the trace file.  If you don't
*  see a trace file appearing during a conversion attempt with debug on,
*  the most likely cause is that the directory isn't writable by the
*  web-server user.
*/
if (!defined('VB4_DEBUG'))
{
	define('VB4_DEBUG', false);
}
if (!defined('VB4_TRACE_FILE'))
{
	define('VB4_TRACE_FILE', '/tmp/vb4_user_trace.log');
}

/**
* Append a trace line.  No-op unless VB4_DEBUG is true.
*
* @param string $line  Text to log (newline appended automatically).
*/
function vb_trace($line)
{
	if (!VB4_DEBUG)
	{
		return;
	}
	@file_put_contents(VB4_TRACE_FILE, $line . "\n", FILE_APPEND);
}

/**
* Convert ISO-8859-1 to UTF-8.
*
* This replaces the deprecated PHP 8.2 utf8_encode() builtin. We try the most
* portable options in order so the converter still works on older PHP versions.
*/
function vb_utf8_encode($text)
{
	if ($text === null || $text === '')
	{
		return (string) $text;
	}

	if (function_exists('mb_convert_encoding'))
	{
		return mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
	}

	if (function_exists('iconv'))
	{
		$converted = @iconv('ISO-8859-1', 'UTF-8//IGNORE', $text);
		if ($converted !== false)
		{
			return $converted;
		}
	}

	// Last-resort fallback for ancient PHP installs without mbstring/iconv.
	if (function_exists('utf8_encode'))
	{
		return @utf8_encode($text);
	}

	return $text;
}

/**
* Replacement for the legacy /e modifier preg_replace that mapped vB legacy
* size codes (1, 2, 3, ...) to point sizes used later by vb_replace_size().
*
* Original /e expression was:
*   '[size='.( (\\1 == 1) ? 9 : ( (\\1 == 2) ? 10 : 4*\\1 )) .']'
*/
function vb_legacy_size_to_pt($matches)
{
	$value = (int) $matches[1];

	if ($value === 1)
	{
		$pt = 9;
	}
	else if ($value === 2)
	{
		$pt = 10;
	}
	else
	{
		$pt = 4 * $value;
	}

	return '[size=' . $pt . ']';
}

/**
* Counterpart to is_empty()
*/
function not_is_empty($mixed)
{
	return (empty($mixed)) ? false : true;
}

/**
* Set a phpBB config value, working on both phpBB 3.0.x (procedural set_config)
* and phpBB 3.1+ (object-based $config->set()).
*
* The legacy procedural function set_config() was removed in 3.1.x and is NOT
* present in 3.3.x.  Calling it on a 3.3 install produces an uncaught Error
* which the convertor framework swallows silently, with the side effect that
* the calling transformation function never finishes.  In phpbb_user_id()'s
* case that means the per-call lazy-init block is re-entered on every row,
* throws on every row, and every user import is silently skipped.
*
* This helper preserves the legacy call site shape but routes through the
* right API for the running phpBB.
*/
function vb_set_phpbb_config($name, $value, $cache = true)
{
	global $config;

	vb_trace(sprintf('[%s] vb_set_phpbb_config(%s, %s)',
		date('H:i:s'), var_export($name, true), var_export($value, true)
	));

	if (is_object($config) && method_exists($config, 'set'))
	{
		// phpBB 3.1+ object-based config.  $cache==false would skip cache
		// invalidation; the modern API takes a boolean second argument that
		// inverts that meaning ("use_cache"), defaulting to true.
		vb_trace('  -> using object API $config->set()');
		$config->set($name, $value, $cache);
	}
	else if (function_exists('set_config'))
	{
		// phpBB 3.0.x legacy fallback.
		vb_trace('  -> using procedural set_config()');
		set_config($name, $value, $cache);
	}
	else
	{
		vb_trace('  !! NEITHER $config->set NOR set_config exists');
	}
	// If neither is available we simply update the in-memory $config below
	// (the caller does that anyway) and let phpBB write it on next save.
}

/**
* Print an inventory of source-board content into the conversion progress log.
*
* Called from `execute_first` so it runs once at the very start of the
* actual conversion (the source DB connection is open by that point but
* no data has been touched yet).  Each row is added to the framework's
* `checks` template block, which is what the convertor already uses to
* render every other progress message.  The result: the user gets an
* itemised summary -- "X users, Y posts, Z attachments..." -- right at
* the top of the conversion screen, so they have a realistic sense of
* what's about to be processed.
*
* All queries are wrapped in @-suppression and try/catch so that if
* an unusual vBulletin install is missing one of the optional tables
* (custom avatars, infractions, etc.) the conversion still proceeds;
* the corresponding row simply shows "n/a".
*/
function vb_log_source_inventory()
{
	global $src_db, $convert, $template, $user;

	// Each entry: array(label, src_table, optional_where, optional_select_expr).
	// optional_select_expr defaults to COUNT(*); use it for SUM / size totals.
	$items = array(
		array('Users (total)',                    'user',           '',                ''),
		array('Users (active, not banned/awaiting)', 'user',        'usergroupid NOT IN (3,4)', ''),
		array('Categories + forums (vB nodes)',   'forum',          '',                ''),
		array('Topics (excluding moved stubs)',   'thread',         'open <> 10',      ''),
		array(' .. of which sticky',              'thread',         'sticky = 1 AND open <> 10', ''),
		array('Redirect / moved-thread stubs',    'thread',         'open = 10',       ''),
		array('Posts',                            'post',           '',                ''),
		array('Polls',                            'poll',           '',                ''),
		array('Poll votes',                       'pollvote',       '',                ''),
		array('Private messages (recipients)',    'pm',             '',                ''),
		array('Private message bodies',           'pmtext',         '',                ''),
		array('Attachments',                      'attachment',     '',                ''),
		array('Attachments size',                 'attachment',     '',                'SUM(filesize)'),
		array('Custom avatars',                   'customavatar',   '',                ''),
		array('Profile fields',                   'profilefield',   '',                ''),
		array('Subscriptions (threads)',          'subscribethread','',                ''),
		array('Subscriptions (forums)',           'subscribeforum', '',                ''),
		array('Usergroups',                       'usergroup',      '',                ''),
		array('Smilies',                          'smilie',         '',                ''),
		array('Censored words',                   'word',           '',                ''),
		array('Reputation entries',               'reputation',     '',                ''),
		array('Infraction records',               'infraction',     '',                ''),
		array('Banned users',                     'userban',        '',                ''),
	);

	// Header row.
	if (isset($template))
	{
		$template->assign_block_vars('checks', array(
			'S_LEGEND'	=> true,
			'LEGEND'	=> 'Source board content (vBulletin 4) -- inventory before conversion',
		));
	}

	$prefix = $convert->src_table_prefix;

	foreach ($items as $row)
	{
		list($label, $table, $where, $select_expr) = $row;

		$select = ($select_expr !== '') ? $select_expr : 'COUNT(*)';
		$sql = 'SELECT ' . $select . ' AS total FROM ' . $prefix . $table
			. (($where !== '') ? ' WHERE ' . $where : '');

		// Some optional vB tables may not exist on every install.  Suppress
		// errors so a missing table just yields "n/a" instead of aborting.
		$src_db->sql_return_on_error(true);
		$result = @$src_db->sql_query($sql);
		$value = ($result) ? $src_db->sql_fetchfield('total') : false;
		if ($result)
		{
			$src_db->sql_freeresult($result);
		}
		$src_db->sql_return_on_error(false);

		if ($value === false || $value === null)
		{
			$display = 'n/a';
		}
		else if ($select_expr === 'SUM(filesize)')
		{
			// Format byte total as a human-friendly size.
			$bytes = (float) $value;
			if ($bytes >= 1073741824)
			{
				$display = number_format($bytes / 1073741824, 2) . ' GB';
			}
			else if ($bytes >= 1048576)
			{
				$display = number_format($bytes / 1048576, 1) . ' MB';
			}
			else
			{
				$display = number_format($bytes / 1024, 1) . ' KB';
			}
		}
		else
		{
			$display = number_format((int) $value);
		}

		if (isset($template))
		{
			$template->assign_block_vars('checks', array(
				'TITLE'		=> $label,
				'RESULT'	=> $display,
			));
		}
	}
}

/**
* Function for recoding text with the default language
*
* @param string $text text to recode to utf8
*/
function vb_set_encoding($text)
{
	global $src_db, $same_db, $convert;
	static $encoding;

	if ($encoding === null)
	{
		if ($convert->mysql_convert && $same_db)
		{
			$src_db->sql_query("SET NAMES 'binary'");
		}

		$sql = 'SELECT l.charset
			FROM ' . $convert->src_table_prefix . 'language l, ' .
				$convert->src_table_prefix . 'setting s
			WHERE s.varname = "languageid"
				AND l.languageid = s.value';
		$result = $src_db->sql_query($sql);
		$encoding = $src_db->sql_fetchfield('charset');
		$src_db->sql_freeresult($result);

		if ($convert->mysql_convert && $same_db)
		{
			$src_db->sql_query("SET NAMES 'utf8'");
		}

		// If the language row didn't return anything (very common on
		// old vB4 boards that have lost their language metadata) assume
		// Windows-1252 - it's a strict superset of ISO-8859-1 and the
		// most common legacy Western charset for vB installations.
		if (!$encoding)
		{
			$encoding = 'windows-1252';
		}
	}

	if ($text === null || $text === '')
	{
		return (string) $text;
	}

	$result = utf8_recode($text, $encoding);

	// If utf8_recode produced valid UTF-8, we're done.
	if (function_exists('mb_check_encoding') && mb_check_encoding($result, 'UTF-8'))
	{
		return $result;
	}

	// utf8_recode did NOT produce valid UTF-8.  Most common cause: the
	// declared source charset is something phpBB's recoder doesn't have a
	// table for, so it returned the raw bytes unchanged.  Re-attempt the
	// conversion from the ORIGINAL bytes treating them as Windows-1252 so
	// that we recover the actual character (e.g. \xAE -> ®) instead of
	// dropping/replacing it.
	if (function_exists('mb_convert_encoding'))
	{
		$forced = @mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
		if (is_string($forced) && $forced !== '' &&
			(!function_exists('mb_check_encoding') || mb_check_encoding($forced, 'UTF-8')))
		{
			return $forced;
		}
	}

	if (function_exists('iconv'))
	{
		$forced = @iconv('Windows-1252', 'UTF-8//IGNORE', $text);
		if ($forced !== false && $forced !== '')
		{
			return $forced;
		}
	}

	// Last resort: strip byte sequences that aren't legal UTF-8 from the
	// recoded string.  Better to lose a single character than to abort
	// the entire conversion with an SQL "Incorrect string value" error.
	if (function_exists('mb_convert_encoding'))
	{
		$prev_substitute = function_exists('mb_substitute_character') ? mb_substitute_character() : null;
		if (function_exists('mb_substitute_character'))
		{
			@mb_substitute_character(0xFFFD);
		}

		$cleaned = @mb_convert_encoding($result, 'UTF-8', 'UTF-8');

		if (function_exists('mb_substitute_character') && $prev_substitute !== null && $prev_substitute !== false)
		{
			@mb_substitute_character($prev_substitute);
		}

		if (is_string($cleaned))
		{
			return $cleaned;
		}
	}

	$invalid_utf8 = '/(?:'
		. '[\xC0-\xC1]'                                      // overlong 2-byte starts
		. '|[\xF5-\xFF]'                                     // bytes that never appear in UTF-8
		. '|\xE0[\x80-\x9F]'                                 // overlong 3-byte
		. '|\xF0[\x80-\x8F]'                                 // overlong 4-byte
		. '|[\xC2-\xDF](?![\x80-\xBF])'                      // truncated 2-byte
		. '|[\xE0-\xEF](?![\x80-\xBF]{2})'                   // truncated 3-byte
		. '|[\xF0-\xF4](?![\x80-\xBF]{3})'                   // truncated 4-byte
		. '|(?<![\xC2-\xDF]|[\xE0-\xEF][\x80-\xBF]?|[\xF0-\xF4][\x80-\xBF]{0,2})[\x80-\xBF]'  // orphan continuation
		. ')/';
	return preg_replace($invalid_utf8, '', $result);
}

/**
* Return correct user id value
* Everyone's id will be one higher to allow the guest/anonymous user to have a positive id as well
*/
function phpbb_user_id($user_id)
{
	global $config;

	vb_trace(sprintf('[%s] phpbb_user_id IN  uid=%s',
		date('H:i:s.') . substr(microtime(), 2, 4), var_export($user_id, true)
	));

	// Increment user id if the old forum is having a user with the id 1
	if (!isset($config['increment_user_id']))
	{
		vb_trace('  -> entering lazy-init block');
		global $src_db, $same_db, $convert;

		if ($convert->mysql_convert && $same_db)
		{
			$src_db->sql_query("SET NAMES 'binary'");
		}

		// Now let us set a temporary config variable for user id incrementing
		$sql = "SELECT userid
			FROM {$convert->src_table_prefix}user
			WHERE userid = 1";
		$result = $src_db->sql_query($sql);
		$id = (int) $src_db->sql_fetchfield('userid');
		$src_db->sql_freeresult($result);
		vb_trace('     id-1 lookup: ' . var_export($id, true));

		// Try to get the maximum user id possible...
		$sql = "SELECT MAX(userid) AS max_user_id
			FROM {$convert->src_table_prefix}user";
		$result = $src_db->sql_query($sql);
		$max_id = (int) $src_db->sql_fetchfield('max_user_id');
		$src_db->sql_freeresult($result);
		vb_trace('     max userid: ' . $max_id);

		if ($convert->mysql_convert && $same_db)
		{
			$src_db->sql_query("SET NAMES 'utf8'");
		}

		// If there is a user id 1, we need to increment user ids. :/
		if ($id === 1)
		{
			vb_trace('     setting increment_user_id to ' . ($max_id + 1));
			vb_set_phpbb_config('increment_user_id', ($max_id + 1), true);
			$config['increment_user_id'] = $max_id + 1;
		}
		else
		{
			vb_trace('     setting increment_user_id to 0 (no remap needed)');
			vb_set_phpbb_config('increment_user_id', 0, true);
			$config['increment_user_id'] = 0;
		}
	}

	// If the old user id is -1 in 2.0.x it is the anonymous user...
	if ($user_id == -1)
	{
		vb_trace('  -> mapping -1 to ANONYMOUS');
		return ANONYMOUS;
	}
	if ($user_id == 0)
	{
		vb_trace('  -> mapping 0 to ANONYMOUS');
		return ANONYMOUS;
	}

	if (!empty($config['increment_user_id']) && $user_id == 1)
	{
		$ret = (int) $config['increment_user_id'];
		vb_trace('  -> remapping vB userid 1 to phpBB user_id ' . $ret);
		return $ret;
	}

	$ret = (int) $user_id;
	vb_trace('  -> returning ' . $ret . ' (pass-through)');
	return $ret;
}

/**
* Determine the file extension using the file name.
*/
function vb_file_ext($filename)
{
	return strtolower(substr(strrchr($filename,'.'),1));
}

/**
* Set forum flags - only prune old polls by default
*/
function phpbb_forum_flags()
{
	// Set forum flags
	$forum_flags = 0;

	// FORUM_FLAG_LINK_TRACK
	$forum_flags += 0;

	// FORUM_FLAG_PRUNE_POLL
	$forum_flags += FORUM_FLAG_PRUNE_POLL;

	// FORUM_FLAG_PRUNE_ANNOUNCE
	$forum_flags += 0;

	// FORUM_FLAG_PRUNE_STICKY
	$forum_flags += 0;

	// FORUM_FLAG_ACTIVE_TOPICS
	$forum_flags += 0;

	// FORUM_FLAG_POST_REVIEW
	$forum_flags += FORUM_FLAG_POST_REVIEW;

	return $forum_flags;
}

/**
* Calculate the left right id's for forums. This is a recursive function.
*/
function vb_left_right_ids($groups, $parent_id, &$forums, &$node)
{
	foreach ($groups[$parent_id] as $forum_id)
	{
		$forums[$forum_id]['left_id'] = $node++;

		if (!empty($groups[$forum_id]))
		{
			vb_left_right_ids($groups, $forum_id, $forums, $node);
		}

		$forums[$forum_id]['right_id'] = $node++;
	}
}

/**
* Insert/Convert forums
*/
function vb_insert_forums()
{
	global $db, $src_db, $same_db, $convert;

	$db->sql_query($convert->truncate_statement . FORUMS_TABLE);

	if ($convert->mysql_convert && $same_db)
	{
		$src_db->sql_query("SET NAMES 'binary'");
	}

	$sql = 'SELECT forumid, title, description, options, displayorder, parentid, password, link
		FROM ' . $convert->src_table_prefix . 'forum
		ORDER BY parentid, displayorder ASC';
	$result = $src_db->sql_query($sql);
	$forums = $forum_groups = array();

	while ($row = $src_db->sql_fetchrow($result))
	{
		$row['parentid'] = ($row['parentid'] == -1) ? 0 : $row['parentid'];

		if (!empty($row['link']))
		{
			$row['forum_type'] = FORUM_LINK;
		}
		else if ($row['options'] & 4)
		{
			$row['forum_type'] = FORUM_POST;
		}
		else
		{
			$row['forum_type'] = FORUM_CAT;
		}

		$forums[$row['forumid']] = $row;
		$forum_groups[$row['parentid']][] = $row['forumid'];
	}
	$src_db->sql_freeresult($result);

	if ($convert->mysql_convert && $same_db)
	{
		$src_db->sql_query("SET NAMES 'utf8'");
	}

	switch ($db->sql_layer)
	{
		case 'mssql':
		case 'mssql_odbc':
		case 'mssqlnative':
			$db->sql_query('SET IDENTITY_INSERT ' . FORUMS_TABLE . ' ON');
		break;
	}

	// Calculate the left and right id's
	$node = 1;
	vb_left_right_ids($forum_groups, 0, $forums, $node);

	foreach ($forums as $forum_id => $row)
	{
		$row['description'] = preg_replace('#<(br|br/|br /|br\s/)>#i', "\n", $row['description']);

		$sql_ary = array(
			'forum_id'			=> (int) $forum_id,
			'forum_name'		=> utf8_htmlspecialchars(vb_set_encoding($row['title'])),
			'parent_id'			=> (int) $row['parentid'],
			'forum_parents'		=> '',
			'forum_desc'		=> htmlspecialchars(vb_set_encoding(htmlspecialchars_decode(html_entity_decode($row['description']), ENT_QUOTES)), ENT_COMPAT, 'UTF-8'),
			'forum_type'		=> $row['forum_type'],
			'forum_status'		=> ($row['options'] & 2) ? ITEM_UNLOCKED : ITEM_LOCKED,
			'forum_link'		=> $row['link'],
			'forum_password'	=> $row['password'],
			'forum_flags'		=> phpbb_forum_flags(),
			'left_id'			=> $row['left_id'],
			'right_id'			=> $row['right_id'],
			'enable_icons'		=> 1,

			// Default values
			'forum_desc_bitfield'		=> '',
			'forum_desc_options'		=> 7,
			'forum_desc_uid'			=> '',
			'forum_style'				=> 0,
			'forum_image'				=> '',
			'forum_rules'				=> '',
			'forum_rules_link'			=> '',
			'forum_rules_bitfield'		=> '',
			'forum_rules_options'		=> 7,
			'forum_rules_uid'			=> '',
			'forum_topics_per_page'		=> 0,
			// phpBB 3.1+ replaced forum_posts/forum_topics/forum_topics_real with
			// the approved/unapproved/softdeleted triplets.  Inserting the legacy
			// columns against a 3.3 schema raises "Unknown column" SQL errors.
			'forum_posts_approved'		=> 0,
			'forum_posts_unapproved'	=> 0,
			'forum_posts_softdeleted'	=> 0,
			'forum_topics_approved'		=> 0,
			'forum_topics_unapproved'	=> 0,
			'forum_topics_softdeleted'	=> 0,
			'forum_last_post_id'		=> 0,
			'forum_last_poster_id'		=> 0,
			'forum_last_post_subject'	=> '',
			'forum_last_post_time'		=> 0,
			'forum_last_poster_name'	=> '',
			'forum_last_poster_colour'	=> '',
			'display_on_index'			=> 1,
			'enable_indexing'			=> 1,
		);

		$sql = 'INSERT INTO ' . FORUMS_TABLE . ' ' . $db->sql_build_array('INSERT', $sql_ary);
		$db->sql_query($sql);
	}

	switch ($db->sql_layer)
	{
		case 'mssql':
		case 'mssql_odbc':
		case 'mssqlnative':
			$db->sql_query('SET IDENTITY_INSERT ' . FORUMS_TABLE . ' OFF');
		break;
	}
}

/**
* Import topic/post icons
*/
function vb_import_icon($source)
{
	global $convert;

	$dim = get_image_dim($source);
	$source = $convert->convertor_data['forum_path'] . '/' . $source;
	$result = _import_check('icons_path', $source, false);

	if (!$result['copied'])
	{
		$result['target'] = utf8_basename($result['target']);
	}
	if (empty($dim))
	{
		$dim = array(0, 0);
	}

	$convert->row['icon_height'] = $dim[0];
	$convert->row['icon_width'] = $dim[1];

	return $result['target'];
}

/**
* Fetch the topic/post icon height.
*/
function vb_icon_height()
{
	global $convert;

	return $convert->row['icon_height'];
}

/**
* Fetch the topic/post icon width.
*/
function vb_icon_width()
{
	global $convert;

	return $convert->row['icon_width'];
}

/**
* Determine how many poll options can be voted for.
*/
function vb_poll_options($multiple)
{
	global $convert_row;

	return (!$multiple) ? 1 : (int) $convert_row['numberoptions'];
}

/**
* Convert a vBulletin poll timeout (in days) to a phpBB poll_length (in seconds),
* with bounds-checking so we don't overflow the destination column.
*
* phpBB poll_length is INT(11) UNSIGNED -- max 4,294,967,295 seconds (~136 years).
* vBulletin uses sentinel values like 65535 days (~179 years) to mean "never
* expires", and a naive days*86400 conversion produces 5,662,224,000 which the
* MySQL driver rejects with "Out of range value for column 'poll_length'".
*
* Anything that would overflow gets clamped to the column max.  A vBulletin
* timeout of 0 (no expiration) maps to 0 here too, which phpBB also treats as
* "never expires".
*/
function vb_poll_length($days)
{
	$days = (int) $days;

	if ($days <= 0)
	{
		return 0;
	}

	// 49710 days * 86400 = 4,294,944,000 -- the largest day count that won't
	// overflow phpBB's INT(11) UNSIGNED column.  Clamp anything bigger.
	if ($days > 49710)
	{
		return 0;   // phpBB convention: 0 = never expires
	}

	return $days * 86400;
}

/**
* Extract poll options and votes from strings and insert them into the poll options database.
*/
function vb_insert_poll_options()
{
	global $db, $convert_row;

	$options = explode('|||', $convert_row['options']);
	$votes = explode('|||', $convert_row['votes']);
	$sql_ary = array();
	$i = 1;

	foreach ($options as $index => $option)
	{
		$sql_ary[] = array(
			'poll_option_id'	=> $i,
			'topic_id'			=> (int) $convert_row['threadid'],
			'poll_option_text'	=> vb_set_encoding($option),
			'poll_option_total'	=> (int) $votes[$index]
		);
		$i++;
	}

	if (!empty($sql_ary))
	{
		$db->sql_multi_insert(POLL_OPTIONS_TABLE, $sql_ary);
	}
}

/**
* Determine if the image has a thumbnail.
*/
function vb_attach_has_thumbnail()
{
	global $convert_row;

	if ($convert_row['height'] > VB_THUMB_LIMIT || $convert_row['width'] > VB_THUMB_LIMIT)
	{
		return 1;
	}
	return 0;
}

/**
* Import attachment file
*/
function vb_import_attachment($user_id)
{
	global $convert, $convert_row;

	$user_id = (int) $user_id;
	$target = phpbb_user_id($user_id) . '_' . md5(unique_id());
	$storage_method = get_config_value('attachfile');
	$thumbnail = vb_attach_has_thumbnail();

	// Attachments are stored in the database if $storage_method is 0.
	if ($storage_method < 1)
	{
		vb_write_file('upload_path', $convert_row['filedata'], $target);

		if ($thumbnail)
		{
			vb_write_file('upload_path', $convert_row['thumbnail'], 'thumb_' . $target);
		}
		return $target;
	}
	else
	{
		$dir = ($storage_method == 2) ? $user_id . '/' : '';
		$source = $dir . $convert_row['attachmentid'] . '.attach';
		// The attachment path is absolute, so we'll temporarily set this setting to true so that that import_attachment() works correctly.
		$convert->convertor['source_path_absolute'] = true;

		if ($thumbnail)
		{
			$thumbnail = $dir . $convert_row['attachmentid'] . '.thumb';
			import_attachment($thumbnail, 'thumb_' . $target);
		}

		import_attachment($source, $target);
		$convert->convertor['source_path_absolute'] = false;

		return $target;
	}

	return '';
}

/**
* Write database file data to the filesystem
*/
function vb_write_file($config_var, $file_data, $target)
{
	global $config, $phpbb_root_path, $convert;

	$write_dir = $phpbb_root_path . $config[$config_var] . '/';

	if (!($fp = fopen($write_dir . $target, 'w')) )
	{
		$lang['INST_ERR_FATAL'] = $user->lang['CONV_ERR_FATAL'];
		$convert->p_master->error('<span style="color:red">Unable to write to ' . htmlspecialchars($write_dir) . '</span>', __LINE__, __FILE__);
	}
	else
	{
		fwrite($fp, $file_data);
		fclose($fp);
	}
}

/**
* Convert the group name, making sure to avoid conflicts with 3.0 special groups
*/
function phpbb_convert_group_name($group_name)
{
	$default_groups = array(
		'GUESTS',
		'REGISTERED',
		'REGISTERED_COPPA',
		'GLOBAL_MODERATORS',
		'ADMINISTRATORS',
		'BOTS',
	);

	if (in_array(strtoupper($group_name), $default_groups))
	{
		return 'vB - ' . $group_name;
	}

	return utf8_htmlspecialchars(vb_set_encoding($group_name));
}

/**
* Convert the group type constants
*/
function phpbb_convert_group_type($group_public)
{
	global $convert_row;

	// The first 8 groups in vB are special groups. These aren't visible to users.
	if ($convert_row['usergroupid'] <= 8)
	{
		return GROUP_HIDDEN;
	}

	return ($group_public) ? GROUP_OPEN : GROUP_CLOSED;
}

function vb_replace_size($matches)
{
	return '[size=' . min(200, ceil(100.0 * (((double) $matches[1])/12.0))) . ']';
}

/**
* Wrap smiley codes with space so phpBB can parse them correctly.
*/
function vb_reformat_smilies(&$message)
{
	global $convert, $src_db, $same_db;
	static $smilies;

	if ($smilies === null)
	{
		if ($convert->mysql_convert && $same_db)
		{
			$src_db->sql_query("SET NAMES 'binary'");
		}

		$sql = 'SELECT smilietext
			FROM ' . $convert->src_table_prefix . 'smilie';
		$result = $src_db->sql_query($sql);
		$smilies = array();

		while ($row = $src_db->sql_fetchrow($result))
		{
			$smilies[] = $row['smilietext'];
		}
		$src_db->sql_freeresult($result);

		if ($convert->mysql_convert && $same_db)
		{
			$src_db->sql_query("SET NAMES 'utf8'");
		}
	}

	foreach ($smilies as $smiley)
	{
		if (strpos($message, $smiley) !== false)
		{
			$search = '#([^\s])?(' . preg_quote($smiley, '#') . ')([^\s])?#';
			$message = preg_replace_callback($search, 'vb_smiley_space', $message);
		}
	}
}

/**
* Wrap smiley code with space.
*/
function vb_smiley_space($match)
{
	$replace = (!empty($match[1])) ? $match[1] . ' ' : '';
	$replace .= $match[2];
	$replace .= (!empty($match[3])) ? ' ' . $match[3] : '';

	return $replace;
}

/**
* Reformat inline attachment bbcode to the proper phpBB format.
*/
function vb_reformat_inline_attach(&$message, $post_id)
{
	global $src_db, $convert, $same_db;

	// Do a simple check for the presence of opening and closing tags before continuing.
	if (strpos($message, '[ATTACH=CONFIG]') !== false && strpos($message, '[/ATTACH]') !== false)
	{
		if ($convert->mysql_convert && $same_db)
		{
			$src_db->sql_query("SET NAMES 'binary'");
		}
		// We need to grab some info from the database :-/
		$sql = 'SELECT attachmentid, filename
			FROM ' . $convert->src_table_prefix . 'attachment
			WHERE contenttypeid = 1
				AND contentid = ' . (int) $post_id . '
			ORDER BY attachmentid DESC';
		$result = $src_db->sql_query($sql);
		$i = 1;

		while ($row = $src_db->sql_fetchrow($result))
		{
			$find = '[ATTACH=CONFIG]' . $row['attachmentid'] . '[/ATTACH]';
			$replace = '[attachment=' . $i . ']' . $row['filename'] . '[/attachment]';

			$message = str_replace($find, $replace, $message); 
		}
		$src_db->sql_freeresult($result);

		if ($convert->mysql_convert && $same_db)
		{
			$src_db->sql_query("SET NAMES 'utf8'");
		}
	}
}

/**
* Reparse the message stripping out the bbcode_uid values and adding new ones and setting the bitfield
* @todo What do we want to do about HTML in messages - currently it gets converted to the entities, but there may be some objections to this
*/
function vb_prepare_message($message)
{
	global $convert, $user, $convert_row, $message_parser;

	if (!$message)
	{
		$convert->row['mp_bbcode_bitfield'] = $convert_row['mp_bbcode_bitfield'] = 0;
		return '';
	}
	
	$message = preg_replace('#<(br|br/|br /|br\s/)>#i', "\n", $message);

	if (!empty($convert_row['allowsmilie']))
	{
		vb_reformat_smilies($message);
	}

	// Convert inline attachment bbcode
	if (!empty($convert_row['attach']))
	{
		vb_reformat_inline_attach($message, $convert_row['postid']);
	}

	$message = preg_replace('#\[glow=(.*?):(.*?)\]#i', "[glow=\\1]", $message);
	$message = preg_replace('#\[shadow=(.*?):(.*?)\]#i', "[shadow=\\1]", $message);
	$message = preg_replace('#\[\/glow:(.*?)\]#i', "[/glow]", $message);
	$message = preg_replace('#\[\/shadow:(.*?)\]#i', "[/shadow]", $message);
	$message = preg_replace( '/\[url=\"(.+?)\"]/si', "[url=\\1]", $message );
	$message = preg_replace( '/\[url=\'(.+?)\']/si', "[url=\\1]", $message );

	$bbcode_replacements = array(
		'[center]'	=> '[align=center]',
		'[/center]'	=> '[/align]',
		'<center>'	=> '[align=center]',
		'</center>' => '[/align]',
		'[left]'	=> '[align=left]',
		'[/left]'	=> '[/align]',
		'[right]'	=> '[align=right]',
		'[/right]'	=> '[/align]',
		'[li]'		=> '[*]',
		'[FONT='	=> '[font=',
		'[/FONT]'	=> '[/font]',
		'[COLOR='	=> '[color=',
		'[/COLOR]'	=> '[/color]',
		'[QUOTE'	=> '[quote',
		'[PHP]'		=> '[code=php]',
		'[/PHP]'	=> '[/code]',
		'[/SIZE]'	=> '[/size]',
	);

	$message = str_replace(array_keys($bbcode_replacements), $bbcode_replacements, $message);

	//	From vb3 to phpBB2 convertor
	// PHP 7 removed the /e modifier; convert legacy size codes (1,2,etc.) to point sizes via callback first,
	// then run the second callback which scales those point sizes into phpBB percentage sizes.
	if (stripos($message, '[size=') !== false)
	{
		$message = preg_replace_callback('/\[size=([0-9]+)\]/i', 'vb_legacy_size_to_pt', $message);
		$message = preg_replace_callback('/\[size=(\d*)\]/i', 'vb_replace_size', $message);

		// Final sanity clamp: catch any [size=N] tag whose numeric part exceeds
		// phpBB's MAX_FONT_SIZE (200), or is empty/zero/non-numeric, and rewrite
		// to a safe value.  Without this pass, a single bad source value (e.g.
		// "[size=300pt]" or "[size=]" that didn't match the previous regexes
		// cleanly, or "[size=N]" produced by stray nested-tag interactions)
		// triggers phpBB's "You may only use fonts up to size 200" parser error
		// for every post that contained the stray tag.
		$message = preg_replace_callback(
			'/\[size=([^\]]*)\]/i',
			function ($m) {
				$n = (int) $m[1];
				if ($n < 1) { return '[size=100]'; }   // empty / non-numeric -> default
				if ($n > 200) { return '[size=200]'; } // clamp to phpBB MAX_FONT_SIZE
				return '[size=' . $n . ']';
			},
			$message
		);
	}

	if (strpos($message, '[quote=') !== false)
	{
		$message = preg_replace('/\[quote="(.*?)"\]/s', '[quote=&quot;\1&quot;]', $message);
		$message = preg_replace('/\[quote=(.*?);(.*?)\]/s', '[quote=\1]', $message);
		$message = preg_replace('/\[quote=(.*?)\]/s', '[quote=&quot;\1&quot;]', $message);
	}

	// Already the new user id ;)
	$user_id = $convert->row['poster_id'];

	$message = str_replace('<', '&lt;', $message);
	$message = str_replace('>', '&gt;', $message);

	// make the post UTF-8
	$message = vb_set_encoding($message);

	$message_parser->warn_msg = array(); // Reset the errors from the previous message
	$message_parser->bbcode_uid = make_uid($convert->row['post_time']);
	$message_parser->message = $message;
	unset($message);

	// Make sure options are set.
	$enable_bbcode = (!isset($convert->row['enable_bbcode'])) ? true : $convert->row['enable_bbcode'];
	$enable_smilies = (!isset($convert->row['allowsmilie'])) ? true : $convert->row['allowsmilie'];
	$enable_magic_url = (!isset($convert->row['enable_magic_url'])) ? true : $convert->row['enable_magic_url'];

	// parse($allow_bbcode, $allow_magic_url, $allow_smilies, $allow_img_bbcode = true, $allow_flash_bbcode = true, $allow_quote_bbcode = true, $allow_url_bbcode = true, $update_this_message = true, $mode = 'post')
	$message_parser->parse($enable_bbcode, $enable_magic_url, $enable_smilies);
	
	if (sizeof($message_parser->warn_msg))
	{
		$msg_id = isset($convert->row['post_id']) ? $convert->row['post_id'] : $convert->row['privmsgs_id'];
		$convert->p_master->error('<span style="color:red">' . $user->lang['POST_ID'] . ': ' . $msg_id . ' ' . $user->lang['CONV_ERROR_MESSAGE_PARSER'] . ': <br /><br />' . implode('<br />', $message_parser->warn_msg), __LINE__, __FILE__, true);
	}

	$convert->row['mp_bbcode_bitfield'] = $convert_row['mp_bbcode_bitfield'] = $message_parser->bbcode_bitfield;

	$message = $message_parser->message;
	unset($message_parser->message);

	return $message;
}

/**
* Return the bitfield calculated by the previous function
*/
function get_bbcode_bitfield()
{
	global $convert_row;

	return $convert_row['mp_bbcode_bitfield'];
}

/**
* Determine the last user to edit a post
* In practice we only tracked edits by the original poster in 2.0.x so this will only be set if they had edited their own post
*/
function phpbb_post_edit_user()
{
	global $convert_row, $config;

	if (isset($convert_row['post_edit_count']))
	{
		return phpbb_user_id($convert_row['poster_id']);
	}

	return 0;
}

/**
* Calculate the correct to_address field for private messages
*/
function vb_privmsgs_to_users($user_array)
{
	// PHP 7+ best practice: forbid object instantiation when unserialising untrusted data.
	if (PHP_VERSION_ID >= 70000)
	{
		$users = @unserialize($user_array, array('allowed_classes' => false));
	}
	else
	{
		$users = @unserialize($user_array);
	}

	if (!is_array($users) || empty($users['cc']))
	{
		return '';
	}

	$to = '';

	foreach ($users['cc'] as $user_id => $username)
	{
		$to .= ((!empty($to)) ? ', ' : '') . 'u_' . phpbb_user_id($user_id);
	}

	return $to;
}

/**
* Calculate whether a private message was unread using the bitfield
*/
function vb_unread_pm($messageread)
{
	return ($messageread == 0) ? 1 : 0;
}

/**
* 没回复就算新的？
*/
function vb_new_pm($messageread)
{
	return ($messageread == 2) ? 0 : 1;
}
function vb_replied_pm($messageread)
{
	return ($messageread == 2) ? 1 : 0;
}

/**
* Convert birthday to phpBB Format
*/
function vb_get_birthday($birthday = '')
{
	$birthday = (string) $birthday;

	// stored as month, day, year
	if (!$birthday)
	{
		return ' 0- 0-   0';
	}

	// Expected format from vB3 is MM-DD-YYYY
	$birthday_parts = explode('-',$birthday);

	$month = $birthday_parts[0];
	$day = $birthday_parts[1];
	$year =  $birthday_parts[2];

	return sprintf('%2d-%2d-%4d', $day, $month, $year);
}

/**
* Set primary group.
*/
function vb_set_primary_group($group_id)
{
	if ($group_id == 6)
	{
		return get_group_id('administrators');
	}
	else if ($group_id == 3)
	{
		// Group 3 is the Users Awaiting Email Confirmation group. These users not activated in phpBB.
		return 0;
	}
	else
	{
		return get_group_id('registered');
	}
}

/**
* Set user type.
*/
function vb_set_user_type($group_id)
{
	vb_trace(sprintf('[%s] vb_set_user_type IN  group_id=%s (called from USER-TABLE row processing)',
		date('H:i:s.') . substr(microtime(), 2, 4), var_export($group_id, true)
	));

	if ($group_id == 6)
	{
		return USER_FOUNDER;
	}
	else if ($group_id == 3)
	{
		// Group 3 is the Users Awaiting Email Confirmation group. These users not activated in phpBB.
		return USER_INACTIVE;
	}
	else
	{
		return USER_NORMAL;
	}
}

function vb_avatar($gallery_avatar_name)
{
	global $config, $convert_row;

	// If the filename field isn't empty, then we're using a custom avatar
	if (!empty($convert_row['filename']))
	{
		$user_id = phpbb_user_id($convert_row['userid']);
		$source = $convert_row['filename'];
		$ext = '.' . vb_file_ext($source);
		$user_avatar = $user_id . '_' . time() . $ext;

		if (VB_AVATAR_IN_DB)
		{
			$target =  $config['avatar_salt'] . '_' . $user_id . $ext;
			vb_write_file('avatar_path', $convert_row['filedata_thumb'], $target);
		}
		else
		{
			import_avatar($source, false, $user_id);
		}

		return $user_avatar;
	}
	else if (!empty($convert_row['avatarid']))
	{
		return $convert_row['avatarid'] . '.' . vb_file_ext($gallery_avatar_name);
	}

	return '';
}

function vb_avatar_type()
{
	global $convert_row;

	if (!empty($convert_row['filename']))
	{
		return AVATAR_UPLOAD;
	}
	else if (!empty($convert_row['avatarid']))
	{
		return AVATAR_GALLERY;
	}
	return 0;
}

/**
* Find out about the avatar's dimensions
*/
function vb_get_avatar_height($user_avatar)
{
	global $convert_row;

	if (!empty($convert_row['height_thumb']))
	{
		return (int) $convert_row['height_thumb'];
	}
	return get_avatar_height(basename($user_avatar), false, AVATAR_GALLERY);
}


/**
* Find out about the avatar's dimensions
*/
function vb_get_avatar_width($user_avatar)
{
	global $convert_row;

	if (!empty($convert_row['width_thumb']))
	{
		return (int) $convert_row['width_thumb'];
	}
	return get_avatar_width(basename($user_avatar), false, AVATAR_GALLERY);
}

/**
* Convert some user options
*/
function vb_user_options($options)
{
	global $convert_row;

	$convert_row = array_merge($convert_row, array(
		'popuppm'	=> ($options & 524288) ? 1 : 0,
		'viewsigs'	=> ($options & 1) ? 1 : 0,
		'viewimg'	=> ($options & 4) ? 1 : 0,
	));
}

/**
* Calculate the date a user became inactive
*/
function vb_inactive_time()
{
	global $convert_row;

	if ($convert_row['usergroupid'] == 3)
	{
		return $convert_row['joindate'];
	}
	return 0;
}

/**
* Calculate the reason a user became inactive
*/
function vb_inactive_reason()
{
	global $convert_row;

	if ($convert_row['usergroupid'] == 3)
	{
		return INACTIVE_REGISTER;
	}
	return 0;
}

/**
* Determine if the user wants to be autosubscribed.
*
* -1 = Not subscribed
* 0 = Subscribed, no email
* 1 = Instant email
* 2 = Daily email
* 3 = Weekly email
* 4 = Instant icq notification
*/
function vb_autosubscribe($type)
{
	return ($type > 0) ? 1 : 0;
}

/**
* Convert authentication
* user, group and forum table has to be filled in order to work
*/
function phpbb_convert_authentication($mode)
{
	global $db, $src_db, $same_db, $convert, $user, $config, $cache;

	if ($mode == 'start')
	{
		$db->sql_query($convert->truncate_statement . ACL_USERS_TABLE);
		$db->sql_query($convert->truncate_statement . ACL_GROUPS_TABLE);

		// What we will do is handling all 2.0.x admins as founder to replicate what is common in 2.0.x.
		// After conversion the main admin need to make sure he is removing permissions and the founder status if wanted.


		// Grab user ids of users with user_level of ADMIN
		$sql = "SELECT userid as user_id
			FROM {$convert->src_table_prefix}user
			WHERE usergroupid = 6
			ORDER BY joindate ASC";
		$result = $src_db->sql_query($sql);

		while ($row = $src_db->sql_fetchrow($result))
		{
			$user_id = (int) phpbb_user_id($row['user_id']);

			// Set founder admin...
			$sql = 'UPDATE ' . USERS_TABLE . '
				SET user_type = ' . USER_FOUNDER . "
				WHERE user_id = $user_id";
			$db->sql_query($sql);
		}
		$src_db->sql_freeresult($result);
	}

	// Grab forum auth information
	$sql = "SELECT *
		FROM {$convert->src_table_prefix}forum";
	$result = $src_db->sql_query($sql);

	$forum_access = array();
	while ($row = $src_db->sql_fetchrow($result))
	{
		$forum_access[] = $row;
	}
	$src_db->sql_freeresult($result);

	if ($convert->mysql_convert && $same_db)
	{
		$src_db->sql_query("SET NAMES 'binary'");
	}
	// vb3 中好像没有使用用户权限，只使用了组权限，这儿直接提取版主权限
	// Grab user auth information from vB3.x board
	$sql="SELECT * from {$convert->src_table_prefix}moderator WHERE forumid > 0";
	$result = $src_db->sql_query($sql);

	$user_access = array();
	while ($row = $src_db->sql_fetchrow($result))
	{
		$user_access[$row['forumid']][] = $row;
	}
	$src_db->sql_freeresult($result);

	// Grab group auth information
	$sql = "SELECT *	FROM {$convert->src_table_prefix}forumpermission where usergroupid>1";
	$result = $src_db->sql_query($sql);

	$group_access = array();
	while ($row = $src_db->sql_fetchrow($result))
	{
		$group_access[$row['forumid']][] = $row;
	}
	$src_db->sql_freeresult($result);

	if ($convert->mysql_convert && $same_db)
	{
		$src_db->sql_query("SET NAMES 'utf8'");
	}

	// Add Forum Access List
	$auth_map = array(
		'auth_view'			=> array('f_', 'f_list'),
		'auth_read'			=> array('f_read', 'f_search'),
		'auth_post'			=> array('f_post', 'f_bbcode', 'f_smilies', 'f_img', 'f_sigs', 'f_postcount', 'f_report', 'f_subscribe', 'f_print', 'f_email'),
		'auth_reply'		=> 'f_reply',
		'auth_edit'			=> 'f_edit',
		'auth_delete'		=> 'f_delete',
		'auth_pollcreate'	=> 'f_poll',
		'auth_vote'			=> 'f_vote',
		'auth_announce'		=> 'f_announce',
		'auth_sticky'		=> 'f_sticky',
		'auth_attachments'	=> array('f_attach', 'f_download'),
		'auth_download'		=> 'f_download',
	);

	// Define the ACL constants used in 2.0 to make the code slightly more readable
	define('AUTH_ALL', 0);
	define('AUTH_REG', 1);
	define('AUTH_ACL', 2);
	define('AUTH_MOD', 3);
	define('AUTH_ADMIN', 5);

	// A mapping of the simple permissions used by 2.0
	$simple_auth_ary = array(
		'public'			=> array(
			'auth_view'			=> AUTH_ALL,
			'auth_read'			=> AUTH_ALL,
			'auth_post'			=> AUTH_ALL,
			'auth_reply'		=> AUTH_ALL,
			'auth_edit'			=> AUTH_REG,
			'auth_delete'		=> AUTH_REG,
			'auth_sticky'		=> AUTH_MOD,
			'auth_announce'		=> AUTH_MOD,
			'auth_vote'			=> AUTH_REG,
			'auth_pollcreate'	=> AUTH_REG,
		),
		'registered'		=> array(
			'auth_view'			=> AUTH_ALL,
			'auth_read'			=> AUTH_ALL,
			'auth_post'			=> AUTH_REG,
			'auth_reply'		=> AUTH_REG,
			'auth_edit'			=> AUTH_REG,
			'auth_delete'		=> AUTH_REG,
			'auth_sticky'		=> AUTH_MOD,
			'auth_announce'		=> AUTH_MOD,
			'auth_vote'			=> AUTH_REG,
			'auth_pollcreate'	=> AUTH_REG,
		),
		'registered_hidden'	=> array(
			'auth_view'			=> AUTH_REG,
			'auth_read'			=> AUTH_REG,
			'auth_post'			=> AUTH_REG,
			'auth_reply'		=> AUTH_REG,
			'auth_edit'			=> AUTH_REG,
			'auth_delete'		=> AUTH_REG,
			'auth_sticky'		=> AUTH_MOD,
			'auth_announce'		=> AUTH_MOD,
			'auth_vote'			=> AUTH_REG,
			'auth_pollcreate'	=> AUTH_REG,
		),
		'private'			=> array(
			'auth_view'			=> AUTH_ALL,
			'auth_read'			=> AUTH_ACL,
			'auth_post'			=> AUTH_ACL,
			'auth_reply'		=> AUTH_ACL,
			'auth_edit'			=> AUTH_ACL,
			'auth_delete'		=> AUTH_ACL,
			'auth_sticky'		=> AUTH_ACL,
			'auth_announce'		=> AUTH_MOD,
			'auth_vote'			=> AUTH_ACL,
			'auth_pollcreate'	=> AUTH_ACL,
		),
		'private_hidden'	=> array(
			'auth_view'			=> AUTH_ACL,
			'auth_read'			=> AUTH_ACL,
			'auth_post'			=> AUTH_ACL,
			'auth_reply'		=> AUTH_ACL,
			'auth_edit'			=> AUTH_ACL,
			'auth_delete'		=> AUTH_ACL,
			'auth_sticky'		=> AUTH_ACL,
			'auth_announce'		=> AUTH_MOD,
			'auth_vote'			=> AUTH_ACL,
			'auth_pollcreate'	=> AUTH_ACL,
		),
		'moderator'			=> array(
			'auth_view'			=> AUTH_ALL,
			'auth_read'			=> AUTH_MOD,
			'auth_post'			=> AUTH_MOD,
			'auth_reply'		=> AUTH_MOD,
			'auth_edit'			=> AUTH_MOD,
			'auth_delete'		=> AUTH_MOD,
			'auth_sticky'		=> AUTH_MOD,
			'auth_announce'		=> AUTH_MOD,
			'auth_vote'			=> AUTH_MOD,
			'auth_pollcreate'	=> AUTH_MOD,
		),
		'moderator_hidden'	=> array(
			'auth_view'			=> AUTH_MOD,
			'auth_read'			=> AUTH_MOD,
			'auth_post'			=> AUTH_MOD,
			'auth_reply'		=> AUTH_MOD,
			'auth_edit'			=> AUTH_MOD,
			'auth_delete'		=> AUTH_MOD,
			'auth_sticky'		=> AUTH_MOD,
			'auth_announce'		=> AUTH_MOD,
			'auth_vote'			=> AUTH_MOD,
			'auth_pollcreate'	=> AUTH_MOD,
		),
	);

	if ($mode == 'start')
	{
		user_group_auth('guests', 'SELECT user_id, {GUESTS} FROM ' . USERS_TABLE . ' WHERE user_id = ' . ANONYMOUS, false);
		user_group_auth('registered', 'SELECT user_id, {REGISTERED} FROM ' . USERS_TABLE . ' WHERE user_id <> ' . ANONYMOUS, false);

		// Selecting from old table
		if (!empty($config['increment_user_id']))
		{
			$auth_sql = 'SELECT userid as user_id, {ADMINISTRATORS} FROM ' . $convert->src_table_prefix . 'user WHERE usergroupid = 6 AND userid <> 1';
			user_group_auth('administrators', $auth_sql, true);

			$auth_sql = 'SELECT ' . $config['increment_user_id'] . ' as user_id, {ADMINISTRATORS} FROM ' . $convert->src_table_prefix . 'user WHERE usergroupid = 6 AND userid = 1';
			user_group_auth('administrators', $auth_sql, true);
		}
		else
		{
			$auth_sql = 'SELECT userid as user_id, {ADMINISTRATORS} FROM ' . $convert->src_table_prefix . 'user WHERE usergroupid = 6';
			user_group_auth('administrators', $auth_sql, true);
		}

		if (!empty($config['increment_user_id']))
		{
			$auth_sql = 'SELECT userid as user_id, {GLOBAL_MODERATORS} FROM ' . $convert->src_table_prefix . 'user WHERE usergroupid in (5,6) AND userid <> 1';
			user_group_auth('global_moderators', $auth_sql, true);

			$auth_sql = 'SELECT ' . $config['increment_user_id'] . ' as user_id, {GLOBAL_MODERATORS} FROM ' . $convert->src_table_prefix . 'user WHERE usergroupid in (5,6) AND userid = 1';
			user_group_auth('global_moderators', $auth_sql, true);
		}
		else
		{
			$auth_sql = 'SELECT userid as user_id, {GLOBAL_MODERATORS} FROM ' . $convert->src_table_prefix . 'user WHERE usergroupid in (5,6)';
			user_group_auth('global_moderators', $auth_sql, true);
		}
	}
	else if ($mode == 'first')
	{
		// Go through all vB3.x forums
		foreach ($forum_access as $forum)
		{
			$new_forum_id = (int) $forum['forumid'];

			// Administrators have full access to all forums whatever happens
			mass_auth('group_role', $new_forum_id, 'administrators', 'FORUM_FULL');
			//在这儿简单化处理，转换完毕后需到后台重新进行调整
			$matched_type = 'public';
			switch ($matched_type)
			{
				case 'public':
					mass_auth('group_role', $new_forum_id, 'guests', 'FORUM_LIMITED');
					mass_auth('group_role', $new_forum_id, 'registered', 'FORUM_LIMITED_POLLS');
					mass_auth('group_role', $new_forum_id, 'bots', 'FORUM_BOT');
				break;

				case 'registered':
					mass_auth('group_role', $new_forum_id, 'guests', 'FORUM_READONLY');
					mass_auth('group_role', $new_forum_id, 'bots', 'FORUM_BOT');

				// no break;

				case 'registered_hidden':
					mass_auth('group_role', $new_forum_id, 'registered', 'FORUM_POLLS');
				break;

				case 'private':
				case 'private_hidden':
				case 'moderator':
				case 'moderator_hidden':
				default:
					// The permissions don't match a simple set, so we're going to have to map them directly

					// No post approval for all, in 2.0.x this feature does not exist
					mass_auth('group', $new_forum_id, 'guests', 'f_noapprove', ACL_YES);
					mass_auth('group', $new_forum_id, 'registered', 'f_noapprove', ACL_YES);
				break;
			}
		}
	}
	else if ($mode == 'second')
	{
		// Assign permission roles and other default permissions

		// guests having u_download and u_search ability
		$db->sql_query('INSERT INTO ' . ACL_GROUPS_TABLE . ' (group_id, forum_id, auth_option_id, auth_role_id, auth_setting) SELECT ' . get_group_id('guests') . ', 0, auth_option_id, 0, 1 FROM ' . ACL_OPTIONS_TABLE . " WHERE auth_option IN ('u_', 'u_download', 'u_search')");

		// administrators/global mods having full user features
		mass_auth('group_role', 0, 'administrators', 'USER_FULL');
		mass_auth('group_role', 0, 'global_moderators', 'USER_FULL');

		// By default all converted administrators are given full access
		mass_auth('group_role', 0, 'administrators', 'ADMIN_FULL');

		// All registered users are assigned the standard user role
		mass_auth('group_role', 0, 'registered', 'USER_STANDARD');
		mass_auth('group_role', 0, 'registered_coppa', 'USER_STANDARD');

		// Instead of administrators being global moderators we give the MOD_FULL role to global mods (admins already assigned to this group)
		mass_auth('group_role', 0, 'global_moderators', 'MOD_FULL');
	}
	else if ($mode == 'third')
	{
		// And now the moderators
		// We make sure that they have at least standard access to the forums they moderate in addition to the moderating permissions
		foreach ($user_access as $forum_id => $access_map)
		{
			$forum_id = (int) $forum_id;

			foreach ($access_map as $access)
			{
				mass_auth('user_role', $forum_id, (int) phpbb_user_id($access['userid']), 'MOD_STANDARD');
				mass_auth('user_role', $forum_id, (int) phpbb_user_id($access['userid']), 'FORUM_STANDARD');
			}
		}

		foreach ($group_access as $forum_id => $access_map)
		{
			$forum_id = (int) $forum_id;

			foreach ($access_map as $access)
			{
				if (isset($access['forumpermissions']) && ($access['forumpermissions'] == 131072))
				{
					mass_auth('group_role', $forum_id, (int) $access['usergroupid'], 'MOD_STANDARD');
					mass_auth('group_role', $forum_id, (int) $access['usergroupid'], 'FORUM_STANDARD');
				}
			}
		}

		// We grant everyone readonly access to the categories to ensure that the forums are visible
		$sql = 'SELECT forum_id, forum_name, parent_id, left_id, right_id
			FROM ' . FORUMS_TABLE . '
			ORDER BY left_id ASC';
		$result = $db->sql_query($sql);

		$parent_forums = $forums = array();
		while ($row = $db->sql_fetchrow($result))
		{
			if ($row['parent_id'] == 0)
			{
				mass_auth('group_role', $row['forum_id'], 'administrators', 'FORUM_FULL');
				mass_auth('group_role', $row['forum_id'], 'global_moderators', 'FORUM_FULL');
				$parent_forums[] = $row;
			}
			else
			{
				$forums[] = $row;
			}
		}
		$db->sql_freeresult($result);

		global $auth;

		// Let us see which groups have access to these forums...
		foreach ($parent_forums as $row)
		{
			// Get the children
			$branch = $forum_ids = array();

			foreach ($forums as $key => $_row)
			{
				if ($_row['left_id'] > $row['left_id'] && $_row['left_id'] < $row['right_id'])
				{
					$branch[] = $_row;
					$forum_ids[] = $_row['forum_id'];
					continue;
				}
			}

			if (sizeof($forum_ids))
			{
				// Now make sure the user is able to read these forums
				$hold_ary = $auth->acl_group_raw_data(false, 'f_list', $forum_ids);

				if (empty($hold_ary))
				{
					continue;
				}

				foreach ($hold_ary as $g_id => $f_id_ary)
				{
					$set_group = false;

					foreach ($f_id_ary as $f_id => $auth_ary)
					{
						foreach ($auth_ary as $auth_option => $setting)
						{
							if ($setting == ACL_YES)
							{
								$set_group = true;
								break 2;
							}
						}
					}

					if ($set_group)
					{
						mass_auth('group', $row['forum_id'], $g_id, 'f_list', ACL_YES);
					}
				}
			}
		}
	}
}

/**
* Obtain list of forums in which different attachment categories can be used
*/
function phpbb_attachment_forum_perms($forum_permissions)
{
	if (empty($forum_permissions))
	{
		return '';
	}

	// Decode forum permissions
	$forum_ids = array();

	$one_char_encoding = '#';
	$two_char_encoding = '.';

	$auth_len = 1;
	for ($pos = 0; $pos < strlen($forum_permissions); $pos += $auth_len)
	{
		$forum_auth = substr($forum_permissions, $pos, 1);
		if ($forum_auth == $one_char_encoding)
		{
			$auth_len = 1;
			continue;
		}
		else if ($forum_auth == $two_char_encoding)
		{
			$auth_len = 2;
			$pos--;
			continue;
		}
		
		$forum_auth = substr($forum_permissions, $pos, $auth_len);
		$forum_id = base64_unpack($forum_auth);

		$forum_ids[] = (int) $forum_id;
	}
	
	if (sizeof($forum_ids))
	{
		return attachment_forum_perms($forum_ids);
	}

	return '';
}

/**
* Convert list of disallowed usernames
*/
function vb_convert_disallowed_usernames()
{
	global $convert, $db;

	$disallowed = trim(get_config_value('illegalusernames'));
	$disallowed = explode(' ', $disallowed);
	$insert_ary = array();

	foreach ($disallowed as $username)
	{
		$username = utf8_htmlspecialchars(vb_set_encoding($username));
		$username = '%' . $username . '%';
		$insert_ary[] = array('disallow_username' => $username);
	}

	$db->sql_query($convert->truncate_statement . DISALLOW_TABLE);

	if (sizeof($insert_ary))
	{
		$db->sql_multi_insert(DISALLOW_TABLE, $insert_ary);
	}
}

/**
* Checks whether there are any usernames on the old board that would map to the same
* username_clean on phpBB3. Prints out a list if any exist and exits.
*/
function phpbb_check_username_collisions()
{
	global $db, $src_db, $convert, $table_prefix, $user, $lang;

	vb_trace(sprintf('[%s] phpbb_check_username_collisions ENTER', date('H:i:s')));

	$map_dbms = '';
	switch ($db->sql_layer)
	{
		case 'mysql':
			$map_dbms = 'mysql_40';
		break;
	
		case 'mysql4':
			if (version_compare($db->sql_server_info(true), '4.1.3', '>='))
			{
				$map_dbms = 'mysql_41';
			}
			else
			{
				$map_dbms = 'mysql_40';
			}
		break;
	
		case 'mysqli':
			$map_dbms = 'mysql_41';
		break;
	
		case 'mssql':
		case 'mssql_odbc':
			$map_dbms = 'mssql';
		break;
	
		default:
			$map_dbms = $db->sql_layer;
		break;
	}

	// create a temporary table in which we store the clean usernames
	$drop_sql = 'DROP TABLE ' . $table_prefix . 'userconv';
	switch ($map_dbms)
	{
		case 'firebird':
			$create_sql = 'CREATE TABLE ' . $table_prefix . 'userconv (
				user_id INTEGER NOT NULL,
				username_clean VARCHAR(255) CHARACTER SET UTF8 DEFAULT \'\' NOT NULL COLLATE UNICODE
			)';
		break;

		case 'mssql':
			$create_sql = 'CREATE TABLE [' . $table_prefix . 'userconv] (
				[user_id] [int] NOT NULL ,
				[username_clean] [varchar] (255) DEFAULT (\'\') NOT NULL
			)';
		break;

		case 'mysql_40':
			$create_sql = 'CREATE TABLE ' . $table_prefix . 'userconv (
				user_id mediumint(8) NOT NULL,
				username_clean blob NOT NULL
			)';
		break;

		case 'mysql_41':
			$create_sql = 'CREATE TABLE ' . $table_prefix . 'userconv (
				user_id mediumint(8) NOT NULL,
				username_clean varchar(255) DEFAULT \'\' NOT NULL
			) CHARACTER SET `utf8` COLLATE `utf8_bin`';
		break;

		case 'oracle':
			$create_sql = 'CREATE TABLE ' . $table_prefix . 'userconv
				user_id number(8) NOT NULL,
				username_clean varchar2(255) DEFAULT \'\'
			)';
		break;

		case 'postgres':
			$create_sql = 'CREATE TABLE ' . $table_prefix . 'userconv (
				user_id INT4 DEFAULT \'0\',
				username_clean varchar_ci DEFAULT \'\' NOT NULL
			)';
		break;

		case 'sqlite':
			$create_sql = 'CREATE TABLE ' . $table_prefix . 'userconv (
				user_id INTEGER NOT NULL DEFAULT \'0\',
				username_clean varchar(255) NOT NULL DEFAULT \'\',
			)';
		break;
	}

	$db->sql_return_on_error(true);
	$db->sql_query($drop_sql);
	$db->sql_query($create_sql);
	$db->sql_return_on_error(false);

	// now select all user_ids and usernames and then convert the username (this can take quite a while!)
	$sql = 'SELECT userid, username
		FROM ' . $convert->src_table_prefix . 'user';
	$result = $src_db->sql_query($sql);

	$insert_ary = array();
	$i = 0;
	while ($row = $src_db->sql_fetchrow($result))
	{
		$clean_name = utf8_clean_string(vb_set_encoding($row['username']));
		$insert_ary[] = array('user_id' => $row['userid'], 'username_clean' => $clean_name);

		if ($i % 1000 == 999)
		{
			$db->sql_multi_insert($table_prefix . 'userconv', $insert_ary);
			$insert_ary = array();
		}
		$i++;
	}
	$src_db->sql_freeresult($result);

	if (sizeof($insert_ary))
	{
		$db->sql_multi_insert($table_prefix . 'userconv', $insert_ary);
	}
	unset($insert_ary);

	// now find the clean version of the usernames that collide
	$sql = 'SELECT username_clean
		FROM ' . $table_prefix . 'userconv
		GROUP BY username_clean
		HAVING COUNT(user_id) > 1';
	$result = $db->sql_query($sql);

	$colliding_names = array();
	while ($row = $db->sql_fetchrow($result))
	{
		$colliding_names[] = $row['username_clean'];
	}
	$db->sql_freeresult($result);

	// there was at least one collision, the admin will have to solve it before conversion can continue
	if (sizeof($colliding_names))
	{
		$sql = 'SELECT user_id, username_clean
			FROM ' . $table_prefix . 'userconv
			WHERE ' . $db->sql_in_set('username_clean', $colliding_names);
		$result = $db->sql_query($sql);
		unset($colliding_names);

		$colliding_user_ids = array();
		while ($row = $db->sql_fetchrow($result))
		{
			$colliding_user_ids[(int) $row['user_id']] = $row['username_clean'];
		}
		$db->sql_freeresult($result);

		$sql = 'SELECT username, userid, posts
			FROM ' . $convert->src_table_prefix . 'user
			WHERE ' . $src_db->sql_in_set('userid', array_keys($colliding_user_ids));
		$result = $src_db->sql_query($sql);

		$colliding_users = array();
		while ($row = $db->sql_fetchrow($result))
		{
			$row['userid'] = (int) $row['userid'];
			if (isset($colliding_user_ids[$row['userid']]))
			{
				$colliding_users[$colliding_user_ids[$row['userid']]][] = $row;
			}
		}
		$db->sql_freeresult($result);
		unset($colliding_user_ids);

		$list = '';
		foreach ($colliding_users as $username_clean => $users)
		{
			$list .= sprintf($user->lang['COLLIDING_CLEAN_USERNAME'], $username_clean) . "<br />\n";
			foreach ($users as $i => $row)
			{
				$list .= sprintf($user->lang['COLLIDING_USER'], $row['userid'], vb_set_encoding($row['username']), $row['posts']) . "<br />\n";
			}
		}

		$lang['INST_ERR_FATAL'] = $user->lang['CONV_ERR_FATAL'];
		$convert->p_master->error('<span style="color:red">' . $user->lang['COLLIDING_USERNAMES_FOUND'] . '</span></b><br /><br />' . $list . '<b>', __LINE__, __FILE__);
	}

	$db->sql_query($drop_sql);

	vb_trace(sprintf('[%s] phpbb_check_username_collisions EXIT (no collisions)', date('H:i:s')));
}

/**
* Add the converter-required helper columns to the users table.
*
* Two columns are needed by this converter:
*
*   user_passwd_salt    - holds the vBulletin password salt so the
*                         vb4 auth provider can verify imported passwords
*                         using vB's md5(md5(plain) . salt) formula.
*
*   user_pass_convert   - flag (1) on imported users; the vb4 auth provider
*                         consults it on every login.  When the user logs
*                         in successfully the provider re-hashes their
*                         password to phpBB native format and clears the
*                         flag, so post-migration logins go through the
*                         stock fast path with zero overhead.
*
* Both columns are dropped from a fresh phpBB 3.3.x install:
*   - user_passwd_salt was a vB-specific helper that never shipped with phpBB
*   - user_pass_convert was a phpBB 2 -> 3 migration artifact, dropped from
*     the 3.3.x schema_data
*
* Without these columns the user-conversion step silently fails (INSERT
* references columns that don't exist), which manifests as "users not
* converted" with no obvious error.  This function is idempotent: running
* it twice is a no-op.
*/
function vb_add_user_salt_field()
{
	global $db, $phpbb_root_path, $phpEx;

	vb_trace(sprintf('[%s] vb_add_user_salt_field ENTER', date('H:i:s')));

	// Resolve the right db_tools instance for the running phpBB version.
	// phpBB 3.1+ uses namespaced \phpbb\db\tools\tools via a factory;
	// phpBB 3.0 used a flat phpbb_db_tools class.  Try newest first.
	if (class_exists('\\phpbb\\db\\tools\\factory'))
	{
		$factory = new \phpbb\db\tools\factory();
		$db_tools = $factory->get($db);
	}
	else if (class_exists('\\phpbb\\db\\tools\\tools'))
	{
		$db_tools = new \phpbb\db\tools\tools($db);
	}
	else if (class_exists('phpbb_db_tools'))
	{
		// Legacy phpBB 3.0.x fallback.
		$db_tools = new phpbb_db_tools($db);
	}
	else
	{
		// Last resort - try loading the legacy file (only on very old installs).
		if (file_exists($phpbb_root_path . 'includes/db/db_tools.' . $phpEx))
		{
			include($phpbb_root_path . 'includes/db/db_tools.' . $phpEx);
			$db_tools = new phpbb_db_tools($db);
		}
		else
		{
			trigger_error('Unable to locate phpBB database tools class', E_USER_ERROR);
		}
	}

	// Schema-changes API: add only the columns that don't already exist.
	// sql_column_exists() is the canonical way to check for a column on
	// 3.1+; on 3.0 we fall back to information_schema directly.
	$columns_to_add = array(
		'user_passwd_salt'	=> array('VCHAR:30', ''),
		'user_pass_convert'	=> array('BOOL',     0),
	);

	$add = array();
	foreach ($columns_to_add as $col => $spec)
	{
		$exists = false;
		if (method_exists($db_tools, 'sql_column_exists'))
		{
			$exists = (bool) $db_tools->sql_column_exists(USERS_TABLE, $col);
		}
		else
		{
			// Manual fallback for very old phpBB.
			$sql = "SELECT COUNT(*) AS c FROM information_schema.columns
				WHERE table_schema = DATABASE()
				  AND table_name   = '" . $db->sql_escape(USERS_TABLE) . "'
				  AND column_name  = '" . $db->sql_escape($col) . "'";
			$result = $db->sql_query($sql);
			$exists = ((int) $db->sql_fetchfield('c') > 0);
			$db->sql_freeresult($result);
		}

		if (!$exists)
		{
			$add[$col] = $spec;
		}
	}

	if (!empty($add))
	{
		vb_trace(sprintf('[%s] vb_add_user_salt_field adding columns: %s',
			date('H:i:s'), implode(', ', array_keys($add))
		));
		$db_tools->perform_schema_changes(array(
			'add_columns' => array(USERS_TABLE => $add),
		));
	}
	else
	{
		vb_trace(sprintf('[%s] vb_add_user_salt_field nothing to add (both columns exist)', date('H:i:s')));
	}

	vb_trace(sprintf('[%s] vb_add_user_salt_field EXIT', date('H:i:s')));
}

/**
* Print a "what the converter actually produced" summary into the
* conversion progress log.
*
* Wired into the execute_last hook so it runs once at the very end of
* conversion, after every data-table chunk and every sync function has
* finished.  The user gets an itemised view of what landed in the
* destination DB -- useful both as confirmation that the conversion
* succeeded and as a diff against the source-board inventory printed at
* the start by vb_log_source_inventory().
*
* If the destination counts are dramatically lower than the source counts
* (e.g. 0 users imported when the source had thousands), that's an
* immediate visible signal that something silently dropped data, rather
* than waiting until users complain that posts are missing.
*/
function vb_log_target_counts()
{
	global $db, $template;

	if (!isset($template))
	{
		return;
	}

	$template->assign_block_vars('checks', array(
		'S_LEGEND'	=> true,
		'LEGEND'	=> 'Destination board (phpBB 3.3) -- post-conversion counts',
	));

	// Each entry: array(label, table, optional_where).
	// Tables referenced by phpBB constants because their prefix is
	// already baked in by phpBB itself.
	$items = array(
		array('Users (incl. anonymous + bot)',     USERS_TABLE,         ''),
		array('  imported (user_id > 2)',          USERS_TABLE,         'user_id > 2'),
		array('  pending vB password migration',   USERS_TABLE,         "user_id > 2 AND user_pass_convert = 1"),
		array('User-group memberships',            USER_GROUP_TABLE,    ''),
		array('Groups',                            GROUPS_TABLE,        ''),
		array('Forums',                            FORUMS_TABLE,        ''),
		array('Topics',                            TOPICS_TABLE,        ''),
		array('  approved + visible',              TOPICS_TABLE,        'topic_visibility = 1'),
		array('Posts',                             POSTS_TABLE,         ''),
		array('  approved + visible',              POSTS_TABLE,         'post_visibility = 1'),
		array('Attachments',                       ATTACHMENTS_TABLE,   ''),
		array('Private messages',                  PRIVMSGS_TABLE,      ''),
		array('Polls',                             POLL_OPTIONS_TABLE,  ''),
		array('Smilies',                           SMILIES_TABLE,       ''),
		array('Banned',                            BANLIST_TABLE,       ''),
	);

	foreach ($items as $row)
	{
		list($label, $table, $where) = $row;

		$sql = 'SELECT COUNT(*) AS c FROM ' . $table
			. (($where !== '') ? ' WHERE ' . $where : '');

		$db->sql_return_on_error(true);
		$result = @$db->sql_query($sql);
		$count = ($result) ? (int) $db->sql_fetchfield('c') : -1;
		if ($result)
		{
			$db->sql_freeresult($result);
		}
		$db->sql_return_on_error(false);

		$template->assign_block_vars('checks', array(
			'TITLE'		=> $label,
			'RESULT'	=> ($count >= 0) ? number_format($count) : 'n/a',
		));
	}
}

function add_bbcodes()
{
	global $db, $cache, $convert, $user, $phpbb_root_path, $phpEx;

	// In phpBB 3.1+ BBCodes are parsed by s9e/text-formatter and the legacy
	// first_pass_match / second_pass_match fields are auto-derived from
	// bbcode_match + bbcode_tpl using acp_bbcodes::build_regexp().  The
	// pre-baked /e-modifier regexes that shipped with the original 3.0
	// converter are not valid under PHP 7+.  We let phpBB build the regexes
	// for us instead.
	if (!class_exists('acp_bbcodes'))
	{
		include($phpbb_root_path . 'includes/acp/acp_bbcodes.' . $phpEx);
	}
	$acp_bbcodes = new acp_bbcodes();

	$add_bbcode_ary = array(
		'font=' => array(
			'bbcode_tag'			=> 'font=',
			'bbcode_match'			=> '[font={SIMPLETEXT}]{TEXT}[/font]',
			'bbcode_tpl'			=> '<span style="font-family: {SIMPLETEXT};">{TEXT}</span>',
			'display_on_posting'	=> 1,
			'bbcode_helpline'		=> '[font=Georgia]Georgia font[/font]',
		),
		'align=' => array(
			'bbcode_tag'			=> 'align=',
			'bbcode_match'			=> '[align={TEXT1}]{TEXT2}[/align]',
			'bbcode_tpl'			=> '<div style="text-align: {TEXT1};">{TEXT2}</div>',
			'display_on_posting'	=> 1,
			'bbcode_helpline'		=> 'Alignment: can use center, left, right',
		),
	);

	foreach ($add_bbcode_ary as $bbcode_data)
	{
		// Skip if a BBCode with this tag already exists.
		$sql = 'SELECT 1 AS test
			FROM ' . BBCODES_TABLE . "
			WHERE LOWER(bbcode_tag) = '" . $db->sql_escape(strtolower($bbcode_data['bbcode_tag'])) . "'";
		$result = $db->sql_query($sql);
		$info = $db->sql_fetchrow($result);
		$db->sql_freeresult($result);

		if (!empty($info) && (int) $info['test'] === 1)
		{
			continue;
		}

		// Allocate a new bbcode_id above NUM_CORE_BBCODES.
		$sql = 'SELECT MAX(bbcode_id) AS max_bbcode_id
			FROM ' . BBCODES_TABLE;
		$result = $db->sql_query($sql);
		$row = $db->sql_fetchrow($result);
		$db->sql_freeresult($result);

		$bbcode_id = ($row && !empty($row['max_bbcode_id'])) ? ((int) $row['max_bbcode_id'] + 1) : (NUM_CORE_BBCODES + 1);

		if ($bbcode_id <= NUM_CORE_BBCODES)
		{
			$bbcode_id = NUM_CORE_BBCODES + 1;
		}

		// 1511 was the historical maximum bbcode_id allowed.
		if ($bbcode_id >= 1512)
		{
			continue;
		}

		// Build the regex / replacement strings using phpBB's own builder so
		// the result is valid for the current phpBB version (no /e modifier).
		$built = $acp_bbcodes->build_regexp($bbcode_data['bbcode_match'], $bbcode_data['bbcode_tpl']);

		$sql_ary = array(
			'bbcode_id'				=> (int) $bbcode_id,
			'bbcode_tag'			=> $bbcode_data['bbcode_tag'],
			'bbcode_match'			=> $bbcode_data['bbcode_match'],
			'bbcode_tpl'			=> $bbcode_data['bbcode_tpl'],
			'display_on_posting'	=> (int) $bbcode_data['display_on_posting'],
			'bbcode_helpline'		=> $bbcode_data['bbcode_helpline'],
			'first_pass_match'		=> $built['first_pass_match'],
			'first_pass_replace'	=> $built['first_pass_replace'],
			'second_pass_match'		=> $built['second_pass_match'],
			'second_pass_replace'	=> $built['second_pass_replace'],
		);

		$db->sql_query('INSERT INTO ' . BBCODES_TABLE . ' ' . $db->sql_build_array('INSERT', $sql_ary));
		$cache->destroy('sql', BBCODES_TABLE);
	}
}
?>
