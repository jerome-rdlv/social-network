<?php

namespace Rdlv\WordPress\Networks;

use DateTime;
use Exception;
use Instagram\Auth;
use Instagram\Media;
use WP_Error;

class Instagram extends NetworkApi
{
    const AUTH_URL = 'https://api.instagram.com/oauth/authorize/?client_id=%s&redirect_uri=%s&response_type=code&scope=%s';

    public function renderStatus($field)
    {
        if (empty($field['value']['target'])) {
            echo $this->getLabel('Configuration incomplète', 'warning');
            return;
        }

        try {
//            $callbackUrl = $this->getCallbackUrl($field);

//            $this->saveTransient($field, 'redirect_url', $callbackUrl);
//            $this->saveTransient($field, 'back_url', get_home_url() . $_SERVER['REQUEST_URI']);

//            $params = $this->getParams($field['key']);

//            $url = sprintf(
//                self::AUTH_URL,
//                $params['id'],
//                urlencode($callbackUrl),
//                implode('+', array('public_content'))
//            );

            $response = $this->getData($field);

            echo $this->getLabel(
                $response ? 'Connecté' : 'Déconnecté',
                $response ? self::NOTIF_STATUS_SUCCESS : 'error'
            );
//            echo $this->getConnectionLink($url);
        }
        catch (Exception $e) {
            return;
        }
    }

    public function callback($field)
    {
        $auth = new Auth(array(
            'client_id' => $field['value']['id'],
            'client_secret' => $field['value']['secret'],
            'redirect_uri' => $this->getTransient($field, 'redirect_url'),
            'scope' => 'public_content'
        ));

        try {
            $accessToken = $auth->getAccessToken($_GET['code']);
            $this->saveOption($field, 'token', $accessToken);
            $this->addNotice('La connection '. $field['label'] .' est correctement établie', self::NOTIF_STATUS_SUCCESS);
            $this->redirectBack($field);
        }
        catch (Exception $e) {
            error_log('Instagram fetch error on token request ('. $e->getMessage() .')');
            $this->addNotice('La connection a Instagram a échoué');
            $this->redirectBack($field);
        }

        if (!isset($accessToken)) {
            error_log('Instagram fetch error on token request (no token found is response)');
            $this->addNotice('La connection a Instagram a échouée');
            $this->redirectBack($field);
        }
    }

    function getData($field)
    {
        if (empty($field['value']['target'])) {
            return array();
        }

        $remote = wp_remote_get('https://www.instagram.com/'. $field['value']['target'] .'/');
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

        return array_slice(
            $payload['entry_data']['ProfilePage'][0]['user']['media']['nodes'],
            0,
            $field['value']['limit']
        );
    }


    public function formatValue($field)
    {
        $nodes = $this->getData($field);

        $posts = array_map(function ($item) {
            return array(
                'thumb' => $item['thumbnail_src'],
                'caption' => $item['caption'],
                'network' => 'instagram',
                'url' => 'https://instagram.com/p/'. $item['code'],
                'date' => DateTime::createFromFormat('U', (int)$item['date'])
            );
        }, $nodes);

        $this->sort($posts);

        return $posts;
    }
}