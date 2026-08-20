<?php
/**
 * Reads the WHMCS knowledge base and announcements, and sends them to Asyntai.
 *
 * Only published items are sent. A private knowledge base article is private
 * for a reason, and an unpublished announcement is usually a draft, so neither
 * belongs in an assistant that answers customers.
 *
 * The articles are stored in WHMCS as HTML. They are converted to plain text
 * here, because the knowledge base stores text and markup would waste the
 * character allowance and confuse retrieval.
 *
 * @package Asyntai
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

class Asyntai_Knowledge
{
    const BATCH = 100;

    /**
     * How many items are available to send, for the admin screen.
     */
    public static function counts($vars)
    {
        $counts = array('kb' => 0, 'announcements' => 0);
        try {
            $counts['kb'] = Capsule::table('tblknowledgebase')
                ->where('private', '')
                ->count();
        } catch (Exception $e) {
            $counts['kb'] = 0;
        }
        try {
            $counts['announcements'] = Capsule::table('tblannouncements')
                ->where('published', 1)
                ->count();
        } catch (Exception $e) {
            $counts['announcements'] = 0;
        }
        return $counts;
    }

    /**
     * Collect the articles and send them, in batches.
     *
     * Returns HTML for the admin screen, because that is the only place this
     * is called from.
     */
    public static function sync($vars)
    {
        $articles = array();

        if (self::enabled($vars, 'sync_knowledgebase', true)) {
            $articles = array_merge($articles, self::knowledgeBaseArticles());
        }
        if (self::enabled($vars, 'sync_announcements', false)) {
            $articles = array_merge($articles, self::announcements());
        }

        if (!$articles) {
            return '<div class="infobox"><strong>Nothing to send.</strong> '
                . 'There are no published knowledge base articles.</div>';
        }

        $added = 0;
        $updated = 0;
        $skipped = 0;
        $errors = array();

        foreach (array_chunk($articles, self::BATCH) as $batch) {
            $result = Asyntai_Client::sync($batch);
            if (!$result['ok']) {
                return '<div class="errorbox"><strong>Sending failed.</strong> '
                    . htmlspecialchars($result['error'], ENT_QUOTES, 'UTF-8') . '</div>';
            }
            $added += isset($result['data']['added']) ? (int) $result['data']['added'] : 0;
            $updated += isset($result['data']['updated']) ? (int) $result['data']['updated'] : 0;
            $skipped += isset($result['data']['skipped']) ? (int) $result['data']['skipped'] : 0;
            if (!empty($result['data']['errors'])) {
                $errors = array_merge($errors, $result['data']['errors']);
            }
        }

        Asyntai_Client::clearCache();

        $message = '<div class="successbox"><strong>Sent.</strong> '
            . $added . ' added, ' . $updated . ' updated';
        if ($skipped) {
            $message .= ', ' . $skipped . ' skipped (empty or too short)';
        }
        $message .= '.</div>';

        if ($errors) {
            $message .= '<div class="infobox">Some articles reported a problem: '
                . htmlspecialchars(implode('; ', array_slice($errors, 0, 5)), ENT_QUOTES, 'UTF-8')
                . '</div>';
        }

        return $message;
    }

    /**
     * Published knowledge base articles.
     *
     * WHMCS marks a private article by putting something in the `private`
     * column, so an empty value means public.
     */
    private static function knowledgeBaseArticles()
    {
        $out = array();
        try {
            $rows = Capsule::table('tblknowledgebase')->where('private', '')->get();
        } catch (Exception $e) {
            return $out;
        }

        foreach ($rows as $row) {
            $row = (array) $row;
            $text = self::toText(isset($row['article']) ? $row['article'] : '');
            if ($text === '') {
                continue;
            }
            $out[] = array(
                'id' => (string) $row['id'],
                'kind' => 'kb',
                'title' => isset($row['title']) ? (string) $row['title'] : ('Article ' . $row['id']),
                'content' => $text,
            );
        }
        return $out;
    }

    /**
     * Published announcements.
     */
    private static function announcements()
    {
        $out = array();
        try {
            $rows = Capsule::table('tblannouncements')->where('published', 1)->get();
        } catch (Exception $e) {
            return $out;
        }

        foreach ($rows as $row) {
            $row = (array) $row;
            $text = self::toText(isset($row['announcement']) ? $row['announcement'] : '');
            if ($text === '') {
                continue;
            }
            $out[] = array(
                'id' => (string) $row['id'],
                'kind' => 'announcement',
                'title' => isset($row['title']) ? (string) $row['title'] : ('Announcement ' . $row['id']),
                'content' => $text,
            );
        }
        return $out;
    }

    /**
     * HTML to readable plain text.
     *
     * Scripts and styles are removed first. Without that, strip_tags leaves the
     * script body behind as text and the knowledge base fills with JavaScript.
     * Block tags become line breaks so sentences do not run together.
     */
    public static function toText($html)
    {
        if (!is_string($html) || $html === '') {
            return '';
        }

        $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html);
        $text = preg_replace('#<br\s*/?>#i', "\n", $text);
        $text = preg_replace('#</(p|div|li|h[1-6]|tr)>#i', "\n", $text);
        $text = preg_replace('#<li[^>]*>#i', '- ', $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = str_replace("\xc2\xa0", ' ', $text);          // non-breaking space
        $text = preg_replace("/[ \t]+/", ' ', $text);
        $text = preg_replace("/\n\s*\n\s*\n+/", "\n\n", $text);

        return trim($text);
    }

    /**
     * Read a yes/no module setting.
     */
    private static function enabled($vars, $key, $default)
    {
        if (!isset($vars[$key])) {
            return $default;
        }
        return ($vars[$key] === 'on' || $vars[$key] === 'yes' || $vars[$key] === 1 || $vars[$key] === '1');
    }
}
