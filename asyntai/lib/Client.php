<?php
/**
 * Talks to Asyntai.
 *
 * Everything goes to https://asyntai.com/whmcs/... and carries the
 * installation token. There is no API key in this module, by design: the token
 * only works on these endpoints, so it cannot be used to read an Asyntai
 * account.
 *
 * @package Asyntai
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

class Asyntai_Client
{
    const BASE = 'https://asyntai.com';
    const TIMEOUT = 30;

    /**
     * The installation token, created once and kept in the module's own table.
     */
    public static function token()
    {
        try {
            $row = Capsule::table('mod_asyntai')->where('setting', 'token')->first();
            if ($row && !empty($row->value)) {
                return $row->value;
            }
        } catch (Exception $e) {
            // Table missing means the module was never activated properly.
            return '';
        }

        $token = self::randomToken();
        try {
            Capsule::table('mod_asyntai')->insert(array(
                'setting' => 'token',
                'value' => $token,
            ));
        } catch (Exception $e) {
            return '';
        }

        return $token;
    }

    /**
     * 40 hexadecimal characters from the strongest source this PHP has.
     *
     * random_bytes exists from PHP 7. openssl_random_pseudo_bytes is the
     * fallback for the older servers still running WHMCS, and mt_rand is the
     * last resort so activation never fails outright.
     */
    private static function randomToken()
    {
        if (function_exists('random_bytes')) {
            try {
                return bin2hex(random_bytes(20));
            } catch (Exception $e) {
                // fall through
            }
        }
        if (function_exists('openssl_random_pseudo_bytes')) {
            $bytes = openssl_random_pseudo_bytes(20, $strong);
            if ($bytes !== false) {
                return bin2hex($bytes);
            }
        }
        $out = '';
        for ($i = 0; $i < 40; $i++) {
            $out .= dechex(mt_rand(0, 15));
        }
        return $out;
    }

    /**
     * Ask Asyntai whether this installation is connected, and get the widget id.
     *
     * The answer is cached for a minute. Every client area page render asks for
     * the widget id, and an outbound HTTPS call per page view would slow the
     * whole client area down.
     */
    public static function hello($useCache = true)
    {
        if ($useCache) {
            $cached = self::readCache();
            if ($cached !== null) {
                return array('ok' => true, 'data' => $cached);
            }
        }

        $whmcsVersion = '';
        if (defined('WHMCS_VERSION')) {
            $whmcsVersion = WHMCS_VERSION;
        }

        $result = self::post('/whmcs/hello/', array(
            'token' => self::token(),
            'system_url' => self::systemUrl(),
            'whmcs_version' => $whmcsVersion,
            'module_version' => '1.0.1',
        ));

        if ($result['ok']) {
            self::writeCache($result['data']);
        }

        return $result;
    }

    /**
     * Send articles to the Asyntai knowledge base.
     */
    public static function sync($articles)
    {
        return self::post('/whmcs/sync/', array(
            'token' => self::token(),
            'articles' => $articles,
        ));
    }

    /**
     * POST JSON and decode the answer.
     *
     * Returns array('ok' => bool, 'data' => array, 'error' => string). It never
     * throws: a failure in this module must never break a client area page.
     */
    private static function post($path, $payload)
    {
        $url = self::BASE . $path;
        $body = json_encode($payload);

        if (!function_exists('curl_init')) {
            return array('ok' => false, 'data' => array(),
                         'error' => 'PHP cURL is not available on this server.');
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: Asyntai-WHMCS/1.0',
        ));

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            return array('ok' => false, 'data' => array(),
                         'error' => $error ?: 'The request failed.');
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            return array('ok' => false, 'data' => array(),
                         'error' => 'Asyntai returned an unreadable response (HTTP ' . $status . ').');
        }

        if ($status < 200 || $status >= 300) {
            $message = isset($data['error']) ? $data['error'] : ('HTTP ' . $status);
            return array('ok' => false, 'data' => $data, 'error' => $message);
        }

        return array('ok' => true, 'data' => $data, 'error' => '');
    }

    /**
     * The WHMCS system URL, for display in our support screens.
     */
    private static function systemUrl()
    {
        try {
            $row = Capsule::table('tblconfiguration')->where('setting', 'SystemURL')->first();
            return $row ? $row->value : '';
        } catch (Exception $e) {
            return '';
        }
    }

    // ---------------------------------------------------------------------
    // Cache
    // ---------------------------------------------------------------------

    const CACHE_SECONDS = 60;

    public static function clearCache()
    {
        try {
            Capsule::table('mod_asyntai')->where('setting', 'cache')->delete();
        } catch (Exception $e) {
            // nothing to do
        }
    }

    private static function readCache()
    {
        try {
            $row = Capsule::table('mod_asyntai')->where('setting', 'cache')->first();
            if (!$row) {
                return null;
            }
            $cached = json_decode($row->value, true);
            if (!is_array($cached) || !isset($cached['at'], $cached['data'])) {
                return null;
            }
            if ((time() - (int) $cached['at']) > self::CACHE_SECONDS) {
                return null;
            }
            return $cached['data'];
        } catch (Exception $e) {
            return null;
        }
    }

    private static function writeCache($data)
    {
        $value = json_encode(array('at' => time(), 'data' => $data));
        try {
            $exists = Capsule::table('mod_asyntai')->where('setting', 'cache')->first();
            if ($exists) {
                Capsule::table('mod_asyntai')->where('setting', 'cache')->update(array('value' => $value));
            } else {
                Capsule::table('mod_asyntai')->insert(array('setting' => 'cache', 'value' => $value));
            }
        } catch (Exception $e) {
            // A cache that cannot be written is not an error worth showing.
        }
    }
}
