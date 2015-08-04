<?php

namespace phpninjas\Redis;

class RedisClient implements \ArrayAccess{

    /**
     * @var resource
     */
    private $socket;

    const SIMPLE_STRINGS = "+";
    const ERRORS = "-";
    const INTEGERS = ":";
    const BULK_STRINGS = "$";
    const ARRAYS = "*";
    const CRLF = "\r\n";

    const STATUS_OK = "OK";

    /**
     * @param string $host
     * @param int $port
     */
    public function __construct($host = "localhost", $port = 6379){
        $this->tryConnect($host, $port);
    }

    /**
     * Attempt redis socket connection
     * @param $socket
     * @param $host
     * @param $port
     */
    private function tryConnect($host, $port){
        if(false !== $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)){
            if(socket_connect($this->socket,$host,$port) === false){
                $errno = socket_last_error($this->socket);
                throw new RedisConnectionException(socket_strerror($errno), $errno);
            };
        } else {
            $errno = socket_last_error();
            throw new RedisConnectionException(socket_strerror($errno), $errno);
        };
    }

    /**
     * Read off $length of bytes, blocking
     * @param int $length
     * @return string
     */
    private function nibble($length = 1){
        return socket_read($this->socket, $length);
    }

    /**
     * Read byte by byte until we find the sequence we want.
     * @param string $sequence
     * @return string
     */
    private function readUntil($sequence = self::CRLF){
        $bytes = "";
        while(true){
            $bytes .= $this->nibble();
            if(substr($bytes, -strlen($sequence)) == $sequence){
                break;
            };
        }
        if($sequence = self::CRLF){
            $bytes = substr($bytes, 0, -2);
        }
        return $bytes;
    }

    /**
     * Send a raw command to redis
     * @param $cmd
     * @return int
     */
    public function send($cmd){
        $cmd = $cmd . static::CRLF;
        $length = strlen($cmd);
        $numBytes = 0;
        while($length > $numBytes){
            $numBytes += socket_write($this->socket, substr($cmd,$numBytes));
        }
        return $numBytes;
    }

    /**
     * Read the response byte stream and convert.
     * @param null $firstByte
     * @return string
     */
    private function read($firstByte = null){
        $firstByte = $firstByte?:$this->nibble();
        switch($firstByte){
            case static::SIMPLE_STRINGS:
                $payload = (string)$this->readUntil();
                break;
            case static::BULK_STRINGS:
                $size = intval($this->readUntil());
                $payload = null;
                if($size !== -1) { // error!!!
                    $payload = $this->nibble($size); // read off size bytes
                    $this->readUntil(); // read the rest until CRLF
                }
                break;
            case static::INTEGERS:
                $payload = intval($this->readUntil());
                break;
            case static::ARRAYS:
                $size = intval($this->readUntil());
                $payload = [];
                if($size != -1){
                    while($size--){
                        $payload[] = $this->read($this->nibble());
                    }
                }
                break;
            case static::ERRORS:
                $payload = (string)$this->readUntil();
                throw new RedisReadException($payload);
                break;
            default:
                throw new RedisUnknownResponseException();
        }
        return $payload;
    }

    /**
     * (PHP 5 &gt;= 5.0.0)<br/>
     * Whether a offset exists
     * @link http://php.net/manual/en/arrayaccess.offsetexists.php
     * @param mixed $key <p>
     * An offset to check for.
     * </p>
     * @return boolean true on success or false on failure.
     * </p>
     * <p>
     * The return value will be casted to boolean if non-boolean was returned.
     */
    public function offsetExists($key)
    {
        return $this->exists($key);
    }

    /**
     * Test for existence of a key
     * @param $key
     * @return bool
     */
    public function exists($key){
        $this->send("EXISTS $key");
        return !!$this->read();
    }

    /**
     * (PHP 5 &gt;= 5.0.0)<br/>
     * Offset to retrieve
     * @link http://php.net/manual/en/arrayaccess.offsetget.php
     * @param mixed $key <p>
     * The offset to retrieve.
     * </p>
     * @return mixed Can return all value types.
     */
    public function offsetGet($key)
    {
        try {
            return $this->get($key);
        }catch (RedisReadException $e){
            return $this->lrange($key);
        }
    }

    /**
     * Fetch a key, should be null (nil) on non-existence
     * @param $key
     * @return string
     */
    public function get($key){
        $this->send("GET \"$key\"");
        return $this->read();
    }
    /**
     * (PHP 5 &gt;= 5.0.0)<br/>
     * Offset to set
     * @link http://php.net/manual/en/arrayaccess.offsetset.php
     * @param mixed $key <p>
     * The offset to assign the value to.
     * </p>
     * @param mixed $value <p>
     * The value to set.
     * </p>
     * @return void
     */
    public function offsetSet($key, $value)
    {
        if(is_array($value)){
            $this->rpush($key, $value);
        } else {
            $this->set($key, $value);
        }
    }

    public function set($key, $value){
        $this->send("SET \"$key\" \"$value\"");
        if($this->read() !== static::STATUS_OK){
            throw new RedisWriteException("SET failed: '$key' => '$value'");
        }
    }

    /**
     * (PHP 5 &gt;= 5.0.0)<br/>
     * Offset to unset
     * @link http://php.net/manual/en/arrayaccess.offsetunset.php
     * @param mixed $key <p>
     * The offset to unset.
     * </p>
     * @return void
     */
    public function offsetUnset($key)
    {
        $this->del($key);
    }

    /**
     * @param $key
     */
    public function del($key){
        $keys = func_get_args();
        $this->send("DEL ".implode(" ", $keys));
        $res = intval($this->read());
        if($res !== count($keys)){
            throw new RedisDeleteException("DEL failed: '$key'");
        }
    }

    /**
     * @param $key
     * @param $value
     * @return string
     */
    public function lpush($key, $value){
        $value = is_array($value)?implode(" ", $value):$value;
        $this->send("LPUSH \"$key\" $value");
        return $this->read();
    }

    /**
     * @param $key
     * @param $value
     * @return int
     */
    public function rpush($key, $value){
        $value = is_array($value)?implode(" ", $value):$value;
        $this->send("RPUSH \"$key\" $value");
        return $this->read();
    }

    /**
     * @param $key
     * @return string
     */
    public function rpop($key)
    {
        $this->send("RPOP \"$key\"");
        return $this->read();
    }

    /**
     * @param $key
     */
    public function lpop($key)
    {
        $this->send("LPOP \"$key\"");
        return $this->read();
    }

    /**
     * @param $string
     * @param $start
     * @param $stop
     * @return string
     */
    public function lrange($string, $start = 0, $stop = -1)
    {
        $this->send("LRANGE \"$string\" $start $stop");
        return $this->read();
    }

}
