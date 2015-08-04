<?php

namespace phpninjas\Redis\Test;

use phpninjas\Redis\RedisClient;

class RedisClientTest extends \PHPUnit_Framework_TestCase {

    /**
     * @var RedisClient
     */
    private $redis;


    public function setUp(){
        $this->redis = new RedisClient("localhost");
    }

    public function teardown(){
        $this->redis->flushDb();
    }

    public function testWrite(){
        $this->redis["somekey"] = 123;
    }

    public function testWriteSpacedKeys(){
        $this->redis["key with spaces"] = "space es";
    }

    public function testPersistentWrite(){
        $this->redis["somekey"] = 123;
        $redisClient = new RedisClient("localhost");
        $this->assertThat($redisClient["somekey"], $this->equalTo(123));
    }

    public function testWriteRead(){
        $this->redis["somekey"] = "abc";
        $this->assertThat($this->redis['somekey'], $this->equalTo("abc"));
    }

    public function testReadNonExistant(){
        $this->assertNull($this->redis["not a key"]);
    }

    public function testExists(){
        $this->assertFalse($this->redis->offsetExists("random"));
    }

    public function testDelete(){

        $this->redis["somekey"] = 123;
        unset($this->redis["somekey"]);
    }

    public function testRPushAndPop(){
        $this->redis->rpush("somekey", [1,2,3]);

        $this->assertThat($this->redis->rpop("somekey"), $this->equalTo(3));
    }

    public function testLPushAndPop(){
        $this->redis->lpush("somekey", [3,2,1]);

        $this->assertThat($this->redis->lpop("somekey"), $this->equalTo(1));
    }

    public function testLRange(){
        $this->redis->rpush("myrange",[1,2,3,4,5]);

        $this->assertThat($this->redis->lrange("myrange", 0, 0), $this->equalTo([1]));
    }

    public function testSetArray(){

        $this->redis["list of ints"] = [1,2,3];

        $this->assertThat($this->redis["list of ints"], $this->equalTo([1,2,3]));

    }


}
