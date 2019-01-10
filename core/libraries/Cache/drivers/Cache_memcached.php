<?php

defined('BASEPATH') OR exit('No direct script access allowed');

class Null_Cache_memcached extends Null_Driver {

    protected $_memcached;
    protected $_memcache_conf = array(
        'default' => array(
            'host' => '127.0.0.1',
            'port' => 11211,
            'weight' => 1
        )
    );

    // ------------------------------------------------------------------------

    public function __construct() {
        // Try to load memcached server info from the config file.
        $Null = & get_instance();
        $defaults = $this->_memcache_conf['default'];

        if ($Null->config->load('memcached', TRUE, TRUE)) {
            if (is_array($Null->config->config['memcached'])) {
                $this->_memcache_conf = array();

                foreach ($Null->config->config['memcached'] as $name => $conf) {
                    $this->_memcache_conf[$name] = $conf;
                }
            }
        }

        if (class_exists('Memcached', FALSE)) {
            $this->_memcached = new Memcached();
        } elseif (class_exists('Memcache', FALSE)) {
            $this->_memcached = new Memcache();
        } else {
            log_message('error', 'Cache: Failed to create Memcache(d) object; extension not loaded?');
        }

        foreach ($this->_memcache_conf as $cache_server) {
            isset($cache_server['hostname']) OR $cache_server['hostname'] = $defaults['host'];
            isset($cache_server['port']) OR $cache_server['port'] = $defaults['port'];
            isset($cache_server['weight']) OR $cache_server['weight'] = $defaults['weight'];

            if (get_class($this->_memcached) === 'Memcache') {
                // Third parameter is persistance and defaults to TRUE.
                $this->_memcached->addServer(
                        $cache_server['hostname'], $cache_server['port'], TRUE, $cache_server['weight']
                );
            } else {
                $this->_memcached->addServer(
                        $cache_server['hostname'], $cache_server['port'], $cache_server['weight']
                );
            }
        }
    }

    // ------------------------------------------------------------------------

    public function get($id) {
        $data = $this->_memcached->get($id);

        return is_array($data) ? $data[0] : $data;
    }

    // ------------------------------------------------------------------------

    public function save($id, $data, $ttl = 60, $raw = FALSE) {
        if ($raw !== TRUE) {
            $data = array($data, time(), $ttl);
        }

        if (get_class($this->_memcached) === 'Memcached') {
            return $this->_memcached->set($id, $data, $ttl);
        } elseif (get_class($this->_memcached) === 'Memcache') {
            return $this->_memcached->set($id, $data, 0, $ttl);
        }

        return FALSE;
    }

    // ------------------------------------------------------------------------

    public function delete($id) {
        return $this->_memcached->delete($id);
    }

    // ------------------------------------------------------------------------

    public function increment($id, $offset = 1) {
        return $this->_memcached->increment($id, $offset);
    }

    // ------------------------------------------------------------------------

    public function decrement($id, $offset = 1) {
        return $this->_memcached->decrement($id, $offset);
    }

    // ------------------------------------------------------------------------

    public function clean() {
        return $this->_memcached->flush();
    }

    // ------------------------------------------------------------------------

    public function cache_info() {
        return $this->_memcached->getStats();
    }

    // ------------------------------------------------------------------------

    public function get_metadata($id) {
        $stored = $this->_memcached->get($id);

        if (count($stored) !== 3) {
            return FALSE;
        }

        list($data, $time, $ttl) = $stored;

        return array(
            'expire' => $time + $ttl,
            'mtime' => $time,
            'data' => $data
        );
    }

    // ------------------------------------------------------------------------

    public function is_supported() {
        return (extension_loaded('memcached') OR extension_loaded('memcache'));
    }

}
