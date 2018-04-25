<?php

namespace Rdlv\WordPress\Networks;

use DateInterval;
use Exception;
use ReflectionClass;

abstract class NetworkApi
{
    // static

    const TRANSIENT_NOTICE_KEY = 'rdlv_network_connection_notice';
    const TRANSIENT_ERROR_KEY = 'rdlv_network_connection_error';
    const PREFIX = 'rdlv_network_';
    const OPT_FORMAT = self::PREFIX .'%s_%s';

    const ACTION_CLEAR_CACHE = 'clear-cache';

    const NOTIF_STATUS_SUCCESS = 'success';
    const NOTIF_STATUS_ERROR = 'error';

    const CALLBACK_ACTION = self::PREFIX .'callback';
    const CALLBACK_KEY_NONCE = '_nonce';
    const CALLBACK_NONCE_FORMAT = self::PREFIX .'nonce_%s';
    const CALLBACK_KEY_FIELD = '_field';
    const CALLBACK_CTX_FIELD = '_ctx';
    const CALLBACK_KEY_ACTION = 'action';

    private static $initialized = false;

    const KEY_BACK_URL = 'back_url';

    public static function init()
    {
        if (!self::$initialized) {
            $class = get_class() .'::';
            add_filter('acf/format_value/type=social_connection', $class .'cbFormatValue', 10, 3);
            add_action('rdlv_network_status', $class .'cbRenderStatus', 10, 2);
            add_action('wp_logout', $class .'cbEndSession');
            add_action('wp_login', $class .'cbEndSession');
            add_action('admin_notices', $class .'cbAdminNotice');
            add_action('admin_action_' . self::CALLBACK_ACTION, $class .'cbCallback');
            add_action('init', $class .'cbInit');
        }
    }

    private static $networksClasses = array(
        'facebook' => 'Facebook',
        'instagram' => 'Instagram',
        'twitter' => 'Twitter',
        'linkedin' => 'LinkedIn',
        'youtube' => 'Youtube',
        'pinterest' => 'Pinterest',
    );

    /** @var array NetworkApi[] */
    private static $networks = array();


    /**
     * @param $network
     * @return NetworkApi
     */
    private static function getNetwork($network)
    {
        if (!array_key_exists($network, self::$networks)) {
            if (!array_key_exists($network, self::$networksClasses)) {
                throw new Exception('Network '. $network .' is unknown');
            }
            else {
                $class = 'Rdlv\\WordPress\\Networks\\' . self::$networksClasses[$network];
                self::$networks[$network] = new $class();
            }
        }
        return self::$networks[$network];
    }

