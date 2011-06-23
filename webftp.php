<?php
/**
 * This file is part of the Froxlor project.
 * Copyright (c) 2011 the Froxlor Team (see authors).
 *
 * For the full copyright and license information, please view the COPYING
 * file that was distributed with this source code. You can also view the
 * COPYING file online at http://files.froxlor.org/misc/COPYING.txt
 *
 * @copyright  (c) the authors
 * @author     Froxlor team <team@froxlor.org> (2010-)
 * @license    GPLv2 http://files.froxlor.org/misc/COPYING.txt
 * @package    WebFTP
 *
 */

// Configuration
// Server to connect to:
$server = 'localhost';

// Temporary directory on the server (need write permissions)
$downloadDir = "/tmp/";

// Which file extensions indicate files allowed to be edited
// Either simple extension or regex
$editFileExtensions = array("php[34]?$","sh$","txt$","[ps]?htm[l]?$","tpl$","pl","cgi","^ht[acespwd]+$");
// Are files without extension allowed to be edited?
$editFileNoExtension = true;
// Which FTP - mode should be used by default?
$default_mode = "FTP_BINARY";
// Max. uploadsize (0 = unlimited)
$MAX_FILE_SIZE = 1907300;

header("Content-Type: text/html; charset=utf-8");

// prevent Froxlor pages from being cached
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");
header('Last-Modified: ' . gmdate( 'D, d M Y H:i:s \G\M\T', time()));
header('Expires: ' . gmdate( 'D, d M Y H:i:s \G\M\T', time()));

// Prevent inline - JS to be executed (i.e. XSS) in browsers which support this,
// Inline-JS is no longer allowed and used
// See: http://people.mozilla.org/~bsterne/content-security-policy/index.html
header("X-Content-Security-Policy: allow 'self'; frame-ancestors 'none'");

// Don't allow to load Froxlor in an iframe to prevent i.e. clickjacking
header('X-Frame-Options: DENY');

// If Froxlor was called via HTTPS -> enforce it for the next time
if(isset( $_SERVER['HTTPS']) && (strtolower($_SERVER['HTTPS']) != 'off' ))
{
	header('Strict-Transport-Security: max-age=500');
}

// Internet Explorer shall not guess the Content-Type, see:
// http://blogs.msdn.com/ie/archive/2008/07/02/ie8-security-part-v-comprehensive-protection.aspx
header('X-Content-Type-Options: nosniff' );

// ensure that default timezone is set
if(function_exists("date_default_timezone_set") && function_exists("date_default_timezone_get"))
{
	@date_default_timezone_set(@date_default_timezone_get());
}

// Load the database - connection parameters
if(!file_exists('./lib/userdata.inc.php'))
{
	$config_hint = file_get_contents('./templates/Froxlor/misc/configurehint.tpl');
	die($config_hint);
}

if(!is_readable('./lib/userdata.inc.php'))
{
	die('You have to make the file "./lib/userdata.inc.php" readable for the http-process!');
}

require ('./lib/userdata.inc.php');

if(!isset($sql) || !is_array($sql))
{
	$config_hint = file_get_contents('./templates/Froxlor/misc/configurehint.tpl');
	die($config_hint);
}

if(!function_exists("ftp_connect"))
{
	die('No FTP support');
}

// Create the database - connection
$db = new mysqli($sql['host'], $sql['user'], $sql['password'], $sql['db']);
unset($sql);
if ($db->connect_error)
{
    die('Connect Error (' . $db->connect_errno . ') ' . $db->connect_error);
}

$settings = array();
// Let's get the theme we need
if ($result = $db->query("SELECT `value` FROM `panel_settings` WHERE `varname` = 'default_theme'"))
{
	list($settings['panel']['default_theme']) = $result->fetch_array();
}
else
{
	// Default will be Froxlor ;)
	$settings['panel']['default_theme'] = 'Froxlor';
}

# Set the language
setLocale(LC_ALL,'en_US.utf8');
bindtextdomain('default','./locales/');
textdomain('default');
if ($handle = opendir('./locales/'))
{
	$files = array();
	while(false!==($file = readdir($handle)))
	{
		if (is_dir('./locales/' . $file) && !preg_match('/^\.\.?$/', $file))
		{
			$files[] = $file;
		}
	}
	closedir($handle);
	sort($files);
	$available_languages = array();
	foreach($files as $f)
	{
		$available_languages[] = $f;
	}
}
else
{
	echo 'error: missing language files';
	exit;
}

