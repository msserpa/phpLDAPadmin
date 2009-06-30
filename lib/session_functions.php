<?php
// $Header: /cvsroot/phpldapadmin/phpldapadmin/lib/session_functions.php,v 1.18.2.3 2007/12/29 08:24:11 wurley Exp $

/**
 * A collection of functions to handle sessions throughout phpLDAPadmin.
 * @author The phpLDAPadmin development team
 * @package phpLDAPadmin
 */

/** The session ID that phpLDAPadmin will use for all sessions */
define('PLA_SESSION_ID','PLASESSID');
/** Enables session paranoia, which causes SIDs to change each page load (EXPERIMENTAL!) */
define('pla_session_id_paranoid', false);
/** Flag to indicate whether the session has already been initialized (this constant gets stored in $_SESSION) */
define('pla_session_id_init', 'pla_initialized');
/** The minimum first char value IP in hex for IP hashing. */
define('pla_session_id_ip_min', 8);
/** The maximum first char value of the IP in hex for IP hashing. */
define('pla_session_id_ses_max', 36);

/**
 * Creates a new session id, which includes an IP hash.
 *
 * @return string the new session ID string
 */
function pla_session_get_id() {
	if (DEBUG_ENABLED)
		debug_log('Entered with ()',1,__FILE__,__LINE__,__METHOD__);

	$id_md5 = md5(rand(1,1000000));
	$ip_md5 = md5($_SERVER['REMOTE_ADDR']);
	$id_hex = hexdec($id_md5[0]) + 1;
	$ip_hex = hexdec($ip_md5[0]);
	if ($ip_hex <= pla_session_id_ip_min)
		$ip_len = pla_session_id_ip_min;
	else
		$ip_len = $ip_hex - 1;

	$new_id = substr($id_md5, 0, $id_hex) .
		substr($ip_md5, $ip_hex, $ip_len) .
		substr($id_md5, $id_hex, pla_session_id_ses_max - ($id_hex + $ip_len));

	return $new_id;
}

/**
 * Checks if the session belongs to an IP
 *
 * @return bool True, if the session is valid
 */
function pla_session_verify_id() {
	if (DEBUG_ENABLED)
		debug_log('Entered with ()',1,__FILE__,__LINE__,__METHOD__);

	$check_id = session_id();
	$ip_md5 = md5($_SERVER['REMOTE_ADDR']);
	$id_hex = hexdec($check_id[0]) + 1;
	$ip_hex = hexdec($ip_md5[0]);
	if ($ip_hex <= pla_session_id_ip_min)
		$ip_len = pla_session_id_ip_min;
	else
		$ip_len = $ip_hex - 1;

	$ip_ses = substr($check_id, $id_hex, $ip_len);
	$ip_ver = substr($ip_md5, $ip_hex, $ip_len);

	return ($ip_ses == $ip_ver);
}

function pla_session_param() {
	/* If cookies were disabled, build the url parameter for the session id.
	   It will be append to the url to be redirect */
	return (SID != '') ? sprintf('&%s=%s',session_name(),session_id()) : '';
}

/**
 * The only function which should be called by a user
 *
 * @see common.php
 * @see PLA_SESSION_ID
 * @return bool Returns true if the session was started the first time
 */
function pla_session_start() {
	global $config;

	/* If session.auto_start is on in the server's PHP configuration (php.ini), then
	 * we will have problems loading our schema cache since the session will have started
	 * prior to loading the SchemaItem (and descedants) class. Destroy the auto-started
	 * session to prevent this problem.
	 */
	if (ini_get('session.auto_start'))
		@session_destroy();

	# Do we already have a session?
	if (@session_id())
		die;

	@session_name(PLA_SESSION_ID);
	@session_start();

	# Do we have a valid session?
	$is_initialized = is_array($_SESSION) && array_key_exists(pla_session_id_init,$_SESSION);

	if (! $is_initialized) {
		if (pla_session_id_paranoid) {
			ini_set('session.use_trans_sid',0);
			@session_destroy();
			@session_id(pla_session_get_id());
			@session_start();
			ini_set('session.use_trans_sid',1);
		}

		$_SESSION[pla_session_id_init]['version'] = pla_version();
		$_SESSION[pla_session_id_init]['config'] = filemtime(CONFDIR.'config.php');
	}

	@header('Cache-control: private'); // IE 6 Fix

	if (pla_session_id_paranoid && ! pla_session_verify_id())
		pla_error('Session inconsistent or session timeout');

	# Check we have the correct version of the SESSION cache
	if (isset($_SESSION['cache']) || isset($_SESSION[pla_session_id_init])) {
		if (!is_array($_SESSION[pla_session_id_init])) $_SESSION[pla_session_id_init] = array();

		if (!isset($_SESSION[pla_session_id_init]['version']) || !isset($_SESSION[pla_session_id_init]['config'])
			|| $_SESSION[pla_session_id_init]['version'] !== pla_version()
			|| $_SESSION[pla_session_id_init]['config'] != filemtime(CONFDIR.'config.php')) {
        
			$_SESSION[pla_session_id_init]['version'] = pla_version();
			$_SESSION[pla_session_id_init]['config'] = filemtime(CONFDIR.'config.php');

			unset($_SESSION['cache']);
			unset($_SESSION[APPCONFIG]);

			# Our configuration information has changed, so we'll redirect to index.php to get it reloaded again.
			system_message(array(
				'title'=>_('Configuration cache stale.'),
				'body'=>_('Your configuration has been automatically refreshed.'),
				'type'=>'info'));

			$config_file = CONFDIR.'config.php';
			check_config($config_file);

		} else {
			# Sanity check, specially when upgrading from a previous release.
			if (isset($_SESSION['cache']))
				foreach (array_keys($_SESSION['cache']) as $id)
					if (isset($_SESSION['cache'][$id]['tree']['null']) && ! is_object($_SESSION['cache'][$id]['tree']['null']))
						unset($_SESSION['cache'][$id]);
		}
	}

	# If we came via index.php, then set our $config.
	if (! isset($_SESSION[APPCONFIG]) && isset($config))
		$_SESSION[APPCONFIG] = $config;
}

/**
 * Stops the current session.
 */
function pla_session_close() {
    @session_write_close();
}
?>