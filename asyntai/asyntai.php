<?php
/**
 * Asyntai AI Chatbot for WHMCS.
 *
 * Puts an AI chat assistant on the client area, the order forms and the ticket
 * pages. The assistant answers from the host's own content, and it can read the
 * WHMCS knowledge base so the answers match what the host already wrote.
 *
 * The module holds no API key. It generates a random token when it is
 * activated, and everything it does is authorised by that token. The
 * administrator links the token to an Asyntai account once, by following a
 * connect link. So a stolen configuration file cannot be used to read anything
 * from Asyntai: the token only reaches the endpoints below.
 *
 * @package    Asyntai
 * @author     Asyntai
 * @link       https://asyntai.com/documentation/integrations/whmcs/
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/Client.php';
require_once __DIR__ . '/lib/Knowledge.php';

use WHMCS\Database\Capsule;

/**
 * Module metadata and settings shown in Setup > Addon Modules.
 */
function asyntai_config()
{
    return array(
        'name' => 'Asyntai AI Chatbot',
        'description' => 'Adds an AI chat assistant to your client area that answers '
            . 'your customers from your own content, day and night, in over 30 '
            . 'languages. It can also read your WHMCS knowledge base.',
        'author' => 'Asyntai',
        'language' => 'english',
        'version' => '1.0.1',
        'fields' => array(
            'show_on_client_area' => array(
                'FriendlyName' => 'Show on client area',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'Show the chat widget on the client area, the order '
                    . 'forms and the ticket pages.',
            ),
            'show_to_logged_out' => array(
                'FriendlyName' => 'Show to signed out visitors',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'Also show the widget to people who have not signed '
                    . 'in yet, for example on the order form.',
            ),
            'sync_knowledgebase' => array(
                'FriendlyName' => 'Send knowledge base articles',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'Send your published WHMCS knowledge base articles to '
                    . 'Asyntai, so the assistant answers from them. Private articles '
                    . 'are never sent.',
            ),
            'sync_announcements' => array(
                'FriendlyName' => 'Send announcements',
                'Type' => 'yesno',
                'Default' => '',
                'Description' => 'Also send your published announcements.',
            ),
        ),
    );
}

/**
 * Create the module's own table on activation.
 *
 * One row, holding the installation token. WHMCS keeps addon settings in its
 * own table, but a setting can be edited by hand in the admin area, and an
 * edited token would silently unlink the installation. The token belongs in a
 * place nobody types into.
 */
function asyntai_activate()
{
    try {
        if (!Capsule::schema()->hasTable('mod_asyntai')) {
            Capsule::schema()->create('mod_asyntai', function ($table) {
                $table->increments('id');
                $table->string('setting', 64)->unique();
                $table->text('value');
            });
        }
        Asyntai_Client::token();  // generates and stores it on first call

        return array(
            'status' => 'success',
            'description' => 'Asyntai is installed. Open it from the Addons menu to '
                . 'connect your Asyntai account.',
        );
    } catch (Exception $e) {
        return array(
            'status' => 'error',
            'description' => 'Could not create the Asyntai table: ' . $e->getMessage(),
        );
    }
}

/**
 * Leave the table in place on deactivation.
 *
 * Dropping it would throw away the installation token, so re-activating would
 * look like a brand new installation and the administrator would have to
 * connect again. Deactivating is usually temporary; losing the link is not.
 */
function asyntai_deactivate()
{
    return array(
        'status' => 'success',
        'description' => 'Asyntai is deactivated. The widget no longer appears. Your '
            . 'connection is kept, so activating it again needs no further setup.',
    );
}

/**
 * The admin page: connect, sync, and see what is happening.
 */
