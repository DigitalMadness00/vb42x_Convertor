<?php
/** 
*
* @package install
* @version $Id: convert_vb30.php,v 1.34 2007/05/14 21:29:27 kellanved Exp $
* @copyright (c) 2006 phpBB Group 
* @license http://opensource.org/licenses/gpl-license.php GNU Public License 
*
* Revised by Dicky 2008/05/17
* Revised by Dicky 2009/07/17
* Updated to 4.2.x by prototech Feb. 2013
* Updated for PHP 7+ / phpBB 3.3.x compatibility 2024
*/

/**
* NOTE to potential convertor authors. Please use this file to get
* familiar with the structure since we added some bare explanations here.
*
* Since this file gets included more than once on one page you are not able to add functions to it.
* Instead use a functions_ file.
*
* @ignore
*/
if (!defined('IN_PHPBB'))
{
	exit;
}

include($phpbb_root_path . 'config.' . $phpEx);
unset($dbpasswd);

/**
* $convertor_data provides some basic information about this convertor which is
* used on the initial list of convertors and to populate the default settings
*/
$convertor_data = array(
	'forum_name'	=> 'vBulletin 4.x.x',
	'version'		=> '0.0.12',
	'phpbb_version'	=> '3.3.16',
	'author'		=> 'Modernised for phpBB 3.3 / PHP 8 by <a href="https://github.com/DigitalMadness00/vb42x_Convertor">DigitalMadness00</a> &bull; Original by <a href="https://www.phpbb.com/community/memberlist.php?mode=viewprofile&u=163542">Dicky</a> and <a href="https://www.phpbb.com/community/memberlist.php?mode=viewprofile&u=304651">prototech</a> &bull; Credits to <a href="http://wlx.westgis.ac.cn/">wlx</a>',
	'dbms'			=> $dbms,
	'dbhost'		=> $dbhost,
	'dbport'		=> $dbport,
	'dbuser'		=> $dbuser,
	'dbpasswd'		=> '',
	'dbname'		=> $dbname,
	'table_prefix'	=> 'vb_',
	'forum_path'	=> '../forums',
	'author_notes'	=> 'After conversion: clear cookies before logging in (CSRF), then run ACP &rarr; General &rarr; Resync statistics, and ACP &rarr; Maintenance &rarr; Search index &rarr; Create index. See README.md and CHANGES.md in the GitHub repo for the full post-conversion checklist.',
);

/**
 * ============================================================================
 *  BATCH-SIZE TUNING
 * ============================================================================
 *
 *  The phpBB convertor framework reads two knobs off the global $convert
 *  object that control how much work gets done per HTTP request before the
 *  page meta-refreshes to the next chunk:
 *
 *    $convert->batch_size      -- rows processed per data-table chunk
 *                                 (e.g. how many vB posts get inserted
 *                                 into phpbb_posts before an auto-refresh)
 *
 *    $convert->num_wait_rows   -- rows processed per "wait" cycle, which
 *                                 controls how often progress is flushed
 *                                 to the browser
 *
 *  Framework defaults are batch_size=6000, num_wait_rows=60.  Those are
 *  reasonable for a small board on a low-spec server, but they're often
 *  the wrong size for a real conversion: too small and you spend the
 *  entire run round-tripping HTTP refreshes; too large and you run out
 *  of memory or hit max_execution_time partway through a chunk and have
 *  to reset and start over.
 *
 *  PROFILES BELOW.  Uncomment EXACTLY ONE block.  The default is "BALANCED"
 *  which is what the phpBB community generally recommends as the starting
 *  point for any board larger than a few thousand posts.
 *
 *  ----- CONSERVATIVE -------------------------------------------------------
 *  Use this if you've been hitting timeouts, memory errors, or the convertor
 *  is failing partway through a chunk.  Slower overall, but very steady.
 *
 *  // $convert->batch_size    = 2000;
 *  // $convert->num_wait_rows = 60;
 *
 *  ----- BALANCED (default, recommended starting point) ---------------------
 *  Modest bump over framework defaults.  Good for medium boards
 *  (~50k-500k posts) on a server with at least 512 MB PHP memory_limit
 *  and a non-trivial max_execution_time.
 *
 *      batch_size    = 8000
 *      num_wait_rows = 100
 *
 *  ----- AGGRESSIVE ---------------------------------------------------------
 *  For large boards (>500k posts) on a beefy server (>=1 GB PHP memory,
 *  no execution time limit, fast disk).  Cuts conversion time considerably
 *  but ANY mid-chunk failure means you have more rows to clean up before
 *  retrying.  Take a database snapshot first so you can roll back.
 *
 *      batch_size    = 20000
 *      num_wait_rows = 600
 *
 *  HOW TO PICK A PROFILE: edit the two lines further down inside the
 *  `if (!$get_info)` block (search for "BATCH SIZE PROFILE" below).
 *
 * ============================================================================
 *  RELATED PHP / MariaDB TUNING (NOT controlled from this file)
 * ============================================================================
 *
 *  Batch size only matters within whatever ceiling PHP and the database
 *  enforce.  For non-trivial boards you almost certainly need to also
 *  raise these on the host running phpBB during the conversion:
 *
 *  In php.ini (or a drop-in like /usr/local/etc/php/conf.d/zz-converter.ini):
 *      memory_limit       = 1024M
 *      max_execution_time = 0          ; 0 = unlimited
 *      post_max_size      = 64M
 *      upload_max_filesize = 64M
 *
 *  In MariaDB (often /etc/mysql/mariadb.conf.d/50-server.cnf), under [mysqld]:
 *      max_allowed_packet       = 256M
 *      wait_timeout             = 28800
 *      innodb_lock_wait_timeout = 600
 *
 *  You can revert all of the above to their previous values once the
 *  conversion has completed successfully.
 *
 * ============================================================================
 *  RECOMMENDED WORKFLOW
 * ============================================================================
 *
 *    1. Pick a profile above.  Start with BALANCED unless you have reason
 *       to believe otherwise.
 *    2. Raise PHP and MariaDB limits as documented above.
 *    3. Take a phpMyAdmin Export of the clean (truncated + re-seeded) phpBB
 *       database -- your "checkpoint".
 *    4. Run the conversion via install/app.php/convert .
 *    5. If it succeeds, you're done.
 *       If it fails, restore the checkpoint, drop down to a slower profile
 *       (CONSERVATIVE), and retry.  Don't try to "resume" -- the convertor
 *       has no rollback and partial state will produce duplicate-key errors.
 */