$ls = array();
if (!isset($_SERVER['HTTP_ACCEPT_LANGUAGE']))
{
	$_SERVER['HTTP_ACCEPT_LANGUAGE'] = '';
}

$langs = explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE']);
foreach($langs as $lang)
{
	if (in_array(preg_replace('/;.*/','',trim($lang)), $available_languages))
	{
		$selected_language = preg_replace('/;.*/','',trim($lang));
		break;
	}
}
if(!isset($selected_language))
{
	$selected_language = 'en';
}

if(!setLocale(LC_ALL, $selected_language))
{
	preg_match_all("/[^|\w]".$selected_language.'.*/',`locale -a`,$matches);
	if(!count($matches[0]))
	{
		die('no locale info for "'.$selected_language.'"');
	}
	$selected_language = trim($matches[0][0]);
	foreach($matches[0] as $m)
	{
		if(preg_match('/utf8/', $m))
		{
			$selected_language = trim($m);
			break;
		}
	}
	setLocale(LC_ALL, $selected_language);
}

# Initialize Smarty
include('./lib/classes/Smarty/Smarty.class.php');
$smarty = new Smarty;

$smarty->template_dir = './templates/' . $settings['panel']['default_theme'] . '/';
$smarty->compile_dir  = './templates_c/';
$smarty->cache_dir    = './cache/';

$settings['admin']['show_version_login'] = 0;
if ($result = $db->query("SELECT `value` FROM `panel_settings` WHERE `settinggroup` = 'admin' AND `varname` = 'show_version_login'"))
{
	list($settings['admin']['show_version_login']) = $result->fetch_array();
}
$settings['admin']['show_version_footer'] = 0;
if ($result = $db->query("SELECT `value` FROM `panel_settings` WHERE `settinggroup` = 'admin' AND `varname` = 'show_version_footer'"))
{
	list($settings['admin']['show_version_footer']) = $result->fetch_array();
}
$settings['panel']['no_robots'] = 0;
if ($result = $db->query("SELECT `value` FROM `panel_settings` WHERE `settinggroup` = 'panel' AND `varname` = 'no_robots'"))
{
	list($settings['panel']['no_robots']) = $result->fetch_array();
}

// We don't need the database anymore
$db->close();
unset($db);

# Activate gettext for smarty;
define('HAVE_GETTEXT', true);
require ('./lib/functions/smarty_plugins/gettext-prefilter.php');
# Set default options for template
$image_path = 'images/'.$settings['panel']['default_theme'];
$header_logo = $image_path.'/logo.png';
if(file_exists($image_path.'/logo_custom.png'))
{
	$header_logo = $image_path.'/logo_custom.png';
}
$smarty->assign('header_logo', $header_logo);
$smarty->assign('settings', $settings);
$smarty->assign('loggedin', 0);
$smarty->assign('current_year', date('Y'));
$smarty->assign('image_folder', $image_path);
$smarty->assign('title', 'WebFTP - ');

# Let's start the program
session_start();
$s = session_id();

if (isset($_GET['logoff']) || isset($_POST['logoff']))
{
	unset($_SESSION['server'],$_SESSION['user'],$_SESSION['password']);
	session_destroy();
	$smarty->assign('successmessage', _('Successfully logged out'));
	$body = $smarty->fetch('login/login_ftp.tpl');
}

