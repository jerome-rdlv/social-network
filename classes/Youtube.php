<?php


namespace Rdlv\WordPress\Networks;

use DateTime;
use Exception;
use Google_Client;
use Google_Service_Exception;
use Google_Service_YouTube;
use Google_Service_YouTube_PlaylistItem;
use Google_Service_YouTube_Thumbnail;
use Google_Service_YouTube_ThumbnailDetails;

class Youtube extends NetworkApi
{
    const YOUTUBE_URL = 'https://www.youtube.com/watch?v=%s';
    
    private function getData($field)
    {
        try {
            $client = new Google_Client();
            $client->setDeveloperKey($field['value']['key']);
            $youtube = new Google_Service_YouTube($client);
            $response = $youtube->playlistItems->listPlaylistItems('snippet', array(
                'playlistId' => $field['value']['target'],
                'maxResults' => $field['value']['limit'],
            ));
        }
        /** @noinspection PhpRedundantCatchClauseInspection */
        catch (Google_Service_Exception $e) {
            $this->addError($e->getMessage());
            return null;
        }
        catch (Exception $e) {
            $this->addError($e->getMessage());
            return null;
        }
        
        return $response;
    }

    public function renderStatus($field)
    {
        $response = $this->getData($field);
        
        echo $this->getLabel(
            $response ? 'Connecté' : 'Déconnecté',
            $response ? self::NOTIF_STATUS_SUCCESS : self::NOTIF_STATUS_ERROR
        );
    }

    public function formatValue($field)
    {
        $response = $this->getData($field);
        
        if (!$response) {
            return array();
        }
        
        /** @var Google_Service_YouTube_PlaylistItem[] $items */
        $items = $response->getItems();
        
        $posts = array_map(function ($item) {
            /** @var Google_Service_YouTube_PlaylistItem $item */
            
            /** @var Google_Service_YouTube_ThumbnailDetails $thumbs */
            $thumbnails = $item->getSnippet()->getThumbnails();
            
            /** @var Google_Service_YouTube_Thumbnail[] $thumbs */
            $thumbs = [
                $thumbnails->getDefault(),
                $thumbnails->getMedium(),
                $thumbnails->getStandard(),
                $thumbnails->getHigh(),
                $thumbnails->getMaxres(),
            ];
            
            /** @var Google_Service_YouTube_ThumbnailDetails $thumbs */
            $thumb = $item->getSnippet()->getThumbnails()->getStandard();
            
            return array(
                'thumb' => array(
                    'src' => $thumb->getUrl(),
                    'width' => $thumb->getWidth(),
                    'height' => $thumb->getHeight(),
                    'srcset' => implode(', ', array_map(function ($thumb) {
                        /** @var Google_Service_YouTube_Thumbnail $thumb */
                        return $thumb->getUrl() .' '. $thumb->getWidth() .'w';
                    }, $thumbs))
                ),
                'caption' => $item->getSnippet()->getDescription(),
                'network' => 'youtube',
                'url' => sprintf(self::YOUTUBE_URL, $item->getSnippet()->getResourceId()->getVideoId()),
                'date' => DateTime::createFromFormat('Y-m-d\TH:i:s', substr($item->getSnippet()->getPublishedAt(), 0, 19)),
            );
        }, $items);
        
        $this->sort($posts);
        
        return $posts;
    }
}