/**
* $tables is a list of the tables (minus prefix) which we expect to find in the
* source forum. It is used to guess the prefix if the specified prefix is incorrect
*/
// Almost 200 tables :-/
$tables = array(
	'access',
	'action',
	'activitystream',
	'activitystreamtype',
	'ad',
	'adcriteria',
	'adminhelp',
	'administrator',
	'adminlog',
	'adminmessage',
	'adminutil',
	'album',
	'albumupdate',
	'announcement',
	'announcementread',
	'apiclient',
	'apilog',
	'apipost',
	'attachment',
	'attachmentcategory',
	'attachmentcategoryuser',
	'attachmentpermission',
	'attachmenttype',
	'attachmentviews',
	'autosave',
	'avatar',
	'bbcode',
	'bbcode_video',
	'block',
	'blockconfig',
	'blocktype',
	'bookmarksite',
	'cache',
	'cacheevent',
	'calendar',
	'calendarcustomfield',
	'calendarmoderator',
	'calendarpermission',
	'contentpriority',
	'contentread',
	'contenttype',
	'cpsession',
	'cron',
	'cronlog',
	'customavatar',
	'customprofile',
	'customprofilepic',
	'datastore',
	'dbquery',
	'deletionlog',
	'discussion',
	'discussionread',
	'editlog',
	'event',
	'externalcache',
	'faq',
	'filedata',
	'forum',
	'forumpermission',
	'forumprefixset',
	'forumread',
	'forumrunner_push_data',
	'forumrunner_push_users',
	'groupmessage',
	'groupmessage_hash',
	'groupread',
	'holiday',
	'humanverify',
	'hvanswer',
	'hvquestion',
	'icon',
	'imagecategory',
	'imagecategorypermission',
	'indexqueue',
	'infraction',
	'infractionban',
	'infractiongroup',
	'infractionlevel',
	'ipdata',
	'language',
	'mailqueue',
	'moderation',
	'moderator',
	'moderatorlog',
	'navigation',
	'notice',
	'noticecriteria',
	'noticedismissed',
	'package',
	'passwordhistory',
	'paymentapi',
	'paymentinfo',
	'paymenttransaction',
	'phrase',
	'phrasetype',
	'picturecomment',
	'picturecomment_hash',
	'picturelegacy',
	'plugin',
	'pm',
	'pmreceipt',
	'pmtext',
	'pmthrottle',
	'podcast',
	'podcastitem',
	'poll',
	'pollvote',
	'post',
	'postedithistory',
	'posthash',
	'postlog',
	'postparsed',
	'postrelease',
	'prefix',
	'prefixpermission',
	'prefixset',
	'product',
	'productcode',
	'productdependency',
	'profileblockprivacy',
	'profilefield',
	'profilefieldcategory',
	'profilevisitor',
	'ranks',
	'reminder',
	'reputation',
	'reputationlevel',
	'route',
	'rssfeed',
	'rsslog',
	'searchcore',
	'searchcore_text',
	'searchgroup',
	'searchgroup_text',
	'searchlog',
	'session',
	'setting',
	'settinggroup',
	'sigparsed',
	'sigpic',
	'skimlinks',
	'smilie',
	'socialgroup',
	'socialgroupcategory',
	'socialgroupicon',
	'socialgroupmember',
	'spamlog',
	'stats',
	'strikes',
	'style',
	'stylevar',
	'stylevardfn',
	'subscribediscussion',
	'subscribeevent',
	'subscribeforum',
	'subscribegroup',
	'subscribethread',
	'subscription',
	'subscriptionlog',
	'subscriptionpermission',
	'tachyforumcounter',
	'tachyforumpost',
	'tachythreadcounter',
	'tachythreadpost',
	'tag',
	'tagcontent',
	'tagsearch',
	'template',
	'templatehistory',
	'templatemerge',
	'thread',
	'threadrate',
	'threadread',
	'threadredirect',
	'threadviews',
	'upgradelog',
	'user',
	'useractivation',
	'userban',
	'userchangelog',
	'usercss',
	'usercsscache',
	'userfield',
	'usergroup',
	'usergroupleader',
	'usergrouprequest',
	'userlist',
	'usernote',
	'userpromotion',
	'usertextfield',
	'usertitle',
	'visitormessage',
	'visitormessage_hash',
);

