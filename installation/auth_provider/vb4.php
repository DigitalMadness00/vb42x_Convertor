<?php
/**
*
* vBulletin 4.x.x compatibility authentication provider for phpBB 3.3.x
*
* Originally a procedural login_vb4() function for phpBB 3.0.x.
* Rewritten for PHP 7+ / phpBB 3.3.x as a namespaced auth provider class.
*
* @copyright (c) 2005 phpBB Group
* @copyright (c) 2008 Dicky
* @copyright (c) 2013 prototech
* @copyright (c) 2024 Updated for phpBB 3.3.x
* @license http://opensource.org/licenses/gpl-license.php GNU Public License
* @version 0.0.15
*
* ----------------------------------------------------------------------
* INSTALLATION
* ----------------------------------------------------------------------
* 1.  Place this file at:
*         phpBB/phpbb/auth/provider/vb4.php
*
* 2.  Register the service.  Edit:
*         phpBB/config/default/container/services_auth.yml
*     and add the following entry under the "services:" key (matching the
*     indentation of auth.provider.db):
*
*         auth.provider.vb4:
*             class: phpbb\auth\provider\vb4
*             arguments:
*                 - '@captcha.factory'
*                 - '@config'
*                 - '@dbal.conn'
*                 - '@passwords.manager'
*                 - '@request'
*                 - '@user'
*                 - '%core.root_path%'
*                 - '%core.php_ext%'
*             tags:
*                 - { name: auth.provider }
*
* 3.  Clear the phpBB cache (delete cache/production/* / cache/installer/*)
*     so the new service is picked up.
*
* 4.  In the ACP -> General -> Client communication -> Authentication, choose
*     "vb4" as the authentication method.  (The convertor sets
*     auth_method = vb4 for you automatically.)
*
* Once every imported user has logged in once their stored password is
* re-hashed in phpBB's native format and user_pass_convert is cleared,
* so you can safely switch the auth method back to "Database" afterwards.
* ----------------------------------------------------------------------
*/

namespace phpbb\auth\provider;

use phpbb\captcha\factory;
use phpbb\config\config;
use phpbb\db\driver\driver_interface;
use phpbb\passwords\manager;
use phpbb\request\request_interface;
use phpbb\user;

/**
 * vBulletin 4.x.x compatibility authentication provider.
 *
 * Behaves identically to the standard "db" provider except that, on the very
 * first successful login of a user whose user_pass_convert flag is set, it
 * also accepts the vBulletin 4 password format (md5(md5(password) . salt))
 * and silently migrates that hash to phpBB's native passwords-manager format.
 */
class vb4 extends \phpbb\auth\provider\db
{
	/** @var driver_interface */
	protected $db;

	/** @var manager */
	protected $passwords_manager;

	/** @var config */
	protected $config;

	/** @var factory */
	protected $captcha_factory;

	/** @var user */
	protected $user;

	/** @var request_interface */
	protected $request;

	/** @var string */
	protected $phpbb_root_path;

	/** @var string */
	protected $php_ext;

	/**
	 * Constructor - matches the phpBB 3.3.x \phpbb\auth\provider\db signature.
	 */
	public function __construct(factory $captcha_factory, config $config, driver_interface $db, manager $passwords_manager, request_interface $request, user $user, $phpbb_root_path, $php_ext)
	{
		parent::__construct($captcha_factory, $config, $db, $passwords_manager, $request, $user, $phpbb_root_path, $php_ext);

		$this->captcha_factory = $captcha_factory;
		$this->config = $config;
		$this->db = $db;
		$this->passwords_manager = $passwords_manager;
		$this->request = $request;
		$this->user = $user;
		$this->phpbb_root_path = $phpbb_root_path;
		$this->php_ext = $php_ext;
	}

