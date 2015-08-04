<?php

namespace phpninjas\Redis;

class RedisTransaction {
    /**
     * @var RedisClient
     */
    private $redis;

    /**
     * @param RedisClient $redis
     */
    public function __construct(RedisClient $redis){
        $this->redis = $redis;
    }

    public function exec(){
        $this->redis->send("EXEC");
    }

    public function watch($key){
        $this->redis->send("WATCH \"$key\"");
    }

    public function discard(){
        $this->redis->send("DISCARD");
    }

    public function unwatch($key){
        $this->redis->send("UNWATCH \"$key\"");
    }

}