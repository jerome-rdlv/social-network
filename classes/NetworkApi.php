<?php

namespace Rdlv\WordPress\Networks;

abstract class NetworkApi
{
    const TRANSIENT_KEY = 'rdlv_network_connection_notice';
    const PREFIX = 'rdlv_network_';

    protected function __construct()
    {
        add_action('admin_notices', array($this, 'adminNotice'));
    }

    protected function getParams($field_name)
    {
        return get_field($field_name, 'option');
    }

    protected function getExpiration($field_name)
    {
        $params = $this->getParams($field_name);
        $minutes = (int)$params['cache'];
        if ($minutes) {
            return $minutes * 60;
        }
        return 0;

    }

    protected function addNotice($message, $status = 'error')
    {
        $notices = get_transient(self::TRANSIENT_KEY);
        if (!$notices) {
            $notices = array();
        }
        $notices[] = array(
            'status' => $status,
            'message' => $message
        );
        set_transient(self::TRANSIENT_KEY, $notices);
    }

    public function adminNotice()
    {
        $notices = get_transient(self::TRANSIENT_KEY);
        if ($notices) {
            foreach ($notices as $key => $notice) {
                echo '<div class="notice notice-' . $notice['status'] . ' is-dismissible" >';
                echo '<p>' . $notice['message'] . '</p>';
                echo '</div>';
            }
            set_transient(self::TRANSIENT_KEY, array());
        }
    }

    protected function getLabel($text, $status = 'error')
    {
        return sprintf(
            '<span class="notice notice-%s" style="padding:3px 15px;">%s</span>',
            $status,
            $text
        );
    }

    protected function getConnectionLink($url, $label = 'Reconnecter')
    {
        return sprintf(
            ' <a href="%s" class="button button-primary button-small" style="margin-left:.5em;">%s</a>',
            $url,
            $label
        );
    }
}