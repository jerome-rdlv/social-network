<?php

namespace Rdlv\WordPress\Networks;

use DateTime;
use Exception;
use TwitterAPIExchange;
use WP_Error;

class Twitter extends NetworkApi
{
    const API_URL = 'https://api.twitter.com/1.1/statuses/user_timeline.json';
    const TWEET_URL = 'https://twitter.com/%s/status/%s';

    private function getTwitter($field)
    {
        return new TwitterAPIExchange(array(
            'consumer_key' => $field['value']['id'],
            'consumer_secret' => $field['value']['secret'],
            'oauth_access_token' => $field['value']['access_token'],
            'oauth_access_token_secret' => $field['value']['access_token_secret']
        ));
    }

    private function getData($field)
    {
        try {
            $twitter = $this->getTwitter($field);

            if (!$twitter) {
                return array();
            }

            /* tweet_mode=extended needed to get media on some tweets */
            $response = $twitter
                ->setGetfield(sprintf('?count=%s&screen_name=%s&tweet_mode=extended', $field['value']['limit'], $field['value']['target']))
                ->buildOauth(self::API_URL, 'GET')
                ->performRequest();
        }
        catch (Exception $e) {
            error_log('Twitter fetch error ('. $e->getMessage() .')');
            return array();
        }

        if ($response instanceof WP_Error) {
            error_log('Twitter fetch error ('. $response->get_error_message() .')');
            return array();
        }

        return $response;
    }

    public function renderStatus($field)
    {
        if (empty($field['value']['id']) || empty($field['value']['secret'])) {
            echo $this->getLabel('Configuration incomplète', 'warning');
            return;
        }

        try {
            $response = $this->getData($field);

            echo $this->getLabel(
                $response ? 'Connecté' : 'Déconnecté',
                $response ? self::NOTIF_STATUS_SUCCESS : self::NOTIF_STATUS_ERROR
            );
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

        $data = json_decode($response, true);

        if (!$data) {
            return array();
        }

        $posts = array_filter(array_map(function ($item) use ($field) {
            if (isset($item['id']) && !empty($item['entities']['media'][0])) {
                return array(
                    'thumb' => !empty($item['entities']['media'][0]) ? $item['entities']['media'][0]['media_url_https'] : null,
                    'caption' => !empty($item['full_text']) ? $item['full_text'] : '',
                    'network' => 'twitter',
                    'url' => sprintf(self::TWEET_URL, $field['value']['target'], $item['id_str']),
                    'date' => new DateTime($item['created_at'])
                );
            }
            return false;
        }, $data));

        usort($posts, function ($a, $b) {
            return $b['date'] - $a['date'];
        });

        return $posts;
    }
}