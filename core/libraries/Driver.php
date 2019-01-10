<?php

defined('BASEPATH') OR exit('No direct script access allowed');

class Null_Driver_Library {

    protected $valid_drivers = array();
    protected $lib_name;

    public function __get($child) {
        // Try to load the driver
        return $this->load_driver($child);
    }

    public function load_driver($child) {
        // Get Null Computindo instance and subclass prefix
        $prefix = config_item('subclass_prefix');

        if (!isset($this->lib_name)) {
            // Get library name without any prefix
            $this->lib_name = str_replace(array('Null_', $prefix), '', get_class($this));
        }

        // The child will be prefixed with the parent lib
        $child_name = $this->lib_name . '_' . $child;

        // See if requested child is a valid driver
        if (!in_array($child, $this->valid_drivers)) {
            // The requested driver isn't valid!
            $msg = 'Invalid driver requested: ' . $child_name;
            log_message('error', $msg);
            show_error($msg);
        }

        // Get package paths and filename case variations to search
        $Null = get_instance();
        $paths = $Null->load->get_package_paths(TRUE);

        // Is there an extension?
        $class_name = $prefix . $child_name;
        $found = class_exists($class_name, FALSE);
        if (!$found) {
            // Check for subclass file
            foreach ($paths as $path) {
                // Does the file exist?
                $file = $path . 'libraries/' . $this->lib_name . '/drivers/' . $prefix . $child_name . '.php';
                if (file_exists($file)) {
                    // Yes - require base class from BASEPATH
                    $basepath = BASEPATH . 'libraries/' . $this->lib_name . '/drivers/' . $child_name . '.php';
                    if (!file_exists($basepath)) {
                        $msg = 'Unable to load the requested class: Null_' . $child_name;
                        log_message('error', $msg);
                        show_error($msg);
                    }

                    // Include both sources and mark found
                    include_once($basepath);
                    include_once($file);
                    $found = TRUE;
                    break;
                }
            }
        }

        // Do we need to search for the class?
        if (!$found) {
            // Use standard class name
            $class_name = 'Null_' . $child_name;
            if (!class_exists($class_name, FALSE)) {
                // Check package paths
                foreach ($paths as $path) {
                    // Does the file exist?
                    $file = $path . 'libraries/' . $this->lib_name . '/drivers/' . $child_name . '.php';
                    if (file_exists($file)) {
                        // Include source
                        include_once($file);
                        break;
                    }
                }
            }
        }

        // Did we finally find the class?
        if (!class_exists($class_name, FALSE)) {
            if (class_exists($child_name, FALSE)) {
                $class_name = $child_name;
            } else {
                $msg = 'Unable to load the requested driver: ' . $class_name;
                log_message('error', $msg);
                show_error($msg);
            }
        }

        // Instantiate, decorate and add child
        $obj = new $class_name();
        $obj->decorate($this);
        $this->$child = $obj;
        return $this->$child;
    }

}

// --------------------------------------------------------------------------

class Null_Driver {

    protected $_parent;
    protected $_methods = array();
    protected $_properties = array();
    protected static $_reflections = array();

    public function decorate($parent) {
        $this->_parent = $parent;

        $class_name = get_class($parent);

        if (!isset(self::$_reflections[$class_name])) {
            $r = new ReflectionObject($parent);

            foreach ($r->getMethods() as $method) {
                if ($method->isPublic()) {
                    $this->_methods[] = $method->getName();
                }
            }

            foreach ($r->getProperties() as $prop) {
                if ($prop->isPublic()) {
                    $this->_properties[] = $prop->getName();
                }
            }

            self::$_reflections[$class_name] = array($this->_methods, $this->_properties);
        } else {
            list($this->_methods, $this->_properties) = self::$_reflections[$class_name];
        }
    }

    // --------------------------------------------------------------------

    public function __call($method, $args = array()) {
        if (in_array($method, $this->_methods)) {
            return call_user_func_array(array($this->_parent, $method), $args);
        }

        throw new BadMethodCallException('No such method: ' . $method . '()');
    }

    // --------------------------------------------------------------------

    public function __get($var) {
        if (in_array($var, $this->_properties)) {
            return $this->_parent->$var;
        }
    }

    // --------------------------------------------------------------------

    public function __set($var, $val) {
        if (in_array($var, $this->_properties)) {
            $this->_parent->$var = $val;
        }
    }

}
