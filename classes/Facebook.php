<?php

namespace Rdlv\WordPress\Networks;

use DateTime;
use Exception;
use Facebook\Exceptions\FacebookResponseException;
use Facebook\Exceptions\FacebookSDKException;
use WP_Error;

class Facebook extends NetworkApi
{
    const CODE_RETURN_ACTION = 'facebook_code_return';

    private $facebook = null;

    function __construct()
    {
        parent::__construct();
        add_action('rdlv_network_status_facebook', array($this, 'renderConnexionLink'), 10, 1);
        add_action('admin_action_'. self::CODE_RETURN_ACTION, array($this, 'codeReturn'));
        add_action('init', array($this, 'init'));

        add_filter('rdlv_network_last_facebook_posts', array($this, 'getLastPosts'), 10, 2);
    }

    public function init()
    {
        if (is_user_logged_in() && session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        add_action('wp_logout', array($this, 'endSession'));
        add_action('wp_login', array($this, 'endSession'));
    }

    public function endSession()
    {
        session_destroy();
    }

    private function getFacebook($field_name)
    {
        if ($this->facebook === null) {
            $params = $this->getParams($field_name);
            return new \Facebook\Facebook(array(
                'app_id' => $params['id'],
                'app_secret' => $params['secret'],
                'default_graph_version' => $params['version']
            ));
        }
        return $this->facebook;
    }

    private function getRaw($field_name, $limit = 8)
    {
        $token = get_option(self::PREFIX . $field_name .'_token');
        if (!$token) {
            return array();
        }

        try {
            $params = get_field($field_name, 'option');
            $fb = $this->getFacebook($field_name);
            if (!$fb) {
                return array();
            }

            $fb->setDefaultAccessToken($token);

            /** @see https://developers.facebook.com/docs/graph-api/reference/v2.8/page/feed */
            $response = $fb->get(sprintf(
                '%s/posts?fields=%s&limit=%s',
                $params['target'],
                implode(',', array(
                    'id', 'message', 'picture', 'full_picture', 'name',
                    'created_time', 'from', 'description', 'link'
                )),
                $limit
            ));
        }
        catch (Exception $e) {
            error_log('Facebook fetch error ('. $e->getMessage() .')');
            return array();
        }

        if ($response instanceof WP_Error) {
            error_log('Facebook fetch error ('. $response->get_error_message() .')');
            return array();
        }

        return $response;
    }

    public function renderConnexionLink($field)
    {
        if (empty($field['value']['id']) || empty($field['value']['secret']) || empty($field['value']['version'])) {
            echo $this->getLabel('Configuration incomplète', 'warning');
            return;
        }

        try {
            $fb = $this->getFacebook($field['_name']);

            $redirectUrl = add_query_arg(array(
                'field' => $field['_name'],
                'nonce' => wp_create_nonce($field['_name'] .'_code_return'),
                'action' => self::CODE_RETURN_ACTION
            ), get_admin_url());

            set_transient(self::PREFIX . $field['_name'] .'_back_url', get_home_url() . $_SERVER['REQUEST_URI']);

//                $url = $fb->getRedirectLoginHelper()->getLoginUrl($redirectUrl, array(
//                    'email',
//                    'user_posts',
//                    // 'manage_pages'?
//                ));
            $url = $fb->getRedirectLoginHelper()->getLoginUrl($redirectUrl);

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

    public function getLastPosts($posts, $field_name)
    {
        $expiration = $this->getExpiration($field_name);
        $cached = get_transient(self::PREFIX . $field_name . '_data');
        if ($expiration && $cached) {
            return $cached;
        }

        $response = $this->getRaw($field_name);

        if (!$response) {
            return array();
        }

        $payload = $response->getDecodedBody();

        if (!isset($payload['data']) || count($payload['data']) < 1) {
            return array();
        }

        $posts = array_filter(array_map(function ($item) {
            if (isset($item['full_picture']) && isset($item['message']) && isset($item['link'])) {
                return array(
                    'thumb' => $item['full_picture'],
                    'caption' => $item['message'],
                    'network' => 'facebook',
                    'url' => $item['link'],
                    'date' => (int)DateTime::createFromFormat(DateTime::ISO8601, $item['created_time'])->format('U')
                );
            }
            return false;
        }, $payload['data']));

        usort($posts, function ($a, $b) {
            return $b['date'] - $a['date'];
        });

        set_transient(
            self::PREFIX . $field_name .'_data',
            $posts,
            $expiration
        );

        return $posts;
    }

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

        $fb = $this->getFacebook($field_name);

        try {
            $accessToken = $fb->getRedirectLoginHelper()->getAccessToken();
        }
        catch (FacebookResponseException $e) {
            error_log('Facebook fetch error on token request (Graph error: '. $e->getMessage() .')');
            $this->addNotice('La connection a Facebook a échoué');
            header($location);
            exit;
        }
        catch (FacebookSDKException $e) {
            error_log('Facebook fetch error on token request (SDK error: '. $e->getMessage() .')');
            $this->addNotice('La connection a Facebook a échoué');
            header($location);
            exit;
        }

        if (!isset($accessToken)) {
            error_log('Facebook fetch error on token request (no token found is response)');
            $this->addNotice('La connection a Facebook a échoué');
            header($location);
            exit;
        }

        update_option(
            self::PREFIX . $field_name .'_token',
            $accessToken->getValue(),
            false
        );

        $field = get_field_object($field_name, 'option');
        $this->addNotice('La connection '. $field['label'] .' est correctement établie', 'success');

        header($location);
        exit;
    }
}