<?php

namespace Rdlv\WordPress\Networks;

use DateTime;
use Exception;
use SimpleLinkedIn;
use SimpleLinkedInException;
use stdClass;
use WP_Error;

require_once __DIR__ .'/../simplelinkedin.class.php';

class Linkedin extends NetworkApi
{
    const MODE_CONNECT = 'connect';
    const MODE_AUTH = 'auth';

    const API_URL = '/v1/companies/%s/updates?start=0&count=%s';

    /**
     * @param $field
     * @return SimpleLinkedIn
     */
    private function getLinkedin($field)
    {
        return new SimpleLinkedIn(
            $field['value']['id'],
            $field['value']['secret'],
            $this->getCallbackUrl($field, array(
                'mode' => self::MODE_AUTH
            )),
            array(
                'rw_company_admin'
            )
        );
    }

    private function getData($field)
    {
        $token = $this->getOption($field, 'token');
        if (!$token) {
            return array();
        }

        $response = null;
        try {
            $linkedin = $this->getLinkedin($field);
            $linkedin->setTokenData($token, null, null, false);

            if ($linkedin->authorize()) {
                $response = $linkedin->fetch('GET', sprintf(self::API_URL, $field['value']['target'], $field['value']['limit']));
            }
        }
        catch (SimpleLinkedInException $e) {
            if ($e->getLastResponse()->status == 401) {
                $this->saveOption($field, 'token', null);
            }
        }
        catch (Exception $e) {
            error_log('Linkedin fetch error ('. $e->getMessage() .')');
            return array();
        }

        if ($response instanceof WP_Error) {
            error_log('Linkedin fetch error ('. $response->get_error_message() .')');
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

        $connectUrl = $this->getCallbackUrl($field, array(
            'mode' => self::MODE_CONNECT
        ));

        $response = $this->getData($field);

        echo $this->getLabel(
            $response ? 'Connecté' : 'Déconnecté',
            $response ? 'success' : 'error'
        );
        echo $this->getLinkButton($connectUrl);
    }

    private function connect($field)
    {
        $linkedin = $this->getLinkedin($field);
        $linkedin->resetToken();
        $linkedin->authorize();

        $this->redirectBack($field);
    }

    public function formatValue($field)
    {
        $response = $this->getData($field);

        if (!$response) {
            return array();
        }

        $posts = array_filter(array_map(function (StdClass $item) use ($field) {
            if (!empty($item->updateContent->companyStatusUpdate->share)) {
                $share = $item->updateContent->companyStatusUpdate->share;
                return array(
                    'thumb' => empty($share->content->submittedImageUrl) ? '' : $share->content->submittedImageUrl,
                    'caption' => empty($share->comment) ? '' : $share->comment,
                    'network' => 'linkedin',
                    'url' => empty($share->content) ? '' : $share->content->shortenedUrl,
                    'date' => (int)($share->timestamp / 1000)
                );
            }
            return false;
        }, $response->values));

        usort($posts, function ($a, $b) {
            return $b['date'] - $a['date'];
        });

        return $posts;
    }

    public function callback($field)
    {
        switch ($_REQUEST['mode']) {
            case self::MODE_CONNECT:
                $this->connect($field);
                break;
            case self::MODE_AUTH:
                $this->auth($field);
                break;
        }
    }

    private function auth($field)
    {
        $linkedin = $this->getLinkedin($field);

        try {
            $linkedin->authorize();
            $tokenData = $linkedin->getTokenData();
            $this->saveOption($field, 'token', $tokenData['access_token']);
            $this->redirectBack($field);
        }
        catch (Exception $e) {
            error_log('LinkedIn error on retreiveTokenAccess (Graph error: '. $e->getMessage() .')');
            $this->addNotice('La connection a LinkedIn a échoué');
            $this->redirectBack($field);
        }
    }
}