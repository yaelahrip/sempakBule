<?php

defined('BASEPATH') OR exit('No direct script access allowed');

class Null_Session {

    public $userdata;
    protected $_driver = 'files';
    protected $_config;

    // ------------------------------------------------------------------------

    public function __construct(array $params = array()) {
        // No sessions under CLI
        if (is_cli()) {
            log_message('debug', 'Session: Initialization under CLI aborted.');
            return;
        } elseif ((bool) ini_get('session.auto_start')) {
            log_message('error', 'Session: session.auto_start is enabled in php.ini. Aborting.');
            return;
        } elseif (!empty($params['driver'])) {
            $this->_driver = $params['driver'];
            unset($params['driver']);
        } elseif ($driver = config_item('sess_driver')) {
            $this->_driver = $driver;
        }
        // Note: BC workaround
        elseif (config_item('sess_use_database')) {
            $this->_driver = 'database';
        }

        $class = $this->_null_load_classes($this->_driver);

        // Configuration ...
        $this->_configure($params);

        $class = new $class($this->_config);
        if ($class instanceof SessionHandlerInterface) {
            if (is_php('5.4')) {
                session_set_save_handler($class, TRUE);
            } else {
                session_set_save_handler(
                        array($class, 'open'), array($class, 'close'), array($class, 'read'), array($class, 'write'), array($class, 'destroy'), array($class, 'gc')
                );

                register_shutdown_function('session_write_close');
            }
        } else {
            log_message('error', "Session: Driver '" . $this->_driver . "' doesn't implement SessionHandlerInterface. Aborting.");
            return;
        }

        // Sanitize the cookie, because apparently PHP doesn't do that for userspace handlers
        if (isset($_COOKIE[$this->_config['cookie_name']]) && (
                !is_string($_COOKIE[$this->_config['cookie_name']])
                OR ! preg_match('/^[0-9a-f]{40}$/', $_COOKIE[$this->_config['cookie_name']])
                )
        ) {
            unset($_COOKIE[$this->_config['cookie_name']]);
        }

        session_start();

        // Is session ID auto-regeneration configured? (ignoring ajax requests)
        if ((empty($_SERVER['HTTP_X_REQUESTED_WITH']) OR strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') && ($regenerate_time = config_item('sess_time_to_update')) > 0
        ) {
            if (!isset($_SESSION['__null_last_regenerate'])) {
                $_SESSION['__null_last_regenerate'] = time();
            } elseif ($_SESSION['__null_last_regenerate'] < (time() - $regenerate_time)) {
                $this->sess_regenerate((bool) config_item('sess_regenerate_destroy'));
            }
        }
        // Another work-around ... PHP doesn't seem to send the session cookie
        // unless it is being currently created or regenerated
        elseif (isset($_COOKIE[$this->_config['cookie_name']]) && $_COOKIE[$this->_config['cookie_name']] === session_id()) {
            setcookie(
                    $this->_config['cookie_name'], session_id(), (empty($this->_config['cookie_lifetime']) ? 0 : time() + $this->_config['cookie_lifetime']), $this->_config['cookie_path'], $this->_config['cookie_domain'], $this->_config['cookie_secure'], TRUE
            );
        }

        $this->_null_init_vars();

        log_message('info', "Session: Class initialized using '" . $this->_driver . "' driver.");
    }

    // ------------------------------------------------------------------------

    protected function _null_load_classes($driver) {
        // PHP 5.4 compatibility
        interface_exists('SessionHandlerInterface', FALSE) OR require_once(BASEPATH . 'libraries/Session/SessionHandlerInterface.php');

        $prefix = config_item('subclass_prefix');

        if (!class_exists('Null_Session_driver', FALSE)) {
            require_once(
                    file_exists(APPPATH . 'libraries/Session/Session_driver.php') ? APPPATH . 'libraries/Session/Session_driver.php' : BASEPATH . 'libraries/Session/Session_driver.php'
                    );

            if (file_exists($file_path = APPPATH . 'libraries/Session/' . $prefix . 'Session_driver.php')) {
                require_once($file_path);
            }
        }

        $class = 'Session_' . $driver . '_driver';

        // Allow custom drivers without the Null_ or MY_ prefix
        if (!class_exists($class, FALSE) && file_exists($file_path = APPPATH . 'libraries/Session/drivers/' . $class . '.php')) {
            require_once($file_path);
            if (class_exists($class, FALSE)) {
                return $class;
            }
        }

        if (!class_exists('Null_' . $class, FALSE)) {
            if (file_exists($file_path = APPPATH . 'libraries/Session/drivers/' . $class . '.php') OR file_exists($file_path = BASEPATH . 'libraries/Session/drivers/' . $class . '.php')) {
                require_once($file_path);
            }

            if (!class_exists('Null_' . $class, FALSE) && !class_exists($class, FALSE)) {
                throw new UnexpectedValueException("Session: Configured driver '" . $driver . "' was not found. Aborting.");
            }
        }

        if (!class_exists($prefix . $class) && file_exists($file_path = APPPATH . 'libraries/Session/drivers/' . $prefix . $class . '.php')) {
            require_once($file_path);
            if (class_exists($prefix . $class, FALSE)) {
                return $prefix . $class;
            } else {
                log_message('debug', 'Session: ' . $prefix . $class . ".php found but it doesn't declare class " . $prefix . $class . '.');
            }
        }

        return 'Null_' . $class;
    }

    // ------------------------------------------------------------------------

    protected function _configure(&$params) {
        $expiration = config_item('sess_expiration');

        if (isset($params['cookie_lifetime'])) {
            $params['cookie_lifetime'] = (int) $params['cookie_lifetime'];
        } else {
            $params['cookie_lifetime'] = (!isset($expiration) && config_item('sess_expire_on_close')) ? 0 : (int) $expiration;
        }

        isset($params['cookie_name']) OR $params['cookie_name'] = config_item('sess_cookie_name');
        if (empty($params['cookie_name'])) {
            $params['cookie_name'] = ini_get('session.name');
        } else {
            ini_set('session.name', $params['cookie_name']);
        }

        isset($params['cookie_path']) OR $params['cookie_path'] = config_item('cookie_path');
        isset($params['cookie_domain']) OR $params['cookie_domain'] = config_item('cookie_domain');
        isset($params['cookie_secure']) OR $params['cookie_secure'] = (bool) config_item('cookie_secure');

        session_set_cookie_params(
                $params['cookie_lifetime'], $params['cookie_path'], $params['cookie_domain'], $params['cookie_secure'], TRUE // HttpOnly; Yes, this is intentional and not configurable for security reasons
        );

        if (empty($expiration)) {
            $params['expiration'] = (int) ini_get('session.gc_maxlifetime');
        } else {
            $params['expiration'] = (int) $expiration;
            ini_set('session.gc_maxlifetime', $expiration);
        }

        $params['match_ip'] = (bool) (isset($params['match_ip']) ? $params['match_ip'] : config_item('sess_match_ip'));

        isset($params['save_path']) OR $params['save_path'] = config_item('sess_save_path');

        $this->_config = $params;

        // Security is king
        ini_set('session.use_trans_sid', 0);
        ini_set('session.use_strict_mode', 1);
        ini_set('session.use_cookies', 1);
        ini_set('session.use_only_cookies', 1);
        ini_set('session.hash_function', 1);
        ini_set('session.hash_bits_per_character', 4);
    }

    // ------------------------------------------------------------------------

    protected function _null_init_vars() {
        if (!empty($_SESSION['__null_vars'])) {
            $current_time = time();

            foreach ($_SESSION['__null_vars'] as $key => &$value) {
                if ($value === 'new') {
                    $_SESSION['__null_vars'][$key] = 'old';
                }
                // Hacky, but 'old' will (implicitly) always be less than time() ;)
                // DO NOT move this above the 'new' check!
                elseif ($value < $current_time) {
                    unset($_SESSION[$key], $_SESSION['__null_vars'][$key]);
                }
            }

            if (empty($_SESSION['__null_vars'])) {
                unset($_SESSION['__null_vars']);
            }
        }

        $this->userdata = & $_SESSION;
    }

    // ------------------------------------------------------------------------

    public function mark_as_flash($key) {
        if (is_array($key)) {
            for ($i = 0, $c = count($key); $i < $c; $i++) {
                if (!isset($_SESSION[$key[$i]])) {
                    return FALSE;
                }
            }

            $new = array_fill_keys($key, 'new');

            $_SESSION['__null_vars'] = isset($_SESSION['__null_vars']) ? array_merge($_SESSION['__null_vars'], $new) : $new;

            return TRUE;
        }

        if (!isset($_SESSION[$key])) {
            return FALSE;
        }

        $_SESSION['__null_vars'][$key] = 'new';
        return TRUE;
    }

    // ------------------------------------------------------------------------

    public function get_flash_keys() {
        if (!isset($_SESSION['__null_vars'])) {
            return array();
        }

        $keys = array();
        foreach (array_keys($_SESSION['__null_vars']) as $key) {
            is_int($_SESSION['__null_vars'][$key]) OR $keys[] = $key;
        }

        return $keys;
    }

    // ------------------------------------------------------------------------

    public function unmark_flash($key) {
        if (empty($_SESSION['__null_vars'])) {
            return;
        }

        is_array($key) OR $key = array($key);

        foreach ($key as $k) {
            if (isset($_SESSION['__null_vars'][$k]) && !is_int($_SESSION['__null_vars'][$k])) {
                unset($_SESSION['__null_vars'][$k]);
            }
        }

        if (empty($_SESSION['__null_vars'])) {
            unset($_SESSION['__null_vars']);
        }
    }

    // ------------------------------------------------------------------------

    public function mark_as_temp($key, $ttl = 300) {
        $ttl += time();

        if (is_array($key)) {
            $temp = array();

            foreach ($key as $k => $v) {
                // Do we have a key => ttl pair, or just a key?
                if (is_int($k)) {
                    $k = $v;
                    $v = $ttl;
                } else {
                    $v += time();
                }

                if (!isset($_SESSION[$k])) {
                    return FALSE;
                }

                $temp[$k] = $v;
            }

            $_SESSION['__null_vars'] = isset($_SESSION['__null_vars']) ? array_merge($_SESSION['__null_vars'], $temp) : $temp;

            return TRUE;
        }

        if (!isset($_SESSION[$key])) {
            return FALSE;
        }

        $_SESSION['__null_vars'][$key] = $ttl;
        return TRUE;
    }

    // ------------------------------------------------------------------------

    public function get_temp_keys() {
        if (!isset($_SESSION['__null_vars'])) {
            return array();
        }

        $keys = array();
        foreach (array_keys($_SESSION['__null_vars']) as $key) {
            is_int($_SESSION['__null_vars'][$key]) && $keys[] = $key;
        }

        return $keys;
    }

    // ------------------------------------------------------------------------

    public function unmark_temp($key) {
        if (empty($_SESSION['__null_vars'])) {
            return;
        }

        is_array($key) OR $key = array($key);

        foreach ($key as $k) {
            if (isset($_SESSION['__null_vars'][$k]) && is_int($_SESSION['__null_vars'][$k])) {
                unset($_SESSION['__null_vars'][$k]);
            }
        }

        if (empty($_SESSION['__null_vars'])) {
            unset($_SESSION['__null_vars']);
        }
    }

    // ------------------------------------------------------------------------

    public function __get($key) {
        // Note: Keep this order the same, just in case somebody wants to
        //       use 'session_id' as a session data key, for whatever reason
        if (isset($_SESSION[$key])) {
            return $_SESSION[$key];
        } elseif ($key === 'session_id') {
            return session_id();
        }

        return NULL;
    }

    // ------------------------------------------------------------------------

    public function __set($key, $value) {
        $_SESSION[$key] = $value;
    }

    // ------------------------------------------------------------------------

    public function sess_destroy() {
        session_destroy();
    }

    // ------------------------------------------------------------------------

    public function sess_regenerate($destroy = FALSE) {
        $_SESSION['__null_last_regenerate'] = time();
        session_regenerate_id($destroy);
    }

    // ------------------------------------------------------------------------

    public function &get_userdata() {
        return $_SESSION;
    }

    // ------------------------------------------------------------------------

    public function userdata($key = NULL) {
        if (isset($key)) {
            return isset($_SESSION[$key]) ? $_SESSION[$key] : NULL;
        } elseif (empty($_SESSION)) {
            return array();
        }

        $userdata = array();
        $_exclude = array_merge(
                array('__null_vars'), $this->get_flash_keys(), $this->get_temp_keys()
        );

        foreach (array_keys($_SESSION) as $key) {
            if (!in_array($key, $_exclude, TRUE)) {
                $userdata[$key] = $_SESSION[$key];
            }
        }

        return $userdata;
    }

    // ------------------------------------------------------------------------

    public function set_userdata($data, $value = NULL) {
        if (is_array($data)) {
            foreach ($data as $key => &$value) {
                $_SESSION[$key] = $value;
            }

            return;
        }

        $_SESSION[$data] = $value;
    }

    // ------------------------------------------------------------------------

    public function unset_userdata($key) {
        if (is_array($key)) {
            foreach ($key as $k) {
                unset($_SESSION[$k]);
            }

            return;
        }

        unset($_SESSION[$key]);
    }

    // ------------------------------------------------------------------------

    public function all_userdata() {
        return $this->userdata();
    }

    // ------------------------------------------------------------------------

    public function has_userdata($key) {
        return isset($_SESSION[$key]);
    }

    // ------------------------------------------------------------------------

    public function flashdata($key = NULL) {
        if (isset($key)) {
            return (isset($_SESSION['__null_vars'], $_SESSION['__null_vars'][$key], $_SESSION[$key]) && !is_int($_SESSION['__null_vars'][$key])) ? $_SESSION[$key] : NULL;
        }

        $flashdata = array();

        if (!empty($_SESSION['__null_vars'])) {
            foreach ($_SESSION['__null_vars'] as $key => &$value) {
                is_int($value) OR $flashdata[$key] = $_SESSION[$key];
            }
        }

        return $flashdata;
    }

    // ------------------------------------------------------------------------

    public function set_flashdata($data, $value = NULL) {
        $this->set_userdata($data, $value);
        $this->mark_as_flash(is_array($data) ? array_keys($data) : $data);
    }

    // ------------------------------------------------------------------------

    public function keep_flashdata($key) {
        $this->mark_as_flash($key);
    }

    // ------------------------------------------------------------------------

    public function tempdata($key = NULL) {
        if (isset($key)) {
            return (isset($_SESSION['__null_vars'], $_SESSION['__null_vars'][$key], $_SESSION[$key]) && is_int($_SESSION['__null_vars'][$key])) ? $_SESSION[$key] : NULL;
        }

        $tempdata = array();

        if (!empty($_SESSION['__null_vars'])) {
            foreach ($_SESSION['__null_vars'] as $key => &$value) {
                is_int($value) && $tempdata[$key] = $_SESSION[$key];
            }
        }

        return $tempdata;
    }

    // ------------------------------------------------------------------------

    public function set_tempdata($data, $value = NULL, $ttl = 300) {
        $this->set_userdata($data, $value);
        $this->mark_as_temp(is_array($data) ? array_keys($data) : $data, $ttl);
    }

    // ------------------------------------------------------------------------

    public function unset_tempdata($key) {
        $this->unmark_temp($key);
    }

}
