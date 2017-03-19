<?php
/**
 * 
 * @author taylor.luk
 * @todo FileLoader need more test coverage
 */
class H2o_Loader {
    public $parser;
    public $runtime;
    public $cached = false;
    protected $cache = false;
    public $searchpath = false;
    
    function read($filename) {}
    function cache_read($file, $object, $ttl = 3600) {}
}

class H2o_File_Loader extends H2o_Loader {

    function __construct($searchpath, $options = array()) {
        // if (is_file($searchpath)) {
        //     $searthpath = dirname($searchpath).DS;
        // }
        // if (!is_dir($searchpath))
        //     throw new TemplateNotFound($filename);
        //
        
        if (!is_array($searchpath))
             throw new Exception("searchpath must be an array");
        
        
		$this->searchpath = (array) $searchpath;
		$this->setOptions($options);
    }

    function setOptions($options = array()) {
        if (isset($options['cache']) && $options['cache']) {
            $this->cache = h2o_cache($options);
        }
    }
    
    function read($filename) {
                
        if (!is_file($filename))
            $filename = $this->get_template_path($this->searchpath,$filename);

        if (is_file($filename)) {
            $source = file_get_contents($filename);
            return $this->runtime->parse($source);
        } else {
            throw new TemplateNotFound($filename);
        }
    }

	function get_template_path($search_path, $filename){

        
        for ($i=0 ; $i < count($search_path) ; $i++) 
        { 
            
            if(file_exists($search_path[$i] . $filename)) {
                $filename = $search_path[$i] . $filename;
                return $filename;
                break;
            } else {
                continue;
            }

        }

        throw new Exception('TemplateNotFound - Looked for template: ' . $filename);

        

	}

    function read_cache($filename) {        
        if (!$this->cache){
             $filename = $this->get_template_path($this->searchpath,$filename);
             return $this->read($filename);
        }
            
        if (!is_file($filename)){
            $filename = $this->get_template_path($this->searchpath,$filename);
        }
            
        $filename = realpath($filename);
        
        $cache = md5($filename);
        $object = $this->cache->read($cache);
        $this->cached = $object && !$this->expired($object);
        
        if (!$this->cached) {
            $nodelist = $this->read($filename);
            $object = (object) array(
                'filename' => $filename,
                'content' => serialize($nodelist),
                'created' => time(),
                'templates' => $nodelist->parser->storage['templates'],
                'included' => $nodelist->parser->storage['included'] + array_values(h2o::$extensions)
            );
            $this->cache->write($cache, $object);
        } else {
            foreach($object->included as $ext => $file) {
                include_once (h2o::$extensions[$ext] = $file);
            }
        }
        return unserialize($object->content);
    }

    function flush_cache() {
        $this->cache->flush();
    }

    function expired($object) {
        if (!$object) return false;
        
        $files = array_merge(array($object->filename), $object->templates);
        foreach ($files as $file) {
            if (!is_file($file))
                $file = $this->get_template_path($this->searchpath, $file);
            
            if ($object->created < filemtime($file))
                return true;
        }
        return false;
    }
}

function file_loader($file) {
    return new H2o_File_Loader($file);
}

class H2o_Hash_Loader {

    function __construct($scope, $options = array()) {
        $this->scope = $scope;
    }
    
    function setOptions() {}

    function read($file) {
        if (!isset($this->scope[$file]))
            throw new TemplateNotFound;
        return $this->runtime->parse($this->scope[$file], $file);
    }
    
    function read_cache($file) {
        return $this->read($file);
    }
}

function hash_loader($hash = array()) {
    return new H2o_Hash_Loader($hash);
}

/**
 * Cache subsystem
 *
 */
function h2o_cache($options = array()) {
    $type = $options['cache'];
    $className = "H2o_".ucwords($type)."_Cache";

    if (class_exists($className, false)) {
        return new $className($options);
    }
    return false;
}

class H2o_File_Cache {
    var $ttl = 3600;
    var $prefix = 'h2o_';
    
    function __construct($options = array()) {
        if (isset($options['cache_dir']) && is_writable($options['cache_dir'])) {
            $path = $options['cache_dir'];
        } else {
            $path = dirname($tmp = tempnam(uniqid(rand(), true), ''));

            if (file_exists($tmp)) unlink($tmp);
        }
        if (isset($options['cache_ttl'])) {
            $this->ttl = $options['cache_ttl'];
        }
        if(isset($options['cache_prefix'])) {
            $this->prefix = $options['cache_prefix'];
        }
        
        $this->path = realpath($path). DS;
    }

    function read($filename) {
        if (!file_exists($this->path . $this->prefix. $filename))
            return false;

        $content = file_get_contents($this->path . $this->prefix. $filename);
        $expires = (int)substr($content, 0, 10);

        if (time() >= $expires) 
            return false;
        return unserialize(trim(substr($content, 10)));
    }

    function write($filename, &$object) {
        $expires = time() + $this->ttl;
        $content = $expires . serialize($object);
        return file_put_contents($this->path . $this->prefix. $filename, $content);   
    }
    
    function flush() {
        foreach (glob($this->path. $this->prefix. '*') as $file) {
            @unlink($file);
        }
    }
}

class H2o_Apc_Cache {
    var $ttl = 3600;
    var $prefix = 'h2o_';
    
    function __construct($options = array()) {
        if (!function_exists('apc_add'))
            throw new Exception('APC extension needs to be loaded to use APC cache');
            
        if (isset($options['cache_ttl'])) {
            $this->ttl = $options['cache_ttl'];
        } 
        if(isset($options['cache_prefix'])) {
            $this->prefix = $options['cache_prefix'];
        }
    }
    