	/**
	 * {@inheritdoc}
	 */
	public function login($username, $password)
	{
		// Auth plugins receive the password untrimmed; trim() for parity with the db provider.
		$password = trim($password);

		if (!$password)
		{
			return array(
				'status'	=> LOGIN_ERROR_PASSWORD,
				'error_msg'	=> 'NO_PASSWORD_SUPPLIED',
				'user_row'	=> array('user_id' => ANONYMOUS),
			);
		}

		if (!$username)
		{
			return array(
				'status'	=> LOGIN_ERROR_USERNAME,
				'error_msg'	=> 'LOGIN_ERROR_USERNAME',
				'user_row'	=> array('user_id' => ANONYMOUS),
			);
		}

		$username_clean = utf8_clean_string($username);

		// We need user_pass_convert and user_passwd_salt in addition to the
		// columns the standard db provider selects, so do our own SELECT.
		$sql = 'SELECT user_id, username, user_password, user_passchg, user_pass_convert, user_email, user_type, user_login_attempts, user_passwd_salt
			FROM ' . USERS_TABLE . "
			WHERE username_clean = '" . $this->db->sql_escape($username_clean) . "'";
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		// If this user does not have a vB-format password waiting to be
		// converted, defer entirely to the standard database provider.
		if (!$row || empty($row['user_pass_convert']))
		{
			return parent::login($username, $password);
		}

		// --- vB-format password path ---------------------------------------

		// Track failed attempts per IP/forwarded_for, mirroring the db provider.
		$ip            = $this->user->ip;
		$forwarded_for = $this->user->forwarded_for;
		$browser       = $this->user->browser;

		if (($ip && !$this->config['ip_login_limit_use_forwarded']) ||
			($forwarded_for && $this->config['ip_login_limit_use_forwarded']))
		{
			$sql = 'SELECT COUNT(*) AS attempts
				FROM ' . LOGIN_ATTEMPT_TABLE . '
				WHERE attempt_time > ' . (time() - (int) $this->config['ip_login_limit_time']);

			if ($this->config['ip_login_limit_use_forwarded'])
			{
				$sql .= " AND attempt_forwarded_for = '" . $this->db->sql_escape($forwarded_for) . "'";
			}
			else
			{
				$sql .= " AND attempt_ip = '" . $this->db->sql_escape($ip) . "' ";
			}

			$result = $this->db->sql_query($sql);
			$attempts = (int) $this->db->sql_fetchfield('attempts');
			$this->db->sql_freeresult($result);

			$attempt_data = array(
				'attempt_ip'			=> $ip,
				'attempt_browser'		=> trim(substr((string) $browser, 0, 149)),
				'attempt_forwarded_for'	=> $forwarded_for,
				'attempt_time'			=> time(),
				'user_id'				=> (int) $row['user_id'],
				'username'				=> $username,
				'username_clean'		=> $username_clean,
			);
			$sql = 'INSERT INTO ' . LOGIN_ATTEMPT_TABLE . ' ' . $this->db->sql_build_array('INSERT', $attempt_data);
			$this->db->sql_query($sql);
		}
		else
		{
			$attempts = 0;
		}

		$show_captcha = ($this->config['max_login_attempts'] && $row['user_login_attempts'] >= $this->config['max_login_attempts']) ||
						($this->config['ip_login_limit_max'] && $attempts >= $this->config['ip_login_limit_max']);

		// CAPTCHA verification at the threshold, like the db provider does.
		if ($show_captcha)
		{
			$captcha = $this->captcha_factory->get_instance($this->config['captcha_plugin']);
			$captcha->init(CONFIRM_LOGIN);

			$vc_response = $captcha->validate($row);
			if ($vc_response)
			{
				return array(
					'status'	=> LOGIN_ERROR_ATTEMPTS,
					'error_msg'	=> 'LOGIN_ERROR_ATTEMPTS',
					'user_row'	=> $row,
				);
			}

			$captcha->reset();
		}

		// Reproduce the vB4 password formula: md5(md5(plain) . salt).
		$vb_password = md5(md5($password) . (string) $row['user_passwd_salt']);

		if (hash_equals((string) $row['user_password'], $vb_password))
		{
			// Password matches the vB hash: re-hash it in phpBB's native
			// format and clear the convert flag so future logins go through
			// the fast path in parent::login().
			$hash = $this->passwords_manager->hash($password);

			$sql = 'UPDATE ' . USERS_TABLE . "
				SET user_password = '" . $this->db->sql_escape($hash) . "',
					user_pass_convert = 0
				WHERE user_id = " . (int) $row['user_id'];
			$this->db->sql_query($sql);

			$row['user_password']     = $hash;
			$row['user_pass_convert'] = 0;

			// Wipe failed-attempt records for this user.
			$sql = 'DELETE FROM ' . LOGIN_ATTEMPT_TABLE . '
				WHERE user_id = ' . (int) $row['user_id'];
			$this->db->sql_query($sql);

			if ($row['user_login_attempts'] != 0)
			{
				$sql = 'UPDATE ' . USERS_TABLE . '
					SET user_login_attempts = 0
					WHERE user_id = ' . (int) $row['user_id'];
				$this->db->sql_query($sql);
			}

			// Inactive / ignored users still need to be blocked.
			if ($row['user_type'] == USER_INACTIVE || $row['user_type'] == USER_IGNORE)
			{
				return array(
					'status'	=> LOGIN_ERROR_ACTIVE,
					'error_msg'	=> 'ACTIVE_ERROR',
					'user_row'	=> $row,
				);
			}

			return array(
				'status'	=> LOGIN_SUCCESS,
				'error_msg'	=> false,
				'user_row'	=> $row,
			);
		}

		// vB hash did not match either - bump the failed-attempt counter.
		$sql = 'UPDATE ' . USERS_TABLE . '
			SET user_login_attempts = user_login_attempts + 1
			WHERE user_id = ' . (int) $row['user_id'] . '
				AND user_login_attempts < ' . LOGIN_ATTEMPTS_MAX;
		$this->db->sql_query($sql);

		return array(
			'status'	=> ($show_captcha) ? LOGIN_ERROR_ATTEMPTS : LOGIN_ERROR_PASSWORD_CONVERT,
			'error_msg'	=> ($show_captcha) ? 'LOGIN_ERROR_ATTEMPTS' : 'LOGIN_ERROR_PASSWORD_CONVERT',
			'user_row'	=> $row,
		);
	}
}