elseif ((!empty($_POST['loginname']) && !empty($_POST['password'])) || (!empty($_SESSION['user']) && !empty($_SESSION['password']) && !empty($_SESSION['server'])))
{
	if(empty($_SESSION['server']))
	{
		$_SESSION['server'] = $server;
	}
	if(isset($_POST['loginname']))
	{
		$_SESSION['user'] = $_POST['loginname'];
	}
	if(isset($_POST['password']))
	{
		 $_SESSION['password'] = $_POST['password'];
	}

	$connection = @ftp_connect($_SESSION['server']);
	$loggedOn = @ftp_login($connection, $_SESSION['user'], $_SESSION['password']);
	$systype = @ftp_systype($connection);
	$pasv = @ftp_pasv($connection, false);

	// Mode setzen
	if(isset($_POST['mode']))
	{
		$mode = $_POST['mode'];
		$_SESSION['mode'] = $mode;
	}
	elseif(isset($_GET['mode']))
	{
		$mode = $_GET['mode'];
		$_SESSION['mode'] = $mode;
	}
	elseif(isset($_SESSION['mode']))
	{
		$mode = $_SESSION['mode'];
	}
	else
	{
		$mode = $default_mode;
		$_SESSION['mode'] = $default_mode;
	}
	$smarty->assign('mode', $mode);

	if(isset($_POST['file']) && is_array($_POST['file']))
	{
		$file = $_POST['file'];
		if(get_magic_quotes_gpc())
		{
			foreach($_POST['file'] as $key => $val)
			{
				$_POST['file'][$key] = stripslashes($val);
			}
			foreach($_POST as $key => $val)
			{
				$_POST[$key] = stripslashes($val);
			}
			foreach($_GET as $key => $val)
			{
				$_GET[$key] = stripslashes($val);
			}
		}
	}
	else
	{
		if(get_magic_quotes_gpc())
		{
			foreach($_POST as $key => $val)
			{
				$_POST[$key] = stripslashes($val);
			}
			foreach($_GET as $key => $val)
			{
				$_GET[$key] = stripslashes($val);
			}
		}
		if(isset($_POST['file']))
		{
			$file = $_POST['file'];
			$smarty->assign('file', $file);
		}
		elseif(isset($_GET['file']))
		{
			$file = $_GET['file'];
			$smarty->assign('file', $file);
		}
		//$file = htmlentities($file);
	}

	if(isset($_POST['action']))
	{
		$action = $_POST['action'];
	}
	elseif(isset($_GET['action']))
	{
		$action = $_GET['action'];
	}
	else
	{
		$action = '';
	}

	if ($loggedOn)
	{
		$smarty->assign('loggedin', true);

		if (isset($_POST['currentDir']))
		{
			if (!@ftp_chdir($connection, html_entity_decode($_POST['currentDir'])))
			{
				$smarty->assign('errormessage', sprintf(_('Directory change to \'%1$s\' failed!'), $file));
			}
		}
		elseif (isset($_GET['currentDir']))
		{
			if(!@ftp_chdir($connection, html_entity_decode($_GET['currentDir'])))
			{
				$smarty->assign('errormessage', sprintf(_('Directory change to \'%1$s\' failed!'), $file));
			}
		}

		$currentDir = htmlentities(str_replace("\"\"","\"", ftp_pwd($connection)));

		$smarty->assign('currentDir', $currentDir);
		$smarty->assign('curdir', sprintf(_('current folder = [%1$s]'), $currentDir));

		// was soll gemacht werden
		$errormessage = '';
		$successmessage = '';
		switch ($action)
		{
			case "cd":			// Ordner wechseln
				//First try : normal directory
				if(@ftp_chdir($connection, html_entity_decode($currentDir) . "/" .  html_entity_decode($file)))
				{
					$currentDir =  htmlentities(str_replace("\"\"","\"", @ftp_pwd($connection)));
					$smarty->assign('curdir', sprintf(_('current folder = [%1$s]'), $currentDir));
					$smarty->assign('currentDir', $currentDir);
				}
				elseif(@ftp_chdir($connection,  html_entity_decode($file)))
				{ // symbolischer Link
					$currentDir =  htmlentities(str_replace("\"\"","\"", @ftp_pwd($connection)));
					$smarty->assign('curdir', sprintf(_('current folder = [%1$s]'), $currentDir));
					$smarty->assign('currentDir', $currentDir);
				}
				else
				{ // link zu einer Datei, abrufen...
					header("Content-disposition: filename=$file");
					header("Content-type: application/octetstream");
					header("Pragma: no-cache");
					header("Expires: 0");

					// original Dateiname
					$filearray = explode("/",$file);
					$file = $filearray[sizeof($filearray)-1];
					$msg = $file;

					// Datei vom FTP temporär speichern
					$fp = fopen($downloadDir .  killslashes(html_entity_decode($file))."_".$s, "w");
					if($mode == "FTP_ASCII")
					{
						$downloadStatus = @ftp_fget($connection,$fp, html_entity_decode($file),FTP_ASCII);
					}
					elseif($mode == "FTP_BINARY")
					{
						$downloadStatus = @ftp_fget($connection,$fp, html_entity_decode($file),FTP_BINARY);
					}

					if(!$downloadStatus)
					{
						fclose($fp);
						exit;
					}
					fclose($fp);
					// temporäre Datei lesen und ausgeben
					$data = file($downloadDir .  killslashes(html_entity_decode($file))."_".$s);
					/*$i=0;
					while ($data[$i] != ""){
						echo $data[$i];
						$i++;
					}*/
					foreach($data as $outData)
					{
						echo $outData;
					}
					unlink($downloadDir .  killslashes(html_entity_decode($file))."_".$s);
					exit;
				}
				break;
			case "cddirect":
				if(@ftp_chdir($connection, $file))
				{
					$currentDir = htmlentities(str_replace("\"\"","\"",@ftp_pwd($connection)));
					$smarty->assign('curdir', sprintf(_('current folder = [%1$s]'), $currentDir));
				}
				else
				{
					$errormessage = sprintf(_('Directory change to \'%1$s\' failed!'), $file);
				}
				break;
			case "get":			// Datei dwonload
				header("Content-disposition: filename=".killslashes(html_entity_decode($file)));
				header("Content-type: application/octetstream");
				header("Pragma: no-cache");
				header("Expires: 0");
				// Datei vom FTP temporär speichern
				$fp = fopen($downloadDir . killslashes(html_entity_decode($file))."_".$s, "w");
				if($mode == "FTP_ASCII")
				{
					$downloadStatus = ftp_fget($connection,$fp, html_entity_decode($file),FTP_ASCII);
				}
				elseif($mode == "FTP_BINARY")
				{
					$downloadStatus = ftp_fget($connection,$fp, html_entity_decode($file),FTP_BINARY);
				}
				fclose($fp);

				// temporäre Datei lesen und ausgeben
				$data = file($downloadDir .  killslashes(html_entity_decode($file))."_".$s);
				//print_r($data);
				/*$i=0;
				while ($data[$i] != "")
				{
					echo $data[$i];
					$i++;
				}*/
				foreach($data as $outData)
				{
					echo $outData;
				}
				unlink($downloadDir .  killslashes(html_entity_decode($file))."_".$s);
				exit;
				break;
			case "put":			// Datei hochladen
				foreach($_FILES as $myFile => $dummyvar)
				{
					if(file_exists($_FILES[$myFile]['tmp_name']) && ($_FILES[$myFile]['size'] > $MAX_FILE_SIZE && $MAX_FILE_SIZE!=0))
					{
						$errormessage .= sprintf(_('<strong>File \'%1$s\' is to big!</strong> (max. %2$u bytes)<br />'), $_FILES[$myFile]['name'], $MAX_FILE_SIZE);
					}
					elseif(file_exists($_FILES[$myFile]['tmp_name']))
					{
						if($mode == "FTP_ASCII")
						{
							$uploadStatus = ftp_put($connection, html_entity_decode($currentDir) . "/" . $_FILES[$myFile]['name'], $_FILES[$myFile]['tmp_name'], FTP_ASCII);
						}
						elseif($mode == "FTP_BINARY")
						{
							$uploadStatus = ftp_put($connection, html_entity_decode($currentDir) . "/" . $_FILES[$myFile]['name'], $_FILES[$myFile]['tmp_name'], FTP_BINARY);
						}

						if(!$uploadStatus)
						{
							$errormessage .= sprintf(_('<br />File \'%1$s\' couldn\'t be uploaded!'), $_FILES[$myFile]['name']);
						}
						else
						{
							$successmessage .= sprintf(_('<br />File \'%1$s\' was successfully uploaded!'), $_FILES[$myFile]['name']);
						}

						unlink($_FILES[$myFile]['tmp_name']);
					}
				}
				break;
			case "deldir":		// Ordner löschen
				if(ftp_rmdir($connection,  html_entity_decode($file)))
				{
					$successmessage = sprintf(_('<br />Directory \'%1$s\' deleted!'), $file);
				}
				else
				{
					$errormessage = sprintf(_('<br />Directory \'%1$s\' couldn\'t be deleted!'), $file);
				}
				break;
			case "delfile":		// Datei löschen
				if (@ftp_delete($connection,  $file))
				{
					$successmessage = sprintf(_('<br />\'%1$s\' deleted!'), $file);
				}
				else
				{
					$errormessage = sprintf(_('<br />\'%1$s\' couldn\'t be deleted!'), $file);
				}
				break;
			case "rename":		// Datei umbennenen
				if($_POST['op']=="do")
				{
					if (@ftp_rename($connection, $file, $_POST['file2']))
					{
						$successmessage = sprintf(_('\'%1$s\' renamed to \'%2$s\''), $file, $_POST['file2']);
					}
					else
					{
						$errormessage = sprintf(_('\'%1$s\' couldn\'t be renamed to \'%2$s\'!'), $file, $_POST['file2']);
					}
				}
				elseif($_GET['op']=="show")
				{
					$smarty->assign('rename_text', sprintf(_('File \'%1$s\' rename/move to'), $file));
				}
				break;
			case "createdir":  // neuen Ordner erstellen
				if(@ftp_mkdir($connection,  $file))
				{
					$successmessage = sprintf(_('Directory \'%1$s\' created'), $file);
				}
				else
				{
					$errormessage = sprintf(_('Directory \'%1$s\' couldn\'t be created!'), $file);
				}
				break;
			case "chmod":  // Berechtigungen setzen
				$wrongchmod = false;
				$split = preg_split('//', $_POST['file2'], -1, PREG_SPLIT_NO_EMPTY);
				foreach($split as $char)
				{
					if ($char < 0 || $char > 7)
					{
						$wrongchmod = true;
					}
				}
				if ($wrongchmod || strlen($_POST['chmod']) > 3)
				{
					$errormessage = sprintf(_('<br />The permission \'%1$s\' you entered is not valid!'), $_POST['file2']);
				}
				else
				{
					$command = "chmod {$_POST['file2']} {$_POST['file']}";
					if(!$wrongchmod && ftp_site($connection,$command))
					{
						$successmessage = sprintf(_('<br />The permission of \'%1$s\' is set to \'%2$s\'!'), $file, $_POST['file2']);
					}
					else
					{
						$errormessage = sprintf(_('<br />The permission of \'%1$s\' couldn\'t be set to \'%2$s\'!'), $file, $_POST['file2']);
					}
				}
				break;
			case "multiple":		// merhfachaktionen
				if(isset($_POST['yes']) && $_POST['yes']!="" && !isset($_POST['no']))
				{
					if ($_POST['op']=="chmod")
					{
						$wrongchmod = false;
						$split = preg_split('//', $_POST['chmod'], -1, PREG_SPLIT_NO_EMPTY);
						foreach($split as $char)
						{
							if($char<0||$char>7)
							{
								$wrongchmod = true;
							}
						}
						if($wrongchmod || strlen($_POST['chmod'])>3)
						{
							$errormessage .= sprintf(_('<br />The permission \'%1$s\' you entered is not valid!'), $_POST['file2']);
						}
						else
						{
							if(is_array($file))
							{
								foreach ($file as $myID => $myName)
								{
									$command = "chmod $_POST[chmod] ".$myName;
									if (ftp_site($connection,$command))
									{
										$successmessage .= sprintf(_('<br />The permission of \'%1$s\' is set to \'%2$s\'!'), $myName, $_POST['chmod']);
									}
									else
									{
										$errormessage .= sprintf(_('<br />The permission of \'%1$s\' couldn\'t be set to \'%2$s\'!'), $myName, $_POST['chmod']);
									}
								}
							}
						}
					}
					elseif($_POST['op']=="delete")
					{
						if(is_array($file))
						{
							foreach ($file as $myID => $myName)
							{
								if(ftp_chdir($connection, $myName))
								{
									ftp_chdir($connection, html_entity_decode($currentDir));
									$del_status = ftp_rmdir($connection, html_entity_decode($myName));
								}
								else
								{
									ftp_chdir($connection, html_entity_decode($currentDir));
									$del_status = ftp_delete($connection, $myName);
								}

								if($del_status)
								{
									$successmessage .= sprintf(_('<br />\'%1$s\' deleted!'), $myName);
								}
								else
								{
									$errormessage .= sprintf(_('<br />\'%1$s\' couldn\'t be deleted!'), $myName);
								}
							}
						}
					}
					elseif($_POST['op']=="move")
					{
						if(ftp_chdir($connection, $_POST['move_to']))
						{
							ftp_chdir($connection, html_entity_decode($currentDir));
							if(substr($_POST['move_to'],-1)!="/")
							{
								$_POST['move_to'] .= "/";
							}
							if(is_array($file))
							{
								foreach ($file as $myID => $myName)
								{
									if(ftp_rename($connection, $myName,$_POST['move_to'].$myName))
									{
										$successmessage .= sprintf(_('<br />File \'%1$s\' moved'), $myName);
									}
									else
									{
										$errormessage .= sprintf(_('<br />File \'%1$s\' couldn\'t be moved'), $myName);
									}
								}
							}
						}
						else
						{
							$errormessage = sprintf(_('The directory \'%1$s\' doesn\'t exist'), $_POST['move_to']);
						}
					}
				}
				break;
			case "edit":
				$extarray = explode(".", $file);
				$extension = $extarray[sizeof($extarray)-1];
				$editAble = false;
				foreach($editFileExtensions as $regex)
				{
					if(preg_match("/$regex/i", $extension))
					{
						$editAble = true;
						break;
					}
				}
				if($extension == $file && $editFileNoExtension)
				{
					$editAble = true;
				}

				if($editAble)
				{
					if ((isset($_GET['op']) && $_GET['op'] == "open") || (isset($_POST['op']) && $_POST['op'] == "open"))
					{//datei auslesen und im Browser anzeigen
						// original Dateiname
						$filearray = explode("/",$file);
						$file = $filearray[sizeof($filearray)-1];

						// Datei vom FTP temporär speichern
						$fp = fopen($downloadDir . killslashes(html_entity_decode($file)) . "_" . $s, "w");
						if($mode == "FTP_BINARY")
						{
							$downloadStatus = ftp_fget($connection, $fp, html_entity_decode($file),FTP_BINARY);
						}
						else
						{
							$downloadStatus = ftp_fget($connection, $fp, html_entity_decode($file),FTP_ASCII);
						}

						if(!$downloadStatus)
						{
							fclose($fp);
							$errormessage = sprintf(_('File \'%1$s\' couldn\'t be downloaded!'), $file);
							$myFileContent = '';
						}
						else
						{
							fclose($fp);
							// temporäre Datei lesen und ausgeben
							$myFileContent = htmlentities(implode("",file($downloadDir .  killslashes(html_entity_decode($file))."_".$s)));
						}
						$smarty->assign('myFileContent', $myFileContent);
						unlink($downloadDir .  killslashes(html_entity_decode($file))."_".$s);
					}
					elseif ((isset($_GET['op']) && $_GET['op'] == "save") || (isset($_POST['op']) && $_POST['op'] == "save"))
					{// datei speichern
						$fp = fopen($downloadDir .  killslashes(html_entity_decode($file))."_".$s, "w");
						if ((isset($_GET['op']) && $_GET['op'] != "new") && (isset($_POST['op']) && $_POST['op'] != "new"))
						{
							fputs($fp, $_POST['fileContent'], strlen($_POST['fileContent']));
						}
						else
						{
							fputs($fp, html_entity_decode($_POST['fileContent']), strlen(html_entity_decode($_POST['fileContent'])));
						}
						fclose($fp);

						$uploadStatus = false;
						if($mode == "FTP_BINARY")
						{
							$uploadStatus = ftp_put($connection, html_entity_decode($currentDir). "/" . html_entity_decode($file), $downloadDir .  killslashes(html_entity_decode($file))."_".$s, FTP_BINARY);
						}
						else
						{
							$uploadStatus = ftp_put($connection, html_entity_decode($currentDir). "/" . html_entity_decode($file), $downloadDir .  killslashes(html_entity_decode($file))."_".$s, FTP_ASCII);
						}

						if(!$uploadStatus)
						{
							$errormessage = sprintf(_('File \'%1$s\' couldn\'t be saved!'), $file);
						}
						else
						{
							$successmessage = sprintf(_('File \'%1$s\' was saved succesfully!'), $file);
						}
						unlink($downloadDir .  killslashes(html_entity_decode($file))."_".$s);
					}
				}
				else
				{
					$errormessage = sprintf(_('Files with these extension can\'t be created/edited!'), $file);
				}

				if((isset($_GET['op']) && $_GET['op'] != "new") && (isset($_POST['op']) && $_POST['op'] != "new"))
				{
					if($file == "")
					{
						$editAble = false;
						$errormessage = _('Please enter a filename!');
					}
				}
				break;
			case "mode":
				if($_GET['mode'] == "FTP_BINARY")
				{
					$mode = "FTP_BINARY";
					$_SESSION['mode'] = "FTP_BINARY";
				}
				else
				{
					$mode = "FTP_ASCII";
					$_SESSION['mode'] = "FTP_ASCII";
				}
				$smarty->assign('mode', $mode);
				break;
		}

		if (strlen($errormessage) > 0)
		{
			$smarty->assign('errormessage', $errormessage);
		}
		if (strlen($successmessage) > 0)
		{
			$smarty->assign('successmessage', $successmessage);
		}

		if ($action == "edit" && ((isset($_GET['op']) && $_GET['op'] == "open") || (isset($_POST['op']) && $_POST['op'] == "open")))
		{
			$file = htmlentities($file);
			$smarty->assign('file', $file);
			$body = $smarty->fetch('webftp/webftp_edit.tpl');
		}
		elseif ($action == "edit" && ((isset($_GET['op']) && $_GET['op'] == "new") || (isset($_POST['op']) && $_POST['op'] == "new")))
		{
			$file = htmlentities($file);
			$smarty->assign('file', $file);
			$body = $smarty->fetch('webftp/webftp_edit_new.tpl');
		}
		else
		{
			$list = Array();
			$list = ftp_rawlist($connection, "-a ./");

			$countArray = array("dir" => 0, "dirsize" => 0, "file" => 0, "filesize" => 0, "link" => 0);
			$list = parse_ftp_rawlist($list, $systype);
			if (!isset($file))
			{
				$file = "";
			}
			if (is_array($list))
			{
				// Ordner
				$output_dir = '';
				foreach($list as $myDir)
				{
					if ($myDir["is_dir"] == 1)
					{
						$countArray['dir']++;
						$countArray['dirsize'] += $myDir['size'];
						$fileAction = "cd";
						$fileName = $myDir["name"];
						if(is_array($file) && val_in_array($fileName, $file))
						{
							$smarty->assign('checked', 'checked');
							$smarty->assign('checked_color', "bgcolor=\"".$marked_color."\"");
						}
						else
						{
							$smarty->assign('checked', "");
							$smarty->assign('checked_color', "");
						}
						$smarty->assign('myDir', $myDir);
						$output_dir .= $smarty->fetch('webftp/webftp_main_dir_row.tpl');
					}
				}

				// Links
				$output_link = '';
				foreach($list as $myDir)
				{
					if ($myDir["is_link"] == 1)
					{
						$countArray['link']++;
						$fileAction = "cd";
						$fileName = $myDir["target"];
						if (is_array($file) && val_in_array($fileName, $file))
						{
							$smarty->assign('checked', 'checked');
							$smarty->assign('checked_color', "bgcolor=\"".$marked_color."\"");
						}
						else
						{
							$smarty->assign('checked', "");
							$smarty->assign('checked_color', "");
						}
						$smarty->assign('myDir', $myDir);
						$output_link .= $smarty->fetch('webftp/webftp_main_link_row.tpl');
					}
				}

				// Dateien
				$output_file = '';
				foreach($list as $myDir)
				{
					if ($myDir["is_link"] != 1 && $myDir["is_dir"] != 1)
					{
						$countArray['file']++;
						$countArray['filesize'] += $myDir['size'];
						$fileAction = "get";
						$fileName = $myDir["name"];
						if (is_array($file) && val_in_array($fileName, $file))
						{
							$smarty->assign('checked', 'checked');
							$smarty->assign('checked_color', "bgcolor=\"".$marked_color."\"");
						}
						else
						{
							$smarty->assign('checked', "");
							$smarty->assign('checked_color', "");
						}
						// prüfen ob die datei bearbeitbar ist
						$extarray = explode(".",$fileName);
						$extension = $extarray[sizeof($extarray)-1];
						$smarty->assign('editable', false);
						foreach($editFileExtensions as $regex)
						{
							if(preg_match("/$regex/i", $extension))
							{
								$smarty->assign('editable', true);
								break;
							}
						}
						if($extension == $fileName && $editFileNoExtension)
						{
							$smarty->assign('editable', true);
						}
						$smarty->assign('myDir', $myDir);
						$output_file .= $smarty->fetch('webftp/webftp_main_file_row.tpl');
					}
				}
			}

			if(!is_array($file))
			{
				$file = htmlentities($file);
				$smarty->assign('file', $file);
			}

			$smarty->assign('connected_to', sprintf(_('Connected to %1$s'), $_SESSION['server']));

			$body = $smarty->fetch('webftp/webftp_main_header.tpl');
			$body .= $smarty->fetch('webftp/webftp_main_additional.tpl');

			if($action == "rename" && $_GET['op']=="show")
			{
				$body .= $smarty->fetch('webftp/webftp_main_rename');
			}
			$smarty->assign('output_dir', $output_dir);
			$smarty->assign('output_link', $output_link);
			$smarty->assign('output_file', $output_file);
			$smarty->assign('countArray', $countArray);
			$body .= $smarty->fetch('webftp/webftp_main.tpl');

			if ($action == "multiple" && (!isset($_POST['yes']) || $_POST['yes']=="") && (!isset($_POST['no']) || $_POST['no']==""))
			{
				if($_POST['op']=="delete")
				{
					$smarty->assign('action_text', _('Do you really want to delete the selected files?'));
				}
				elseif($_POST['op']=="move")
				{
					$smarty->assign('action_text', sprintf(_('Do you really want to move the selected files to \'%1$s\'?'), $_POST['move_to']));
					$smarty->assign('move_to', $_POST['move_to']);
				}
				elseif($_POST['op']=="chmod")
				{
					$smarty->assign('action_text', sprintf(_('Do you really want to set the permission of the selected files to \'%1$s\'?'), $_POST['chmod']));
					$smarty->assign('chmod', $_POST['chmod']);
				}
				$smarty->assign('op', $_POST['op']);
				$body .= $smarty->fetch('webftp/webftp_main_prompt');
			}
			else
			{
				$body .= $smarty->fetch('webftp/webftp_main_multiple.tpl');
			}
		}
		$smarty->assign('completeLink', '<a href="webftp.php?logoff=true">' . _('Logout') . '</a>');
		$navlinks = $smarty->fetch('navigation_link.tpl');
		$smarty->assign('completeLink', '<a href="webftp.php?webftp.php?action=mode&amp;mode=FTP_BINARY&amp;currentDir=' . $currentDir . '">' . _('Switch to BINARY mode') . '</a>');
		$navlinks .= $smarty->fetch('navigation_link.tpl');
		$smarty->assign('completeLink', '<a href="webftp.php?webftp.php?action=mode&amp;mode=FTP_ASCII&amp;currentDir=' . $currentDir . '">' . _('Switch to ASCII mode') . '</a>');
		$navlinks .= $smarty->fetch('navigation_link.tpl');
		$smarty->assign('completeLink', _('Main'));
		$smarty->assign('navigation_links', $navlinks);
		$smarty->assign('navigation', $smarty->fetch('navigation_element.tpl'));
	}
	else
	{
		$smarty->assign('errormessage', _('Login failed, please try again'));
		$body = $smarty->fetch('login/login_ftp.tpl');
	}
}
else
{
	$body = $smarty->fetch('login/login_ftp.tpl');
}

