<?php

/*
   ____  __      __    ___  _  _  ___    __   ____     ___  __  __  ___
  (  _ \(  )    /__\  / __)( )/ )/ __)  /__\ (_  _)   / __)(  \/  )/ __)
   ) _ < )(__  /(__)\( (__  )  (( (__  /(__)\  )(    ( (__  )    ( \__ \
  (____/(____)(__)(__)\___)(_)\_)\___)(__)(__)(__)    \___)(_/\/\_)(___/

   @author          Black Cat Development
   @copyright       Black Cat Development
   @link            https://blackcat-cms.org
   @license         http://www.gnu.org/licenses/gpl.html
   @category        CAT_Core
   @package         CAT_Core

*/


namespace CAT\Helper;
use \CAT\Base as Base;
use \CAT\Helper\DB as DB;
use \CAT\Helper\HArray as HArray;
use \CAT\Helper\Directory as Directory;

if ( !class_exists( 'Addons' ) )
{
    class Addons extends Base
    {
        /**
         * log level
         **/
        protected static $loglevel = \Monolog\Logger::EMERGENCY;
        /**
         * instance
         **/
        private   static $instance = NULL;
        /**
         * for version compare
         **/
        private   static $states   = array(
            '.0' => 'dev',
            '.1' => 'preview',
            '.2' => 'alpha',
            '.5' => 'beta',
            '.8' => 'rc',
            '.9' => 'final'
        );

        public function __construct() {}

        public function __call( $method, $args )
        {
            if ( !isset( $this ) || !is_object( $this ) )
                return false;
            if ( method_exists( $this, $method ) )
                return call_user_func_array( array(
                     $this,
                    $method
                ), $args );
        }   // end __call()

        public static function getInstance()
        {
            if ( !self::$instance )
                self::$instance = new self();
            return self::$instance;
        } // end function getInstance()

        /**
         *
         * @access public
         * @return
         **/
        public static function executeHandler(string $handler,string $classname,string $method)
        {
            include_once $handler;
            if(is_callable(array($classname,$method))) {
                self::log()->addDebug(sprintf(
                    'calling method [%s] in class [%s]',
                    $method, $classname
                ));
                $classname::$method();
                return;
            }
            $classname = '\CAT\Addon\\'.$classname;
            if(is_callable(array($classname,$method))) {
                self::log()->addDebug(sprintf(
                    'calling method [%s] in class [%s]',
                    $method, $classname
                ));
                $classname::$method();
                return;
            }
        }   // end function executeHandler()
        

        /**
         *
         * @access public
         * @return
         **/
        public static function exists($addon)
        {
            $name = self::getDetails($addon,'name');
            return ($name && strlen($name)) ? true : false;
        }   // end function exists()
        
        /**
         * Function to get installed addons
         *
         * Default: All addons of all types, sorted by type and name, flat array
         * with a list of
         *    <directory> => <name>
         *
         * Please note that $names_only and $find_icon exclude each other
         * (no place for an icon in the flat array, see above)
         * So (example)
         *     getAddons('tool','name',true,true)
         * will never work!!!
         *
         * @access public
         * @param  string  $type       (default: '')     - type of addon - can be an array
         * @param  string  $order      (default: 'name') - value to handle "ORDER BY" for database request of addons
         * @param  boolean $names_only (default: true)   - get only a flat list of names or a complete data array
         * @param  boolean $find_icon  (default: false)  - wether to search for an icon
         * @param  boolean $not_installed (default: false) - only retrieve modules that have no db entry (not installed)
         * @return array
         */
        public static function getAddons($type=NULL,$order='name',$names_only=true,$find_icon=false,$not_installed=false)
        {
            switch($type) {
                case 'javascript':
                    $stmt = self::db()->query(
                        'SELECT * FROM `:prefix:addons_javascripts`'
                    );
                    $data = $stmt->fetchAll();
                    break;
                case 'jquery':
                    $stmt = self::db()->query(
                        'SELECT * FROM `:prefix:addons_javascripts` WHERE `jquery`="Y"'
                    );
                    $data = $stmt->fetchAll();
                    break;
                case 'js':
                case 'css':
                    $stmt = self::db()->query(
                        'SELECT * FROM `:prefix:addons_javascripts` WHERE `jquery`="N"'
                    );
                    $data = $stmt->fetchAll();
                    break;
                default:
                    // create query builder
                    $q = DB::qb()
                        ->select('*')
                        ->from(sprintf('%saddons',CAT_TABLE_PREFIX));

                    // filter by type
                    if($type) {
                        if(is_array($type)) {
                            foreach($type as $item) {
                                $q->andWhere('type = '.$q->createNamedParameter($item));
                            }
                        } else {
                            $q->andWhere('type = '.$q->createNamedParameter($type));
                        }
                    } else {
                        $q->andWhere('type != "core"');
                    }

                    // always order by type
                    $q->orderBy('type', 'ASC'); // default order
                    if($order) {
                        $q->addOrderBy($order, 'ASC');
                    } else {
                        $q->addOrderBy('name','ASC');
                    }

                    // get the data
                    $data = $q->execute()->fetchAll();

                    // remove addons the user is not allowed for
                    $count = (count($data)-1);
                    for($i=$count;$i>=0;$i--)
                    {
                        $addon =& $data[$i];
                        if(!self::user()->hasModulePerm($addon['addon_id']))
                        {
                            unset($data[$i]); // not allowed
                            continue;
                        }
                        if(!$names_only)
                        {
                            if($find_icon) {
                                $icon = Directory::sanitizePath(CAT_ENGINE_PATH.'/'.$addon['type'].'s/'.$addon['directory'].'/icon.png');
                                $addon['icon'] = '';
                                if(file_exists($icon)){
                                    list($width, $height, $type_of, $attr) = getimagesize($icon);
                                    // Check whether file is 32*32 pixel and is an PNG-Image
                                    $addon['icon']
                                        = ($width == 32 && $height == 32 && $type_of == 3)
                                        ? CAT_URL.'/'.$addon['type'].'s/'.$addon['directory'].'/icon.png'
                                        : false
                                        ;
                                }
                            }
                            if($addon['type']!='language') {
                                $info = self::getInfo($addon['directory']);
                                Base::addLangFile(CAT_ENGINE_PATH.'/modules/'.$addon['directory'].'/languages/');
                                $info['description']  = (
                                      isset( $info['description'])
                                    ? self::lang()->translate($info['description'])
                                    : 'n/a'
                                );

                                $addon = array_merge($addon,$info);
                            }
                        }
                    }

                    if($not_installed)
                    {
                        $seen   = HArray::extractList($data,'directory');
                        $result = array();
                        // scan modules path for modules not seen yet
                        foreach(array('module','template') as $t)
                        {
                            $subdirs = Directory::findDirectories(CAT_ENGINE_PATH.'/'.$t.'s');
                            if(count($subdirs))
                            {
                                foreach($subdirs as $dir)
                                {
                                    // skip paths starting with __ (sometimes used for deactivating addons)
                                    if(substr(pathinfo($dir,PATHINFO_BASENAME),0,2) == '__') continue;
                                    $info = self::getInfo(pathinfo($dir,PATHINFO_BASENAME), $t);
                                    if(is_array($info) && count($info) && !in_array($dir,$seen)) {
                                        $result[] = $info;
                                    }
                                }
                            }
                        }
                        return $result;
                    }
                    break;
            } // end switch()

            if($names_only) {
                $data = HArray::extractList($data,'name','directory');
            } else {
                for($i=0;$i<count($data);$i++) {
                    $info = self::getInfo($data[$i]['directory']);
                    if(is_array($info) && !empty($info)) {
                        $data[$i] = array_merge($data[$i],$info);
                    }
                }
            }

            return $data;
        } // end function getAddons()

        /**
         * gets the details of an addon
         *
         * @access public
         * @param  string  ID or directory name
         * @return mixed   array on success, NULL otherwise
         **/
        public static function getDetails($addon,$field='*')
        {
            // sanitize column name
            if(!in_array($field,array('*','addon_id','type','directory','name','installed','upgraded','removable','bundled')))
                return NULL; // silently fail
            $q = 'SELECT %s FROM `:prefix:addons` WHERE ';
            if(is_numeric($addon)) $q .= '`addon_id`=:val';
            else                   $q .= '`directory`=:val';
            $addon = self::db()->query(
                sprintf($q,($field != '*' ? '`'.$field.'`' : $field)),
                array('val'=>$addon)
            );
            if($addon->rowCount())
            {
                $data = $addon->fetch(\PDO::FETCH_ASSOC);
                if($field!='*') return $data[$field];
                else            return $data;
            }
            return NULL;
        } // end function getDetails()

        /**
         *
         * @access public
         * @return
         **/
        public static function getHandler(string $directory, string $module)
        {
            foreach (array_values(array(str_replace(' ', '', $directory),$module)) as $classname) {
                $filename = Directory::sanitizePath(CAT_ENGINE_PATH.'/modules/'.$directory.'/inc/class.'.$classname.'.php');
                if (file_exists($filename)) {
                    return array($filename,$classname);
                }
            }
        }   // end function getHandler()
        
        /**
         *
         * @access public
         * @return
         **/
        public static function getInfo($directory,$type='module')
        {
            $info    = array();
            $fulldir = CAT_ENGINE_PATH.'/'.$type.'s/'.$directory.'/inc';
            $namespace = '\CAT\Addon';
            if($type=='templates') {
                $namespace .= '\Template';
            }

            if(is_dir($fulldir))
            {
                // find class.<modulename>.php
                $files = Directory::findFiles($fulldir,array('extension'=>'php','remove_prefix'=>true));
                if(count($files)>0)
                {
                    for($i=0;$i<count($files);$i++)
                    {
                        $classname = str_ireplace('class.','',pathinfo($files[$i],PATHINFO_FILENAME));
                        if(!class_exists($classname,false))
                        {
                            require_once $fulldir.'/'.$files[$i];
                        }
                        // as there may be files not containing a class...
                        if(class_exists($namespace.'\\'.$classname,false))
                        {
                            $class = $namespace.'\\'.$classname;
                            $info  = $class::getInfo();
                            return $info;
                        }
                    }
                }
            }

            return $info;
        }   // end function getInfo()

        /**
         *
         * @access public
         * @return
         **/
        public static function getVariants(string $directory)
        {
            $module_variants = \CAT\Helper\Directory::findDirectories(
                CAT_ENGINE_PATH.'/modules/'.$directory.'/templates',
                array(
                    'max_depth'     => 1,
                    'remove_prefix' => true
                )
            );
            // remove paths starting with an underscore (we use this to
            // deactivate variants)
            if(is_array($module_variants) && count($module_variants)>0) {
                for($i=count($module_variants)-1;$i>=0;$i--) {
                    if(!substr_compare($module_variants[$i],'_',0,1)) {
                        unset($module_variants[$i]);
                    }
                }
            }
            return $module_variants;
        }   // end function getVariants()

        /**
         * removes/replaces known substrings in version string with their
         * weights
         *
         * @access public
         * @param  string  $version
         * @return string
         */
        public static function getVersion($version)
        {
            $version = strtolower($version);
            // additional version string, f.e. "beta", to "weight"
            foreach(self::$states as $value => $keys) {
                $version = str_replace($keys, $value, $version);
            }
            // remove blanks, comma, 'x'
            $version = str_replace(
                array(" ",',','.x'),
                array("",'',''),
                $version
            );
            /**
             *	Force the version-string to get at least 4 terms.
             *	E.g. 2.7 will become 2.7.0.0
             */
            $temp_array = explode( ".", $version );
            $n          = count( $temp_array );
            if($n < 4)
            {
                for($i = 0; $i<(4-$n); $i++)
                    $version = $version . ".0";
            }
            // remove letters ('v1.2.3' => '1.2.3')
            $version = preg_replace('~[a-z]+~i','',$version);
            return $version;
        } // end function getVersion()

        /**
         * checks if the module in folder $directory has a variant $variant
         *
         * @access public
         * @return
         **/
        public static function hasVariant(string $directory, string $variant)
        {
            $variants = self::getVariants($directory);
            if(!is_array($variants) || count($variants)==0) return false;
            return in_array($variant,$variants);
        }   // end function hasVariant()

        /**
         * checks if a module is installed
         *
         * @access public
         * @param  string  $module  - module name or directory name
         * @param  string  $version - (optional) version to check (>=)
         * @param  string  $type    - default 'module'
         * @return boolean
         **/
        public static function isInstalled($module,$version=NULL,$type='module')
        {
            $q = self::db()->query(
                'SELECT * FROM `:prefix:addons` WHERE type=:type AND ( directory=:dir OR name=:name )',
                array('type'=>$type, 'dir'=>$module, 'name'=>$module)
            );
            if ( !is_object($q) || !$q->rowCount() )
                return false;

            // note: if there's more than one, the first match will be returned!
            while($addon = $q->fetchRow())
            {
                if($version && self::versionCompare($addon['version'], $version))
                    return true;

                // name before directory
                if($addon['name'] == $module)
                    return true;

                if($addon['directory'] == $module)
                    return true;

            }
            return false;
        } // end function isInstalled()
        
        /**
         * This function performs a comparison of two provided version strings
         * The versions are first converted into a string following the major.minor.revision
         * convention; the converted strings are passed to version_compare()
         *
         * @access public
         * @param  string  $version1
         * @param  string  $version2
         * @param  string  $operator - default '>='
         */
        public static function versionCompare($version1,$version2,$operator='>=')
        {
            return version_compare(self::getVersion($version1),self::getVersion($version2),$operator);
        } // end versionCompare()

    } // class Addons

} // if class_exists()
