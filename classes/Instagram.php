<?php

namespace Rdlv\WordPress\Networks;

use Exception;
use Instagram\Auth;
use Instagram\Media;
use WP_Error;

class Instagram extends NetworkApi
{
    const CODE_RETURN_ACTION = 'instagram_code_return';

    const AUTH_URL = 'https://api.instagram.com/oauth/authorize/?client_id=%s&redirect_uri=%s&response_type=code&scope=%s';

    function __construct()
    {
        parent::__construct();
        add_action('rdlv_network_status_instagram', array($this, 'renderConnexionLink'), 10, 1);
        add_action('admin_action_'. self::CODE_RETURN_ACTION, array($this, 'codeReturn'));

        add_filter('rdlv_network_last_instagram_posts', array($this, 'getLastPosts'), 10, 2);
    }

    public function renderConnexionLink($field)
    {
        if (empty($field['value']['id']) || empty($field['value']['secret'])) {
            echo $this->getLabel('Configuration incomplète', 'warning');
            return;
        }

        try {
            $redirectUrl = add_query_arg(array(
                'field' => $field['_name'],
                'nonce' => wp_create_nonce($field['_name'] .'_code_return'),
                'action' => self::CODE_RETURN_ACTION
            ), get_admin_url());

            set_transient(self::PREFIX . $field['_name'] . '_redirect_url', $redirectUrl);
            set_transient(self::PREFIX . $field['_name'] . '_back_url', get_home_url() . $_SERVER['REQUEST_URI']);

            $params = $this->getParams($field['_name']);

            $url = sprintf(
                self::AUTH_URL,
                $params['id'],
                urlencode($redirectUrl),
                implode('+', array('public_content'))
            );

            $response = $this->getRaw($field['_name']);

            echo $this->getLabel(
                $response ? 'Connecté' : 'Déconnecté',
                $response ? 'success' : 'error'
            );
            echo $this->getConnectionLink($url);
        }
        catch (Exception $e) {
            return;
        }
    }

//    function getRaw($field_name)
//    {
//        $token = get_option(self::PREFIX . $field_name .'_token');
//        if (!$token) {
//            return false;
//        }
//
//        try {
//            $params = $this->getParams($field_name);
//            $instagram = new \Instagram\Instagram($token);
//            $user = $instagram->getUserByUsername($params['target']);
//            $response = $user->getMedia();
//        }
//        catch (Exception $e) {
//            error_log('Instagram fetch error ('. $e->getMessage() .')');
//            return false;
//        }
//        if ($response instanceof WP_Error) {
//            error_log('Instagram fetch error ('. $response->get_error_message() .')');
//            return false;
//        }
//        return $response;
//    }

//    function getLastPosts($posts, $field_name)
//    {
//        $expiration = $this->getExpiration($field_name);
//        $cached = get_transient(self::PREFIX . $field_name . '_data');
//        if ($expiration && $cached) {
//            return $cached;
//        }
//
//        $response = $this->getRaw($field_name);
//
//        if (!$response) {
//            return array();
//        }
//
//        $payload = $response->getData();
//        if (!is_array($payload) || count($payload) < 1) {
//            return array();
//        }
//
//        $posts = array_filter(array_map(function ($item) {
//            /** @var Media $item */
//            $content = $item->getData();
//            return array(
//                'thumb' => $content->images->standard_resolution->url,
//                'caption' => $content->caption->text,
//                'network' => 'instagram',
//                'url' => $content->link,
//                'date' => (int)$content->created_time
//            );
//        }, $payload));
//
//
//        usort($posts, function ($a, $b) {
//            return $b['date'] - $a['date'];
//        });
//
//        set_transient(
//            self::PREFIX . $field_name .'_data',
//            $posts,
//            $expiration
//        );
//
//        return $posts;
//    }

    public function codeReturn()
    {
        if (empty($_REQUEST['field'])) {
            exit;
        }

        $field_name = $_REQUEST['field'];

        if (wp_verify_nonce($_REQUEST['nonce'], $field_name .'_code_return') === false) {
            exit;
        }

        $location = 'Location: '. get_transient(self::PREFIX . $field_name .'_back_url');

        $params = $this->getParams($field_name);
        $auth = new Auth(array(
            'client_id' => $params['id'],
            'client_secret' => $params['secret'],
            'redirect_uri' => get_transient(self::PREFIX . $field_name . '_redirect_url'),
            'scope' => 'public_content'
        ));

        try {
            $accessToken = $auth->getAccessToken($_GET['code']);
        }
        catch (Exception $e) {
            error_log('Instagram fetch error on token request ('. $e->getMessage() .')');
            $this->addNotice('La connection a Instagram a échoué');
            header($location);
            exit;
        }

        if (!isset($accessToken)) {
            error_log('Instagram fetch error on token request (no token found is response)');
            $this->addNotice('La connection a Instagram a échouée');
            header($location);
            exit;
        }

        update_option(
            self::PREFIX . $field_name .'_token',
            $accessToken,
            false
        );

        $field = get_field_object($field_name, 'option');
        $this->addNotice('La connection '. $field['label'] .' est correctement établie', 'success');

        header($location);
        exit;
    }

    function getRaw($field_name)
    {
        $params = $this->getParams($field_name);
        if (!$params['target']) {
            return array();
        }

        $remote = wp_remote_get('https://www.instagram.com/'. $params['target'] .'/');
        if (is_wp_error($remote)) {
            error_log('Instagram fetch error ('. $remote->get_error_message() .')');
            return array();
        }

        if (wp_remote_retrieve_response_code($remote) !== 200) {
            error_log('Instagram fetch error (bad response code '. wp_remote_retrieve_response_code($remote) .')');
            return array();
        }

        if (!preg_match('/window._sharedData *= *(.*) *; *<\/script>/i', $remote['body'], $matches)) {
            error_log('Instagram fetch error (payload not found in response)');
            return array();
        }

        $payload = json_decode($matches[1], true);
        if (!isset($payload['entry_data']['ProfilePage'][0]['user']['media']['nodes'])) {
            error_log('Instagram fetch error (medias not found in payload)');
            return array();
        }

        return $payload['entry_data']['ProfilePage'][0]['user']['media']['nodes'];
    }

    public function getLastPosts($posts, $field_name)
    {
        $expiration = $this->getExpiration($field_name);
        $cached = get_transient(self::PREFIX . $field_name . '_data');
        if ($expiration && $cached) {
            return $cached;
        }

        $nodes = $this->getRaw($field_name);

        $posts = array_map(function ($item) {
            return array(
                'thumb' => $item['thumbnail_src'],
                'caption' => $item['caption'],
                'network' => 'instagram',
                'url' => 'https://instagram.com/p/'. $item['code'],
                'date' => (int)$item['date']
            );
        }, $nodes);

        usort($posts, function ($a, $b) {
            return $b['date'] - $a['date'];
        });

        set_transient(
            self::PREFIX . $field_name . '_data',
            $posts,
            $this->getExpiration($field_name)
        );

        return $posts;
    }
}