    function read($filename) {
        return apc_fetch($this->prefix.$filename);
    }

    function write($filename, $object) {
        return apc_store($this->prefix.$filename, $object, $this->ttl);   
    }
    
    function flush() {
        return apc_clear_cache('user');
    }
}


class H2o_Memcache_Cache {
	var $ttl	= 3600;
    var $prefix = 'h2o_';
	/**
	 * @var host default is file socket 
	 */
	var $host	= 'unix:///tmp/memcached.sock';
	var $port	= 0;
    var $object;
    function __construct( $scope, $options = array() ) {
    	if ( !function_exists( 'memcache_set' ) )
            throw new Exception( 'Memcache extension needs to be loaded to use memcache' );
            
        if ( isset( $options['cache_ttl'] ) ) {
            $this->ttl = $options['cache_ttl'];
        } 
        if( isset( $options['cache_prefix'] ) ) {
            $this->prefix = $options['cache_prefix'];
        }
		
		if( isset( $options['host'] ) ) {
            $this->host = $options['host'];
        }
		
		if( isset( $options['port'] ) ) {
            $this->port = $options['port'];
        }
		
        $this->object = memcache_connect( $this->host, $this->port );
    }
    
    function read( $filename ){
    	return memcache_get( $this->object, $this->prefix.$filename );
    }
    
    function write( $filename, $content ) {
    	return memcache_set( $this->object,$this->prefix.$filename,$content , MEMCACHE_COMPRESSED,$this->ttl );
    }
    
    function flush(){
    	return memcache_flush( $this->object );
    }
}


/**
 * Class H2o_Redis_Cache
 */
class H2o_Redis_Cache implements Serializable
{
    /**
     * @var int|mixed
     */
    private $ttl = 3600;

    /**
     * @var mixed|string
     */
    private $prefix = 'h2o_';

    /**
     * @var array|mixed
     */
    private $redis = [
        'mode' => 'standalone',
        'host' => '127.0.0.1',
        'port' => 6379,
        'db' => 0,
        'service' => 'mymaster',
    ];

    /**
     * @var string
     */
    private $encoding_method = 'php';

    /**
     * @var Predis\Client
     */
    private $object;

    /**
     * H2o_Redis_Cache constructor.
     * @param array $options
     */
    public function __construct($options = [])
    {
        error_log('redis -> __construct');
        if (isset($options['cache_ttl'])) {
            $this->ttl = $options['cache_ttl'];
        }
        if (isset($options['cache_prefix'])) {
            $this->prefix = $options['cache_prefix'];
        }

        if (isset($options['redis'])) {
            $this->redis = $options['redis'];
        }

        if (isset($options['cache_encoding_method'])) {
            $this->encoding_method = $options['cache_encoding_method'];
        }

        if ($this->redis['mode'] === 'sentinel') {
            $redisParameters = [
                'tcp://' . $this->redis['host'] . ':' . $this->redis['port'],
            ];
            $redisOptions = [
                'replication' => 'sentinel',
                'service' => $this->redis['service'],
                'parameters' => [
                    'database' => (int)$this->redis['db'],
                ]
            ];
        } else {
            $redisParameters = [
                'scheme' => 'tcp',
                'host'   => $this->redis['host'],
                'port'   => (int)$this->redis['port'],
                'database' => (int)$this->redis['db'],
            ];
            $redisOptions = null;
        }

        $this->object = new Predis\Client($redisParameters, $redisOptions);
    }

    /**
     * Get an object
     *
     * Note: using unserialize on a bool - false or string - "", returns false
     *
     * @param $filename
     * @return mixed
     */
    public function read($filename)
    {
        error_log('redis -> read');
        error_log($filename);
        $result = $this->object->get($this->prefix . $filename);
        error_log($result);
        return $this->decode($this->object->get($this->prefix . $filename));
    }

    /**
     * Set an object
     *
     * @param $filename
     * @param $content
     * @return bool
     */
    public function write($filename, $content)
    {
        error_log('redis -> write');
        return $this->object->setex($this->prefix . $filename, $this->ttl, $this->encode($content));
    }

    /**
     * Flush all objects
     *
     * @return int
     */
    public function flush()
    {
        error_log('redis -> flush');
        return $this->object->del($this->object->keys($this->prefix . '*'));
    }

    /**
     * Serialize a piece of data
     *
     * @param $data
     * @return mixed|string
     */
    private function encode($data)
    {
        error_log('redis -> encode');
        switch ($this->encoding_method) {
            case 'json':
                return $result = json_encode($data);
            case 'php':
            default:
                $result = serialize($data);
        }
        return base64_encode($result);
    }

    /**
     * Unserialize a piece of data
     *
     * @param $data
     * @return mixed
     */
    private function decode($data)
    {
        error_log('redis -> decode');
        $data = base64_decode($data);
        switch ($this->encoding_method) {
            case 'json':
                return json_decode($data);
        }
        return base64_decode($data);
    }

    /**
     * String representation of object
     * @link http://php.net/manual/en/serializable.serialize.php
     * @return string the string representation of the object or null
     * @since 5.1.0
     */
    public function serialize()
    {
        error_log('redis -> serialize');
        return null;
    }

    /**
     * Constructs the object
     * @link http://php.net/manual/en/serializable.unserialize.php
     * @param string $serialized <p>
     * The string representation of the object.
     * </p>
     * @return void
     * @since 5.1.0
     */
    public function unserialize($serialized)
    {
        error_log('redis -> unserialize');
    }
}