$smarty->assign('body', $body);
$smarty->display('index.tpl');

/**
 * Functions
 * This should all be checked if still necessary
 */
function killslashes($input)
{
	return str_replace("\\","",str_replace('\'','',str_replace("\"","",$input)));
}

function parse_ftp_rawlist($list, $type = "UNIX")
{
	if ($type == "UNIX")
	{
		$regexp = "/([-ldrswx]{10})[ ]+([0-9]+)[ ]+([-A-Z0-9_]+)[ ]+([-A-Z0-9_]+)[ ]+([0-9]+)[ ]+([A-Z]{3}[ ]+[0-9]{1,2}[ ]+[0-9:]{4,5})[ ]+(.*)/i";
		$i = 0;
		foreach ($list as $line)
		{
			$is_dir = $is_link = FALSE;
			if (preg_match($regexp, $line, $regs))
			{
				if (!preg_match("/^[\.]{2}/i", $regs[7]) && $regs[7]!=".")
				{
					$i++;
					if (preg_match("/^[d]/i", $regs[1]))
					{
						$is_dir = TRUE;
					}
					elseif (preg_match("/^[l]/i", $regs[1]))
					{
						$is_link = TRUE;
						list($regs[7], $target) = explode(" -> ", $regs[7]);
					}
					$files[$i] = array (
						"is_dir"	=> $is_dir,
						"name"		=> htmlentities($regs[7]),
						"perms"		=> $regs[1],
						"num"		=> $regs[2],
						"user"		=> $regs[3],
						"group"		=> $regs[4],
						"size"		=> $regs[5],
						"date"		=> $regs[6],
						"is_link"	=> $is_link,
					);
					if (isset($target))
					{
						$files[$i]['target'] = $target;
						unset ($target);
					}
				}
			}
		}
	}
	else
	{
		$regexp = "/([0-9\-]{8})[ ]+([0-9:]{5}[APM]{2})[ ]+([0-9|<DIR>]+)[ ]+(.*)/i";
		foreach ($list as $line)
		{
			$is_dir = false;
			//print $line . "<BR>\n";
			if (preg_match($regexp, $line, $regs))
			{
				if (!preg_match("/^[.]/i", $regs[4]))
				{
					if($regs[3] == "<DIR>")
					{
						$is_dir = true;
						$regs[3] = '';
					}
					$i++;
					$files[$i] = array (
						"is_dir"	=> $is_dir,
						"name"		=> htmlentities($regs[4]),
						"date"		=> $regs[1],
						"time"		=> $regs[2],
						"size"		=> $regs[3],
						"is_link"	=> 0,
						"target"	=> "",
						"num"		=> ""
					);
				}
			}
		}
	}

	if ( is_array($files)  AND count($files) > 0)
	{
		asort($files);
		//natcasesort($files);
		reset($files);
	}
	return $files;
}