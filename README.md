USAGE
=====

```php
$redis = new RedisClient("localhost");
$redis["somekey"] = "some value";
echo $redis["somekey"]; // "some value"
```