    public static function cbInit()
    {
        if (is_user_logged_in() && session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    public static function cbFormatValue($params, $post_id, $field)
    {
        $network = self::getNetwork($field['network']);
        $network->populate($field, $post_id, $params);

        $expiration = $network->getExpiration($field);
        $cached = $network->getTransient($field, 'data');
        if ($expiration && $cached) {
            return $cached;
        }

        $formatted = $network->formatValue($field);

        if ($expiration) {
            $network->saveTransient($field, 'data', $formatted, $expiration);
        }

        return $formatted;
    }

    public static function cbRenderStatus($field)
    {
        $network = self::getNetwork($field['network']);
        $network->populate($field);
        $network->renderStatus($field);
        $network->printErrors();

        // add clear cache button
        $network->saveTransient($field, self::KEY_BACK_URL, get_home_url() . $_SERVER['REQUEST_URI']);
        $url = $network->getCallbackUrl($field, array(
            'apiaction' => self::ACTION_CLEAR_CACHE
        ));
        echo $network->getLinkButton($url, 'Vider le cache', '');
    }

    public static function cbEndSession()
    {
        if (session_id()) {
            session_destroy();
        }
    }

    public static function cbAdminNotice()
    {
        $notices = get_transient(self::TRANSIENT_NOTICE_KEY);
        if ($notices) {
            foreach ($notices as $key => $notice) {
                echo '<div class="notice notice-' . $notice['status'] . ' is-dismissible" >';
                echo '<p>' . $notice['message'] . '</p>';
                echo '</div>';
            }
            set_transient(self::TRANSIENT_NOTICE_KEY, array());
        }
    }

    public static function cbCallback()
    {
        if (empty($_REQUEST[self::CALLBACK_KEY_FIELD])) {
            exit;
        }

        $field = acf_get_field($_REQUEST[self::CALLBACK_KEY_FIELD]);
        $network = self::getNetwork($field['network']);
        $network->populate($field, $_REQUEST[self::CALLBACK_CTX_FIELD]);

        if (wp_verify_nonce($_REQUEST[self::CALLBACK_KEY_NONCE], $network->getNonceAction($field)) === false) {
            exit;
        }

        if (!empty($_REQUEST['apiaction'])) {
            switch ($_REQUEST['apiaction']) {
                case self::ACTION_CLEAR_CACHE:
                    delete_transient($network->getOptKey($field, 'data'));
                    $network->addNotice('Le cache a bien été vidé', self::NOTIF_STATUS_SUCCESS);
                    $network->redirectBack($field);
                    break;
            }
        }
        else {
            $network->callback($field);
        }
    }



    // instance

    public function callback($field) {}
    abstract public function renderStatus($field);
    abstract public function formatValue($field);
    
    private function populate(&$field, $ctx = null, $value = null)
    {
        if ($ctx === null) {
            $ctx = acf_get_valid_post_id();
        }
        if ($ctx === null) {
            $ctx = 'options';
        }
        $field['ctx'] = $ctx;
        $field['ctx_key'] = $field['key'] .'_'. $ctx;

        if (empty($field['value'])) {
            if ($value !== null) {
                $field['value'] = $value;
            }
            else {
                $field['value'] = get_field($field['key'], $ctx, false);
            }
        }
    }

    protected function getNonceAction($field)
    {
        return sprintf(self::CALLBACK_NONCE_FORMAT, $field['ctx_key']);
    }

    protected function getExpiration($field)
    {
        $minutes = (int)$field['value']['cache'];
        if ($minutes) {
            return $minutes * 60;
        }
        return 0;
    }

    protected function redirectBack($field)
    {
        header('Location: '. $this->getTransient($field, self::KEY_BACK_URL));
        exit;
    }

    protected function getCallbackUrl($field, $params = array())
    {
        $params = array_merge($params, array(
            self::CALLBACK_KEY_NONCE => wp_create_nonce($this->getNonceAction($field)),
            self::CALLBACK_KEY_FIELD => $field['key'],
            self::CALLBACK_CTX_FIELD => $field['ctx'],
            self::CALLBACK_KEY_ACTION => self::CALLBACK_ACTION
        ));

        return add_query_arg($params, get_admin_url());
    }

    protected function addNotice($message, $status = 'error')
    {
        $notices = get_transient(self::TRANSIENT_NOTICE_KEY);
        if (!$notices) {
            $notices = array();
        }
        $notices[] = array(
            'status' => $status,
            'message' => $message
        );
        set_transient(self::TRANSIENT_NOTICE_KEY, $notices);
    }

    protected function addError($error)
    {
        $class = get_class($this);
        error_log($class . ' error: ' . $error);
        
        $key = self::TRANSIENT_ERROR_KEY .'_'. md5($class);
        $errors = get_transient($key);
        if (!$errors) {
            $errors = array();
        }
        $errors[] = $error;
        set_transient($key, $errors);
    }

    protected function printErrors()
    {
        $class = get_class($this);
        $key = self::TRANSIENT_ERROR_KEY .'_'. md5($class);
        $errors = get_transient($key);
        if ($errors) {
            echo '<ul class="errors">';
            foreach ($errors as $error) {
                echo '<li class="notice notice-error">'. $error .'</li>';
            }
            echo '</ul>';
            
            set_transient($key, array());
        }
    }

    protected function getLabel($text, $status = self::NOTIF_STATUS_ERROR)
    {
        return sprintf(
            '<span class="notice notice-%s" style="padding:3px 15px;">%s</span>',
            $status,
            $text
        );
    }

    protected function getLinkButton($url, $label = 'Reconnecter', $class = 'button')
    {
        return sprintf(
            ' <a href="%s" class="button button-small %s" style="margin-left:.5em;">%s</a>',
            $url,
            $class,
            $label
        );
    }
    
    protected function sort(&$posts)
    {
        usort($posts, function ($a, $b) {
            if (!$a instanceof \DateTime || !$b instanceof \DateTime) {
                return 0;
            }
            /** @var DateInterval $diff */
            $diff = $a['date']->diff($b['date']);
            return $diff->days * ($diff->invert ? -1 : 1);
        });
    }

    private function getOptKey($field, $key)
    {
        return sprintf(self::OPT_FORMAT, $field['ctx_key'], $key);
    }

    protected function getTransient($field, $key)
    {
        return get_transient($this->getOptKey($field, $key));
    }

    protected function saveTransient($field, $key, $value, $expiration = 0)
    {
        return set_transient($this->getOptKey($field, $key), $value, $expiration);
    }

    protected function getOption($field, $key)
    {
        return get_option($this->getOptKey($field, $key));
    }

    protected function saveOption($field, $key, $value, $autoload = false)
    {
        return update_option($this->getOptKey($field, $key), $value, $autoload);
    }
}