function asyntai_output($vars)
{
    $modulelink = $vars['modulelink'];
    $notice = '';

    if (isset($_POST['asyntai_action'])) {
        if ($_POST['asyntai_action'] === 'sync') {
            $notice = Asyntai_Knowledge::sync($vars);
        } elseif ($_POST['asyntai_action'] === 'refresh') {
            $notice = '<div class="infobox"><strong>Refreshed.</strong></div>';
        }
    }

    $status = Asyntai_Client::hello();
    echo $notice;

    if (!$status['ok']) {
        echo '<div class="errorbox"><strong>Cannot reach Asyntai.</strong> '
            . htmlspecialchars($status['error'], ENT_QUOTES, 'UTF-8')
            . '<br>Your server must be able to reach https://asyntai.com over HTTPS.'
            . '</div>';
        return;
    }

    if (empty($status['data']['connected'])) {
        $connect = $status['data']['connect_url'];
        echo '<div class="infobox"><h2>Connect your Asyntai account</h2>'
            . '<p>The widget stays hidden until this installation is connected. '
            . 'Creating an Asyntai account is free.</p>'
            . '<p><a class="btn btn-primary" href="' . htmlspecialchars($connect, ENT_QUOTES, 'UTF-8')
            . '" target="_blank" rel="noopener">Connect Asyntai</a></p>'
            . '<p>After connecting, come back and press Refresh.</p>'
            . '<form method="post"><input type="hidden" name="asyntai_action" value="refresh">'
            . '<input type="submit" class="btn" value="Refresh"></form>'
            . '</div>';
        return;
    }

    $data = $status['data'];
    $domain = htmlspecialchars($data['website_domain'], ENT_QUOTES, 'UTF-8');
    $widgetId = htmlspecialchars($data['widget_id'], ENT_QUOTES, 'UTF-8');
    $lastSync = $data['last_sync_at']
        ? htmlspecialchars($data['last_sync_at'], ENT_QUOTES, 'UTF-8') : 'never';
    $lastStatus = htmlspecialchars($data['last_sync_status'], ENT_QUOTES, 'UTF-8');

    echo '<div class="successbox"><strong>Connected.</strong> The assistant answers '
        . 'from <strong>' . $domain . '</strong>.</div>';

    echo '<h2>Widget</h2><table class="form"><tbody>'
        . '<tr><td class="fieldlabel">Widget id</td><td class="fieldarea">' . $widgetId . '</td></tr>'
        . '<tr><td class="fieldlabel">On client area</td><td class="fieldarea">'
        . (($vars['show_on_client_area'] === 'on') ? 'Yes' : 'No — turn it on in the module settings')
        . '</td></tr></tbody></table>';

    echo '<h2>Knowledge base</h2>';
    if (empty($data['kb_sync_enabled'])) {
        echo '<div class="infobox">Sending articles is turned off for this account.</div>';
    } else {
        $counts = Asyntai_Knowledge::counts($vars);
        echo '<table class="form"><tbody>'
            . '<tr><td class="fieldlabel">Articles ready to send</td><td class="fieldarea">'
            . (int) $counts['kb'] . ' knowledge base, ' . (int) $counts['announcements'] . ' announcements'
            . '</td></tr>'
            . '<tr><td class="fieldlabel">Last sent</td><td class="fieldarea">' . $lastSync . '</td></tr>'
            . '<tr><td class="fieldlabel">Result</td><td class="fieldarea">' . ($lastStatus ?: '-') . '</td></tr>'
            . '</tbody></table>'
            . '<form method="post"><input type="hidden" name="asyntai_action" value="sync">'
            . '<input type="submit" class="btn btn-primary" value="Send articles to Asyntai now">'
            . '</form>'
            . '<p>Only published articles are sent. Private articles stay in WHMCS.</p>';
    }

    echo '<h2>Help</h2><p>Setup guide: '
        . '<a href="https://asyntai.com/documentation/integrations/whmcs/" target="_blank" rel="noopener">'
        . 'asyntai.com/documentation/integrations/whmcs/</a><br>'
        . 'Questions: <a href="mailto:hello@asyntai.com">hello@asyntai.com</a></p>';
}