/**
* $config_schema details how the board configuration information is stored in the source forum.
*
* 'table_format' can take the value 'file' to indicate a config file. In this case array_name
* is set to indicate the name of the array the config values are stored in
* 'table_format' can be an array if the values are stored in a table which is an assosciative array
* (as per phpBB 2.0.x)
* If left empty, values are assumed to be stored in a table where each config setting is
* a column (as per phpBB 1.x)
*
* In either of the latter cases 'table_name' indicates the name of the table in the database
*
* 'settings' is an array which maps the name of the config directive in the source forum
* to the config directive in phpBB3. It can either be a direct mapping or use a function.
* Please note that the contents of the old config value are passed to the function, therefore
* an in-built function requiring the variable passed by reference is not able to be used. Since
* empty() is such a function we created the function is_empty() to be used instead.
*/
$config_schema = array(
	'table_name'	=>	'setting',
	'table_format'	=>	array('varname' => 'value'),
	'settings'		=>	array(
		'allow_bbcode'			=> 'allowbbcode',
		'allow_emailreuse'		=> 'not(requireuniqueemail)',
		'allow_smilies'			=> 'allowsmilies',
		'allow_sig'				=> 'allowsignatures',
		'allow_avatar_local'	=> 'avatarenabled',
		'allow_avatar_remote'	=> 'avatarenabled',
		'allow_avatar_upload'	=> 'avatarenabled',
		'board_disable'			=> 'not(bbactive)',
		'sitename'				=> 'vb_set_encoding(bbtitle)',
		'site_desc'				=> 'vb_set_encoding(description)',
		'board_contact'			=> 'webmasteremail',
		'board_email'			=> 'webmasteremail',
		'posts_per_page'		=> 'maxposts',
		'topics_per_page'		=> 'maxthreads',
		//unsure?
		'enable_confirm'		=> 'enableemail',
		'enable_pm_icons'		=> 'privallowicons',
		'hot_threshold'			=> 'hotnumberposts',
		'max_poll_options'		=> 'maxpolloptions',
		'max_sig_chars'			=> 'sigmax',
		'max_name_chars'		=> 'maxuserlength',
		'min_name_chars'		=> 'minuserlength',
		'limit_load'	=>	'loadlimit',
		//in vb30, pm_max_msgs is defined in usergroup.
		//'pm_max_msgs'			=> 'max_inbox_privmsgs',
		'smtp_delivery'			=> 'use_smtp',
		'smtp_host'				=> 'smtp_host',
		'smtp_username'			=> 'smtp_user',
		'smtp_password'			=> 'smtp_pass',
		'require_activation'	=> 'verifyemail',
		'flood_interval'		=> 'floodchecktime',
		//'avatar_filesize'		=> 'avatar_filesize',
		//'avatar_max_width'		=> 'avatar_max_width',
		//'avatar_max_height'		=> 'avatar_max_height',
		//use phpbb default?		
		//'default_dateformat'	=> 'default_dateformat',
		'board_timezone'		=> 'timeoffset',
		'allow_privmsg'			=> 'enablepms',
		'gzip_compress'			=> 'gziplevel',
		'coppa_enable'			=> 'usecoppa',
		'coppa_fax'				=> 'faxnumber',
	)
);

/**
* $test_file is the name of a file which is present on the source
* forum which can be used to check that the path specified by the 
* user was correct
*/
$test_file = 'showthread.php';

