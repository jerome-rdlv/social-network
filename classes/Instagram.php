<?php

namespace Rdlv\WordPress\Networks;

use DateTime;
use Exception;
//use Instagram\Auth;
//use Instagram\Media;
//use WP_Error;

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
                $response ? self::NOTIF_STATUS_SUCCESS : self::NOTIF_STATUS_ERROR
            );
//            echo $this->getConnectionLink($url);
        }
        catch (Exception $e) {
            return;
        }
    }

    public function callback($field)
    {
//        $auth = new Auth(array(
//            'client_id' => $field['value']['id'],
//            'client_secret' => $field['value']['secret'],
//            'redirect_uri' => $this->getTransient($field, 'redirect_url'),
//            'scope' => 'public_content'
//        ));
//
//        try {
//            $accessToken = $auth->getAccessToken($_GET['code']);
//            $this->saveOption($field, 'token', $accessToken);
//            $this->addNotice('La connection '. $field['label'] .' est correctement établie', self::NOTIF_STATUS_SUCCESS);
//            $this->redirectBack($field);
//        }
//        catch (Exception $e) {
//            $this->addError('fetch error on token request ('. $e->getMessage() .')');
////            error_log('Instagram fetch error on token request ('. $e->getMessage() .')');
//            $this->addNotice('La connection a Instagram a échoué');
//            $this->redirectBack($field);
//        }
//
//        if (!isset($accessToken)) {
//            error_log('Instagram fetch error on token request (no token found is response)');
//            $this->addNotice('La connection a Instagram a échouée');
//            $this->redirectBack($field);
//        }
    }

    function getData($field)
    {
        if (empty($field['value']['target'])) {
            return array();
        }

        $remote = wp_remote_get('https://www.instagram.com/'. $field['value']['target'] .'/');
        if (is_wp_error($remote)) {
            $this->addError($remote->get_error_message());
            return array();
        }

        if (wp_remote_retrieve_response_code($remote) !== 200) {
            $this->addError(wp_remote_retrieve_response_code($remote));
            return array();
        }

        if (!preg_match('/window._sharedData *= *(.*) *; *<\/script>/i', $remote['body'], $matches)) {
            $this->addError('payload not found in response)');
            return array();
        }

        $payload = json_decode($matches[1], true);
//        if (!isset($payload['entry_data']['ProfilePage'][0]['user']['media']['nodes'])) {
        if (!isset($payload['entry_data']['ProfilePage'][0]['graphql']['user']['edge_owner_to_timeline_media']['edges'])) {
            $this->addError('medias not found in payload)');
            return array();
        }
        
        $nodes = $payload['entry_data']['ProfilePage'][0]['graphql']['user']['edge_owner_to_timeline_media']['edges'];
        
        if (!$nodes) {
            return [];
        }

        $nodes = array_slice(
            $nodes,
            0,
            $field['value']['limit']
        );
        
        return array_map(function ($node) {
            return $node['node'];
        }, $nodes);
    }


    public function formatValue($field)
    {
        $nodes = $this->getData($field);

        $posts = array_map(function ($item) {
            $caption = isset($item['edge_media_to_caption']['edges'][0]['node']['text']) ? $item['edge_media_to_caption']['edges'][0]['node']['text'] : '';
            return array(
                'thumb' => array(
                    'src' => $item['thumbnail_src'],
                    'width' => $item['dimensions']['width'],
                    'height' => $item['dimensions']['height'],
                    'srcset' => implode(', ', array_map(function ($thumb) {
                        return $thumb['src'] .' '. $thumb['config_width'] .'w';
                    }, $item['thumbnail_resources']))
                ),
                'caption' => $caption,
                'network' => 'instagram',
                'url' => sprintf('https://instagram.com/p/%s', $item['shortcode']),
                'date' => DateTime::createFromFormat('U', (int)$item['taken_at_timestamp'])
            );
        }, $nodes);

        $this->sort($posts);

        return $posts;
    }
}