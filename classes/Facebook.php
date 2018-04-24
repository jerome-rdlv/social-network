<?php

namespace Rdlv\WordPress\Networks;

use DateInterval;
use DateTime;
use Exception;
use Facebook\Exceptions\FacebookResponseException;
use Facebook\Exceptions\FacebookSDKException;
use WP_Error;

class Facebook extends NetworkApi
{
    const DEFAULT_API_VERSION = 'v2.11';
    
    /**
     * @param $field
     * @return \Facebook\Facebook
     */
    private function getFacebook($field)
    {
        try {
            return new \Facebook\Facebook(array(
                'app_id'                => $field['value']['id'],
                'app_secret'            => $field['value']['secret'],
                'default_graph_version' => $field['value']['version'],
            ));
        } catch (FacebookSDKException $e) {
            error_log('Facebook SDK Exception: '. $e->getMessage());
            return null;
        }
    }

    private function getData($field)
    {
        $token = $this->getOption($field, 'token');
        if (!$token) {
            return array();
        }

        try {
            $fb = $this->getFacebook($field);
            if (!$fb) {
                return array();
            }

            $fb->setDefaultAccessToken($token);

            /** @see https://developers.facebook.com/docs/graph-api/reference/v2.8/page/feed */
            $response = $fb->get(sprintf(
                '%s/posts?fields=%s&limit=%s',
                $field['value']['target'],
                implode(',', array(
                    'id', 'message', 'picture', 'full_picture', 'name',
                    'created_time', 'from', 'description', 'link'
                )),
                $field['value']['limit']
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

    public function renderStatus($field)
    {
        if (empty($field['value']['id']) || empty($field['value']['secret']) || empty($field['value']['version'])) {
            echo $this->getLabel('Configuration incomplète', 'warning');
            return;
        }

        try {
            $fb = $this->getFacebook($field);

            $callbackUrl = $this->getCallbackUrl($field);

            $url = $fb->getRedirectLoginHelper()->getLoginUrl($callbackUrl);

            $response = $this->getData($field);

            echo $this->getLabel(
                $response ? 'Connecté' : 'Déconnecté',
                $response ? 'success' : 'error'
            );
            echo $this->getLinkButton($url);
        }
        catch (Exception $e) {
            return;
        }
    }

    public function formatValue($field)
    {
        $response = $this->getData($field);

        if (!$response) {
            return array();
        }

        $payload = $response->getDecodedBody();

        if (!isset($payload['data']) || count($payload['data']) < 1) {
            return array();
        }

        $posts = array_filter(array_map(function ($item) use ($field) {
            return $this->getPostData($item);
        }, $payload['data']));

        $this->sort($posts);

        return $posts;
    }

    private function getPostData($item)
    {
        if (empty($item['link']) || empty($item['created_time'])) {
            return false;
        }

        $data = array(
            'network' => 'facebook',
            'url' => $item['link'],
            'date' => DateTime::createFromFormat(DateTime::ISO8601, $item['created_time'])
        );

        // thumb
        if (!empty($item['full_picture'])) {
            $data['thumb'] = $item['full_picture'];
        }
//        elseif (!empty($item['picture'])) {
//            $data['thumb'] = $item['picture'];
//        }
//        else {
//            return false;
//        }

        // caption
        if (!empty($item['message'])) {
            $data['caption'] = $item['message'];
        }
        elseif (!empty($item['description'])) {
            $data['caption'] = $item['description'];
        }
        else {
            $data['caption'] = '';
        }

        return $data;
    }

    public function callback($field)
    {
        $fb = $this->getFacebook($field);

        try {
            $accessToken = $fb->getRedirectLoginHelper()->getAccessToken($this->getCallbackUrl($field));
            
            if (!isset($accessToken)) {
                error_log('Facebook fetch error on token request (no token found is response)');
                $this->addNotice('La connection a Facebook a échoué');
                $this->redirectBack($field);
            }
            
            $this->saveOption($field, 'token', $accessToken->getValue());
            $this->addNotice('La connection '. $field['label'] .' est correctement établie', 'success');
            $this->redirectBack($field);
        }
        catch (FacebookResponseException $e) {
            error_log('Facebook fetch error on token request (Graph error: '. $e->getMessage() .')');
            $this->addNotice('La connection a Facebook a échoué');
            $this->redirectBack($field);
        }
        catch (FacebookSDKException $e) {
            error_log('Facebook fetch error on token request (SDK error: '. $e->getMessage() .')');
            $this->addNotice('La connection a Facebook a échoué');
            $this->redirectBack($field);
        }
        catch (Exception $e) {
            error_log('Facebook fetch error on token request (SDK error: '. $e->getMessage() .')');
            $this->addNotice('La connection a Facebook a échoué');
            $this->redirectBack($field);
        }
    }
}