/**
* If this is set then we are not generating the first page of information but getting the conversion information.
*/
if (!$get_info)
{
	// ------------------------------------------------------------------
	//  BATCH SIZE PROFILE
	//  See the long comment block at the top of this file for an
	//  explanation of these knobs and the available profiles.  The
	//  defaults below correspond to the BALANCED profile, which is
	//  the recommended starting point for most boards.
	//
	//  CONSERVATIVE: 2000 / 60      (slow but very steady)
	//  BALANCED:     8000 / 100     (default - this file)
	//  AGGRESSIVE:   20000 / 600    (only on large hardware)
	// ------------------------------------------------------------------
	$convert->batch_size    = 8000;
	$convert->num_wait_rows = 100;

	// If attachfile == 0, attachments are stored in the database.
	@define('VB_ATTACH_IN_DB', is_empty(get_config_value('attachfile')));
	@define('VB_AVATAR_IN_DB', is_empty(get_config_value('usefileavatar')));
	@define('VB_THUMB_LIMIT', (int) get_config_value('attachthumbssize'));

/**
*	Description on how to use the convertor framework.
*
*	'schema' Syntax Description
*		-> 'target'			=> Target Table. If not specified the next table will be handled
*		-> 'primary'		=> Primary Key. If this is specified then this table is processed in batches
*		-> 'query_first'	=> array('target' or 'src', Query to execute before beginning the process
*								(if more than one then specified as array))
*		-> 'function_first'	=> Function to execute before beginning the process (if more than one then specified as array)
*								(This is mostly useful if variables need to be given to the converting process)
*		-> 'test_file'		=> This is not used at the moment but should be filled with a file from the old installation
*
*		// DB Functions
*		'distinct'	=> Add DISTINCT to the select query
*		'where'		=> Add WHERE to the select query
*		'group_by'	=> Add GROUP BY to the select query
*		'left_join'	=> Add LEFT JOIN to the select query (if more than one joins specified as array)
*		'having'	=> Add HAVING to the select query
*
*		// DB INSERT array
*		This one consist of three parameters
*		First Parameter: 
*							The key need to be filled within the target table
*							If this is empty, the target table gets not assigned the source value
*		Second Parameter:
*							Source value. If the first parameter is specified, it will be assigned this value.
*							If the first parameter is empty, this only gets added to the select query
*		Third Parameter:
*							Custom Function. Function to execute while storing source value into target table. 
*							The functions return value get stored.
*							The function parameter consist of the value of the second parameter.
*
*							types:
*								- empty string == execute nothing
*								- string == function to execute
*								- array == complex execution instructions
*		
*		Complex execution instructions:
*		@todo test complex execution instructions - in theory they will work fine
*
*							By defining an array as the third parameter you are able to define some statements to be executed. The key
*							is defining what to execute, numbers can be appended...
*
*							'function' => execute function
*							'execute' => run code, whereby all occurrences of {VALUE} get replaced by the last returned value.
*										The result *must* be assigned/stored to {RESULT}.
*							'typecast'	=> typecast value
*
*							The returned variables will be made always available to the next function to continue to work with.
*
*							example (variable inputted is an integer of 1):
*
*							array(
*								'function1'		=> 'increment_by_one',		// returned variable is 2
*								'typecast'		=> 'string',				// typecast variable to be a string
*								'execute'		=> '{RESULT} = {VALUE} . ' is good';', // returned variable is '2 is good'
*								'function2'		=> 'replace_good_with_bad',				// returned variable is '2 is bad'
*							),
*
*/

// For attachments, get Table: setting, varname=attachpath, value=
// For avatars, get Table: setting, varname=avatarpath, value=
// Check usefileavatar if avatars are in files or database. - 0=Store in DB, 1=Store as files.
// Check attachfile if attachments are in files or database. - 0=Store in DB, 2=Store files, not sure what 1 equals.
	$convertor = array(
		'test_file'				=> 'showthread.php',

		'avatar_path'			=> get_config_value('avatarurl') . '/',
		'avatar_gallery_path'	=> 'images/avatars/thumbs/',
		'smilies_path'			=> 'images/smilies/',
		'upload_path'			=> get_config_value('attachpath') . '/',
		'thumbnails'			=> '',
		'ranks_path'			=> false,

		// We empty some tables to have clean data available
		'query_first'			=> array(
			array('target', $convert->truncate_statement . SEARCH_RESULTS_TABLE),
			array('target', $convert->truncate_statement . SEARCH_WORDLIST_TABLE),
			array('target', $convert->truncate_statement . SEARCH_WORDMATCH_TABLE),
			array('target', $convert->truncate_statement . LOG_TABLE),
		),


		// phpBB2 allowed some similar usernames to coexist which would have the same
		// username_clean in phpBB3 which is not possible, so we'll give the admin a list
		// of user ids and usernames and let him deicde what he wants to do with them
		'execute_first'	=> '
			vb_log_source_inventory();
			phpbb_check_username_collisions();
			add_bbcodes();
			vb_set_phpbb_config("auth_method", "vb4");
			vb_add_user_salt_field();
			import_avatar_gallery();
			vb_insert_forums();
		',

		'execute_last'	=> array('
			add_bots();
		', '
			vb_convert_disallowed_usernames();
		', '
			update_folder_pm_count();
		', '
			update_unread_count();
		', '
			phpbb_convert_authentication(\'start\');
		', '
			phpbb_convert_authentication(\'first\');
		', '
			phpbb_convert_authentication(\'second\');
		', '
			phpbb_convert_authentication(\'third\');
		', '
			vb_log_target_counts();
		'),

		'schema' => array(
			array(
				'target'		=> ATTACHMENTS_TABLE,
				'primary'		=> 'attachment.attachmentid',
				'query_first'	=>  array('target', $convert->truncate_statement . ATTACHMENTS_TABLE),
				'autoincrement'	=> 'attach_id',

				array('attach_id',			'attachment.attachmentid',			''),
				array('post_msg_id',		'attachment.contentid',				''),
				array('topic_id',			'post.threadid',					''),
				array('in_message',		0,									''),
				array('is_orphan',			0,									''),
				array('poster_id',			'attachment.userid',				'phpbb_user_id'),
				// Even though the attachments may be stored in the filesystem, the database may still contain the file data as well.
				// We need to check the setting to ensure that we aren't wasting resources by grabbing the attachments from the database and then not doing anything with them.
				array('',					((VB_ATTACH_IN_DB) ? 'filedata.filedata' : ''), ''),
				array('',					((VB_ATTACH_IN_DB) ? 'filedata.thumbnail' : ''), ''),
				array('',					'filedata.height',					''),
				array('',					'filedata.width',					''),
				array('physical_filename',	'attachment.userid',				'vb_import_attachment'),
				array('real_filename',		'attachment.filename',				array('function1' => 'vb_set_encoding', 'function2' => 'utf8_htmlspecialchars')),
				array('download_count',	'attachment.counter',				''),
				array('attach_comment',	'',									''),
				array('extension',			'attachment.filename',				'vb_file_ext'),
				array('mimetype',			'attachment.filename',				'mimetype'),
				array('filesize',			'filedata.filesize',				''),
				array('filetime',			'attachment.dateline',				''),
				array('thumbnail',			'',									'vb_attach_has_thumbnail'),

				'left_join'		=> array('attachment LEFT JOIN filedata ON (attachment.filedataid = filedata.filedataid)',
									'attachment LEFT JOIN post ON (attachment.contenttypeid = 1 AND post.postid = attachment.contentid)'),

				// Post attachment: contenttypeid = 1
				'where'			=> 'attachment.contenttypeid = 1',
			),

			array(
				'target'		=> BANLIST_TABLE,
				'query_first'	=> array('target', $convert->truncate_statement . BANLIST_TABLE),

				array('ban_userid',			'userban.userid',			'phpbb_user_id'),
				array('ban_reason',			'',							''),
				array('ban_give_reason',		'',							''),
			),

			array(
				'target'		=> SMILIES_TABLE,
				'query_first'	=> array('target', $convert->truncate_statement . SMILIES_TABLE),
				'autoincrement'	=> 'smiley_id',

				array('smiley_id',				'smilie.smilieid',			''),
				array('code',					'smilie.smilietext',		'vb_set_encoding'),
				array('emotion',				'smilie.title',				'vb_set_encoding'),
				array('smiley_url',			'smilie.smiliepath',		'import_smiley'),
				array('smiley_width',			'smilie.smiliepath',		'get_smiley_width'),
				array('smiley_height',			'smilie.smiliepath',		'get_smiley_height'),
				array('smiley_order',			'smilie.displayorder',		''),
				array('display_on_posting',	'smilie.smilieid',			'get_smiley_display'),

				'order_by'		=> 'smilie.smilieid ASC',
			),

			array(
				'target'		=> ICONS_TABLE,
				'query_first'	=> array('target', $convert->truncate_statement . ICONS_TABLE),

				array('icons_id',				'icon.iconid',				''),
				array('icons_url',				'icon.iconpath',			'vb_import_icon'),
				array('icons_width',			'',							'vb_icon_width'),
				array('icons_height',			'',							'vb_icon_height'),
				array('icons_order',			'icon.displayorder',		''),
				array('display_on_posting',	1,							''),
			),

			array(
				'target'		=> TOPICS_TABLE,
				'query_first'	=> array('target', $convert->truncate_statement . TOPICS_TABLE),
				'primary'		=> 'thread.threadid',
				'autoincrement'	=> 'topic_id',

				array('topic_id',					'thread.threadid',			''),
				array('forum_id',					'thread.forumid',			''),
				array('icon_id',					'thread.iconid',			''),
				array('topic_poster',				'thread.postuserid',		'phpbb_user_id'),
				// phpBB topic_attachment is TINYINT(1) UNSIGNED (a 0/1 flag), but
				// vBulletin stores the count of attachments in thread.attach.
				// Pass-through overflows for any thread with >1 attachment.
				array('topic_attachment',			'thread.attach', 			'not_is_empty'),
				array('topic_title',				'thread.title',				array('function1' => 'vb_utf8_encode', 'function2' => 'vb_set_encoding')), //'utf8_htmlspecialchars'
				array('topic_time',				'thread.dateline',			array('typecast' => 'int')),				
				array('topic_views',				'thread.views',				''),
				// phpBB 3.1+ replaced topic_replies / topic_replies_real with
				// topic_posts_approved / topic_posts_unapproved / topic_posts_softdeleted
				// and added topic_visibility (default 0 = unapproved!).
				array('topic_posts_approved',		'thread.replycount',		''),
				array('topic_posts_unapproved',		0,							''),
				array('topic_posts_softdeleted',	0,							''),
				array('topic_visibility',			ITEM_APPROVED,				''),
				array('topic_last_post_id',		'thread.lastpostid',		''),
				array('topic_status',				'thread.open',				'not'),
				array('topic_moved_id',			0,							''),
				array('topic_type',				'thread.sticky',			''),
				array('topic_first_post_id',		'thread.firstpostid',		''),
				array('topic_first_poster_name',	'thread.postusername',		'vb_set_encoding'),
				array('topic_last_poster_name',	'thread.lastposter',		'vb_set_encoding'),
				array('topic_last_post_time',		'thread.lastpost',			''),

				array('poll_title',				'poll.question',			array('function1' => 'null_to_str', 'function2' => 'vb_set_encoding', 'function3' => 'utf8_htmlspecialchars')),
				array('poll_start',				'poll.dateline',			'null_to_zero'),
				// vB stores poll.timeout in DAYS, with sentinel value 65535 meaning
				// "never expires".  65535 * 86400 = 5,662,224,000 which overflows
				// phpBB's INT(11) UNSIGNED poll_length column (max 4,294,967,295).
				// vb_poll_length() converts and clamps.
				array('poll_length',				'poll.timeout',				'vb_poll_length'),
				array('',							'poll.numberoptions',		''),
				array('poll_max_options',			'poll.multiple',			'vb_poll_options'),
				array('poll_vote_change',			0,							''),

				'left_join'		=> 'thread LEFT JOIN poll ON thread.pollid = poll.pollid',
				// Exclude redirects for moved topics
				'where'			=> 'thread.open <> 10',
			),

			// Topic redirects for moved topics
			array(
				'target'		=> TOPICS_TABLE,
				'primary'		=> 'thread.threadid',
				'autoincrement'	=> 'topic_id',

				array('topic_id',					'thread.threadid',			''),
				array('forum_id',					'thread.forumid',			''),
				array('icon_id',					'thread.iconid',			''),
				array('topic_poster',				'thread.postuserid',		'phpbb_user_id'),
				// Same boolean-flag conversion as the regular-topics block above.
				array('topic_attachment',			'thread.attach', 			'not_is_empty'),
				array('topic_title',				'thread.title',				array('function1' => 'vb_utf8_encode', 'function2' => 'vb_set_encoding')), //'utf8_htmlspecialchars'
				array('topic_time',				'thread.dateline',			array('typecast' => 'int')),				
				array('topic_views',				'thread.views',				''),
				// phpBB 3.1+ schema (see notes on regular topics above).
				array('topic_posts_approved',		'thread.replycount',		''),
				array('topic_posts_unapproved',		0,							''),
				array('topic_posts_softdeleted',	0,							''),
				array('topic_visibility',			ITEM_APPROVED,				''),
				array('topic_last_post_id',		'thread.lastpostid',		''),
				array('topic_status',				ITEM_MOVED,					''),
				array('topic_moved_id',			'thread.pollid',			''),
				array('topic_type',				'thread.sticky',			''),
				array('topic_first_post_id',		'thread.firstpostid',		''),
				array('topic_first_poster_name',	'thread.postusername',		'vb_set_encoding'),
				array('topic_last_poster_name',	'thread.lastposter',		'vb_set_encoding'),
				array('topic_last_post_time',		'thread.lastpost',			''),

				'where'			=> 'thread.open = 10',
			),

			array(
				'target'		=> POLL_OPTIONS_TABLE,
				'primary'		=> 'poll.pollid',
				'query_first'	=> array('target', $convert->truncate_statement . POLL_OPTIONS_TABLE),

				array('',						'thread.threadid',			''),
				array('',						'poll.votes',				''),
				array('',						'poll.options',				'vb_insert_poll_options'),

				'left_join'		=> 'poll LEFT JOIN thread ON (poll.pollid = thread.pollid)',
			),

			array(
				'target'		=> POLL_VOTES_TABLE,
				'primary'		=> 'pollvote.pollvoteid',
				'query_first'	=> array('target', $convert->truncate_statement . POLL_VOTES_TABLE),

				array('poll_option_id',		'pollvote.voteoption',		''),
				array('topic_id',				'thread.threadid',			''),
				array('vote_user_id',			'pollvote.userid',			'phpbb_user_id'),
				array('vote_user_ip',			'',							''),

				'left_join'		=> 'pollvote LEFT JOIN thread ON pollvote.pollid = thread.pollid',
			),

			array(
				'target'		=> TOPICS_WATCH_TABLE,
				'primary'		=> 'subscribethread.subscribethreadid',
				'query_first'	=> array('target', $convert->truncate_statement . TOPICS_WATCH_TABLE),

				array('topic_id',				'subscribethread.threadid',		''),
				array('user_id',				'subscribethread.userid',		'phpbb_user_id'),
				array('notify_status',			'subscribethread.emailupdate',	''),//?
			),

			array(
				'target'		=> POSTS_TABLE,
				'primary'		=> 'post.postid',
				'autoincrement'	=> 'postid',
				'query_first'	=> array('target', $convert->truncate_statement . POSTS_TABLE),
				'execute_first'	=> '
					$config["max_post_chars"] = -1;
					$config["max_quote_depth"] = 0;
				',

				array('post_id',				'post.postid',					''),
				array('topic_id',				'post.threadid',				''),
				array('forum_id',				'thread.forumid',				''),
				array('poster_id',				'post.userid as poster_id',		'phpbb_user_id'),
				array('icon_id',				'post.iconid',					''),
				array('poster_ip',				'post.ipaddress',				''),
				array('post_time',				'post.dateline',				''),
				array('enable_bbcode',			1,								''),
				array('enable_smilies',		'post.allowsmilie',				''),
				array('enable_sig',			'post.showsignature',			''),
				array('enable_magic_url',		1,								''),
				array('post_username',			'post.username',				'vb_set_encoding'),
				array('post_subject',			'post.title',					'vb_set_encoding'),
				array('post_attachment',		'post.attach',					'not_is_empty'),
				// phpBB 3.1+ uses post_visibility instead of post_approved; default is 0 = unapproved.
				array('post_visibility',		ITEM_APPROVED,					''),
				//array('post_edit_time',		'posts.post_edit_time',			array('typecast' => 'int')),
				//array('post_edit_count',		'posts.post_edit_count',		''),
				//array('post_edit_reason',		'',								''),
				//array('post_edit_user',		'',								'phpbb_post_edit_user'),

				array('bbcode_uid',			'post.dateline  AS post_time',	'make_uid'),
				array('post_text',				'post.pagetext',				'vb_prepare_message'),
				array('bbcode_bitfield',		'',								'get_bbcode_bitfield'),
				array('post_checksum',			'',								''),

				'left_join'		=> 'post LEFT JOIN thread ON (post.threadid = thread.threadid)',
			),

			array(
				'target'		=> PRIVMSGS_TABLE,
				'primary'		=> 'pmtextid',
				'autoincrement'	=> 'msg_id',
				'query_first'	=> array(
					array('target', $convert->truncate_statement . PRIVMSGS_TABLE),
					array('target', $convert->truncate_statement . PRIVMSGS_RULES_TABLE),
				),

				'execute_first'	=> '
					$config["max_post_chars"] = -1;
					$config["max_quote_depth"] = 0;
				',

				array('msg_id',					'pmtext.pmtextid',						''),
				array('root_level',				0,										''),
				array('author_id',					'pmtext.fromuserid AS poster_id',		'phpbb_user_id'),
				array('icon_id',					'pmtext.iconid',						''),
				//array('author_ip',				'privmsgs.privmsgs_ip',				'decode_ip'),
				array('message_time',				'pmtext.dateline',			''),
				array('enable_smilies',			'pmtext.allowsmilie',					''),
				array('enable_magic_url',			1,										''),
				array('enable_sig',				'pmtext.showsignature',					''),
				array('message_subject',			'pmtext.title',							'vb_set_encoding'), 
				array('message_edit_reason',		'',										''),
				array('message_edit_user',			0,										''),
				array('message_edit_time',			0,										''),
				array('message_edit_count',		0,										''),

				array('bbcode_uid',				'pmtext.dateline AS post_time',			'make_uid'),
				array('message_text',				'pmtext.message',						'vb_prepare_message'),
				array('bbcode_bitfield',			'',										'get_bbcode_bitfield'),
				array('to_address',				'pmtext.touserarray',					'vb_privmsgs_to_users'),
				array('bcc_address',				'',										''),
			),

			array(
				'target'		=> PRIVMSGS_FOLDER_TABLE,
				'primary'		=> 'user.userid',
				'query_first'	=> array('target', $convert->truncate_statement . PRIVMSGS_FOLDER_TABLE),

				array('user_id',				'user.userid',					'phpbb_user_id'),
				array('folder_name',			$user->lang['CONV_SAVED_MESSAGES'],	''),
				array('pm_count',				0,								''),
			
			),

			// Inbox
			array(
				'target'		=> PRIVMSGS_TO_TABLE,
				'primary'		=> 'pm.pmid',
				'query_first'	=> array('target', $convert->truncate_statement . PRIVMSGS_TO_TABLE),

				array('msg_id',				'pm.pmtextid',				''),
				array('user_id',				'pm.userid',				'phpbb_user_id'),
				array('author_id',				'pmtext.fromuserid',		'phpbb_user_id'),
				array('pm_deleted',			0,							''),
				array('pm_new',				'pm.messageread',			'vb_unread_pm'),
				array('pm_unread',				'pm.messageread',			'vb_unread_pm'),
				array('pm_replied',			'pm.messageread',			'vb_replied_pm'),
				array('pm_marked',				0,							''),
				array('pm_forwarded',			0,							''),
				array('folder_id',				PRIVMSGS_INBOX,				''),

				'left_join'		=> 'pm LEFT JOIN pmtext ON (pm.pmtextid = pmtext.pmtextid)',
				'where'			=> 'pm.folderid = 0',
			),
		
			// Sentbox
			array(
				'target'		=> PRIVMSGS_TO_TABLE,
				'primary'		=> 'pm.pmid',

				array('msg_id',				'pm.pmtextid',				''),
				array('user_id',				'pm.userid',				'phpbb_user_id'),
				array('author_id',				'pmtext.fromuserid',		'phpbb_user_id'),
				array('pm_deleted',			0,							''),
				array('pm_new',				0,							''),
				array('pm_unread', 			0,							''),
				array('pm_replied',			1,							''),
				array('pm_marked',				0,							''),
				array('pm_forwarded',			0,							''),
				array('folder_id',				PRIVMSGS_SENTBOX,			''),

				'left_join'		=> 'pm LEFT JOIN pmtext ON pm.pmtextid = pmtext.pmtextid',
				'where'			=> 'pm.folderid = -1',
			),

			array(
				'target'		=> RANKS_TABLE,
				'autoincrement'	=> 'rank_id',
				'query_first'	=> array('target', $convert->truncate_statement . RANKS_TABLE),

				array('rank_id',			'usertitle.usertitleid',		''),
				array('rank_title',		'usertitle.title',				''),
				array('rank_min',			'usertitle.minposts',			''),
				array('rank_special',		0,								''),
			),

			array(
				'target'		=> GROUPS_TABLE,
				'autoincrement'	=> 'group_id',
				'query_first'	=> array('target', $convert->truncate_statement . GROUPS_TABLE),

				array('group_id',				'usergroup.usergroupid',	''),
				array('group_type',			'usergroup.ispublicgroup',	'phpbb_convert_group_type'),
				array('group_display',			0,							''),
				array('group_legend',			0,							''),
				array('group_name',			'usergroup.title',			'phpbb_convert_group_name'), 
				array('group_desc',			'usergroup.description',	array('function1' => 'vb_set_encoding', 'function2' => 'utf8_htmlspecialchars')),

				'where'			=> 'usergroup.usergroupid > 1',
			),

			array(
				'target'		=> USER_GROUP_TABLE,
				'query_first'	=> array('target', $convert->truncate_statement . USER_GROUP_TABLE),
				'execute_first'	=> '
					add_default_groups();
				',

				array('group_id',				'user.usergroupid',			''),
				array('user_id',				'user.userid',				'phpbb_user_id'),
				array('group_leader',			0,							''),
				array('user_pending',			0,							''),
			),

			array(
				'target'		=> USER_GROUP_TABLE,

				array('group_id',		'user.membergroupids',				''),
				array('user_id',		'user.userid',						'phpbb_user_id'),
				array('group_leader',	0,									''),
				array('user_pending',	0,									''),

				'where'			=> 'user.membergroupids <> ""',
			),

			array(
				'distinct'		=> 'userid, relationid',
				'primary'		=> 'userlist.userid',
				'target'		=> ZEBRA_TABLE,
				'query_first'	=> array('target', $convert->truncate_statement . ZEBRA_TABLE),

				array('user_id',		'userlist.userid',					'phpbb_user_id'),
				array('zebra_id',		'userlist.relationid', 				'phpbb_user_id'),
				array('friend',		'userlist.type', 					array('execute' => '{RESULT} = ({VALUE}[0] == "buddy") ? 1 : 0;')),
				array('foe',			'userlist.type', 					array('execute' => '{RESULT} = ({VALUE}[0] == "ignore") ? 1 : 0;')),

				'where'			=> 'userlist.type = "buddy" OR userlist.type = "ignore"',
			),

			array(
				'target'		=> USERS_TABLE,
				'primary'		=> 'user.userid',
				'autoincrement'	=> 'user_id',
				'query_first'	=> array(
					array('target', 'DELETE FROM ' . USERS_TABLE . ' WHERE user_id <> ' . ANONYMOUS),
					array('target', $convert->truncate_statement . BOTS_TABLE),
				),

				'execute_last'	=> '
					remove_invalid_users();
				',

				array('user_id',				'user.userid',				'phpbb_user_id'),
				array('',						'user.userid AS poster_id',	'phpbb_user_id'),
				array('user_type',				'user.usergroupid',			'vb_set_user_type'),
				array('group_id',				'user.usergroupid',			'vb_set_primary_group'),
				array('user_ip',				'user.ipaddress',			''),
				array('user_passwd_salt',		'user.salt',				''),
				array('user_regdate',			'user.joindate',			''),
				array('username',				'user.username',			'vb_set_encoding'), 
				array('username_clean',		'user.username',			array('function1' => 'vb_set_encoding', 'function2' => 'utf8_clean_string')),
				array('user_password',			'user.password',			''),
				array('user_pass_convert',		1,							''),
				array('user_posts',			'user.posts',				''),
				array('user_email',			'user.email',				'strtolower'),
				// user_email_hash row removed:  the column was dropped in phpBB 3.3.0
				// and the helper function gen_email_hash() was removed in 3.1.
				// Inserting into a non-existent column would raise "Unknown column".
				array('user_birthday',			'user.birthday',			'vb_get_birthday'),
				array('user_lastvisit',		'user.lastvisit',			''),
				array('user_lastmark',			'user.lastactivity',		''),
				array('user_lang',				$config['default_lang'],	''),
				array('user_timezone',			'user.timezoneoffset',		'floatval'),
				//array('user_dateformat',		'users.user_dateformat',	''),//?
				array('user_inactive_reason',	'',							'vb_inactive_reason'),
				array('user_inactive_time',	'',							'vb_inactive_time'),
				// phpBB 3.1+ removed the following eight columns from phpbb_users
				// and migrated their data to the custom-profile-fields table
				// (phpbb_profile_fields_data).  Inserting them against a 3.3
				// destination produces "Unknown column" errors and rejects
				// every single user row.  Re-importing this profile data
				// requires a separate migration step that creates matching
				// custom fields and inserts into profile_fields_data; that's a
				// future enhancement.  For now we just don't carry these
				// columns over so user import succeeds.
				//
				//   user_from         -> custom field "Location"
				//   user_interests    -> custom field "Interests"
				//   user_occ          -> custom field "Occupation"
				//   user_website      -> custom field "Website"
				//   user_msnm         -> custom field "MSN"
				//   user_yim          -> custom field "Yahoo Messenger"
				//   user_aim          -> custom field "AOL"
				//   user_icq          -> custom field "ICQ"
				//
				// user_jabber DOES still exist in 3.3.16's phpbb_users -- kept.
				array('user_jabber',			'',							''),
				array('user_rank',				'user.customtitle',			''),
				array('user_permissions',		'',							''),

				array('',						'customavatar.filename',	''),
				array('',						'customavatar.height_thumb',''),
				array('',						'customavatar.width_thumb',	''),
				array('',						((VB_AVATAR_IN_DB)? 'customavatar.filedata_thumb' : ''), ''),
				array('',						'avatar.avatarid',			''),
				array('user_avatar',			'avatar.avatarpath',		'vb_avatar'),
				array('user_avatar_type',		'',							'vb_avatar_type'),
				array('user_avatar_width',		'avatar.avatarpath',		'vb_get_avatar_width'),
				array('user_avatar_height',	'avatar.avatarpath',		'vb_get_avatar_height'),

				array('user_new_privmsg',		'user.pmunread',			''),
				array('user_unread_privmsg',	'user.pmunread',			''), 
				array('user_emailtime',		'user.emailstamp',			'null_to_zero'),
				array('user_notify',			'user.autosubscribe',		'vb_autosubscribe'),
				array('user_notify_pm',		'user.options',				array('execute' => '{RESULT} = ({VALUE}[0] & 4096) ? 1 : 0;')),
				array('user_notify_type',		NOTIFY_EMAIL,				''),
				array('user_allow_pm',			'user.options',				array('execute' => '{RESULT} = ({VALUE}[0] & 2048) ? 1 : 0;')),
				array('user_allow_viewonline',	'user.options',				array('execute' => '{RESULT} = ({VALUE}[0] & 512) ? 0 : 1;')),
				//array('user_allow_viewemail',	'users.user_viewemail',			''),
				//array('user_actkey',			'users.user_actkey',			''),
				array('user_newpasswd',			'',								''), // Users need to re-request their password...
				array('user_style',				$config['default_style'],		''),

				array('',						'user.options',					'vb_user_options'),
				array('user_options',			'',								'set_user_options'),

				array('user_sig_bbcode_uid',	'user.joindate',				'make_uid'),
				array('user_sig',				'usertextfield.signature',		'vb_prepare_message'),
				array('user_sig_bbcode_bitfield',	'',							'get_bbcode_bitfield'),
				array('',						'user.joindate AS post_time',	''),

				'left_join'		=> array(
										'user LEFT JOIN userfield ON (user.userid = userfield.userid)',
										'user LEFT JOIN usertextfield ON (user.userid=usertextfield.userid)',
										'user LEFT JOIN avatar ON (user.avatarid = avatar.avatarid)',
										'user LEFT JOIN customavatar ON (user.userid = customavatar.userid AND customavatar.visible = 1)'
				),
				'where'			=> 'user.userid <> -1',
			),
		),
	);
}

?>
