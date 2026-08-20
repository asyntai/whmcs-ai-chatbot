<?php
/**
 * Prints the Asyntai widget on the client area.
 *
 * ClientAreaFooterOutput fires on every client area page: the home page, the
 * order forms, the service list and the ticket pages. That is exactly where a
 * hosting customer asks a question, so one hook covers all of them.
 *
 * Two rules this file follows, both learned the hard way elsewhere:
 *
 *   1. The script tag is added AFTER the page's load event. An async script
 *      tag still holds the load event open until its fetch finishes, so an
 *      unreachable Asyntai would slow down somebody else's client area.
 *   2. Nothing here may throw. A hook that raises an error inside WHMCS takes
 *      the page with it, and the page belongs to the customer, not to us.
 *
 * @package Asyntai
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/Client.php';

use WHMCS\Database\Capsule;

add_hook('ClientAreaFooterOutput', 1, function ($vars) {
    try {
        $settings = asyntai_module_settings();

        if (asyntai_setting_off($settings, 'show_on_client_area')) {
            return '';
        }

        // A signed out visitor is on the order form or the sign-in page. Some
        // hosts want the assistant there, some do not.
        $loggedIn = !empty($vars['loggedin']) || !empty($_SESSION['uid']);
        if (!$loggedIn && asyntai_setting_off($settings, 'show_to_logged_out')) {
            return '';
        }

        $status = Asyntai_Client::hello();
        if (!$status['ok'] || empty($status['data']['connected'])) {
            return '';
        }

        $widgetId = isset($status['data']['widget_id']) ? $status['data']['widget_id'] : '';
        if ($widgetId === '') {
            return '';
        }

        $idJson = json_encode((string) $widgetId);
        $srcJson = json_encode('https://widget.asyntai.com/static/js/chat-widget.js');

        return '<script>(function(){var l=function(){'
            . 'var s=document.createElement("script");'
            . 's.src=' . $srcJson . ';s.async=true;'
            . 's.setAttribute("data-asyntai-id",' . $idJson . ');'
            . 'document.head.appendChild(s)};'
            . 'if(document.readyState==="complete"){l()}'
            . 'else{window.addEventListener("load",l)}})();</script>';
    } catch (Exception $e) {
        return '';
    } catch (Throwable $e) {
        // PHP 7 and newer raise Error, not Exception, for a missing class.
        return '';
    }
});

/**
 * Read this addon's settings.
 *
 * WHMCS passes settings to asyntai_output, but not to a hook, so the hook has
 * to read them itself.
 */
function asyntai_module_settings()
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $settings = array();
    try {
        $rows = Capsule::table('tbladdonmodules')->where('module', 'asyntai')->get();
        foreach ($rows as $row) {
            $row = (array) $row;
            $settings[$row['setting']] = $row['value'];
        }
    } catch (Exception $e) {
        $settings = array();
    }

    $cache = $settings;
    return $cache;
}

/**
 * True when a yes/no setting is present and switched off.
 *
 * A missing setting counts as ON, because WHMCS stores nothing for a checkbox
 * the administrator has never touched, and both of ours default to on.
 */
function asyntai_setting_off($settings, $key)
{
    if (!array_key_exists($key, $settings)) {
        return false;
    }
    $value = $settings[$key];
    return !($value === 'on' || $value === 'yes' || $value === '1' || $value === 1);
}
