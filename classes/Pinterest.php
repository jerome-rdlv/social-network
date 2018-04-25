<?php

namespace Rdlv\WordPress\Networks;

use DateTime;
use DirkGroenen\Pinterest\Models\Pin;
use Exception;
use Facebook\Exceptions\FacebookResponseException;
use Facebook\Exceptions\FacebookSDKException;
use WP_Error;

class Pinterest extends NetworkApi
{
    /**
     * @param $field
     * @return \DirkGroenen\Pinterest\Pinterest
     */
    private function getPinterest($field)
    {
        return new \DirkGroenen\Pinterest\Pinterest(
            $field['value']['id'],
            $field['value']['secret']
        );
    }

    /**
     * @param $field
     * @return array|\DirkGroenen\Pinterest\Models\Collection|mixed
     */
    private function getData($field)
    {
        $expiration = $this->getExpiration($field);
        $cached = $this->getTransient($field, 'pdata');
        if ($expiration && $cached) {
            return $cached;
        }
        
        $token = $this->getOption($field, 'token');
        if (!$token) {
            return array();
        }

        try {
            $pinterest = $this->getPinterest($field);
            if (!$pinterest) {
                return array();
            }
            
            $pinterest->auth->setOAuthToken($token);
            
            // get board id
            $remote = wp_remote_get($field['value']['target']);
            if (is_wp_error($remote)) {
                $this->addError($remote->get_error_message());
                return array();
            }
            
            if (wp_remote_retrieve_response_code($remote) !== 200) {
                $this->addError('error getting Pinterest board page ('. wp_remote_retrieve_response_code($remote) .')');
                return array();
            }
            
            if (!preg_match('/"options": *{"board_id": *"([0-9]+)"/', $remote['body'], $matches)) {
                $this->addError('board id not found in response');
                return array();
            }
            
            $boardId = $matches[1];
            $pins = $pinterest->pins->fromBoard($boardId, array(
                'fields' => implode(',', array(
                    'id', 'image', 'link', 'media', 'metadata', 'note', 'url', 'created_at'
                )),
            ));
            
            if ($expiration) {
                $this->saveTransient($field, 'pdata', $pins, $expiration);
            }
            
            return $pins;
        }
        catch (Exception $e) {
            $this->addError($e->getMessage());
            return array();
        }
    }

    public function renderStatus($field)
    {
        if (empty($field['value']['id']) || empty($field['value']['secret']) || empty($field['value']['target'])) {
            echo $this->getLabel('Configuration incomplète', 'warning');
            return;
        }

        try {
            $pinterest = $this->getPinterest($field);

            $callbackUrl = $this->getCallbackUrl($field, array(
//                '_nonce' => '',
            ));
            
            $url = $pinterest->auth->getLoginUrl($callbackUrl, array('read_public'));

            $response = $this->getData($field);

            echo $this->getLabel(
                $response ? 'Connecté' : 'Déconnecté',
                $response ? 'success' : 'error'
            );
            echo $this->getLinkButton($url);
            
            $this->addNotice('Pinterest callback URL: <code>'. $callbackUrl .'</code>', self::NOTIF_STATUS_INFO);
        }
        catch (Exception $e) {
            return;
        }
    }

    public function formatValue($field)
    {
        $pins = $this->getData($field);

        if (!$pins) {
            return array();
        }

        $posts = array_filter(array_map(function ($item) use ($field) {
            /** @var Pin $item */
            return $this->getPostData($item);
        }, $pins->all()));

        $this->sort($posts);

        return $posts;
    }

    /**
     * @param Pin $pin
     * @return array|bool
     */
    private function getPostData($pin)
    {
        $pin = $pin->toArray();
        
        if (empty($pin['url']) || empty($pin['created_at'])) {
            return false;
        }

        $data = array(
            'network' => 'pinterest',
            'thumb' => isset($pin['image']['original']) ? array(
                'src' => $pin['image']['original']['url'],
                'width' => $pin['image']['original']['width'],
                'height' => $pin['image']['original']['height'],
            ) : null,
            'url' => $pin['url'],
            'caption' => '',
            'date' => DateTime::createFromFormat('Y-m-d\TH:i:s', $pin['created_at']),
        );

        return $data;
    }

    public function callback($field)
    {
        $pinterest = $this->getPinterest($field);

        try {
            if (!isset($_GET['code'])) {
                $this->addError('Error getting code from Pinterest');
                $this->addNotice('La connection a Pinterest a échoué');
                $this->redirectBack($field);
            }
            $token = $pinterest->auth->getOAuthToken($_GET['code']);
            
            if (!isset($token)) {
                $this->addError('Error getting accessToken from Pinterest response');
                $this->addNotice('La connection a Pinterest a échoué');
                $this->redirectBack($field);
            }
            
            $this->saveOption($field, 'token', $token->access_token);
            $this->addNotice('La connection '. $field['label'] .' est correctement établie', 'success');
            $this->redirectBack($field);
        }
        catch (Exception $e) {
            error_log('Pinterest fetch error on token request ('. $e->getMessage() .')');
            $this->addNotice('La connection a Pinterest a échoué');
            $this->redirectBack($field);
        }
    }
}
