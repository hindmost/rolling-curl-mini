Rolling Curl Mini
===============

Rolling Curl Mini allows to process multiple HTTP requests in parallel using cURL PHP library.
It is a fork of [Rolling Curl] (http://code.google.com/p/rolling-curl/).

For more information read [this article (in russian)] (http://savreen.com/mnogopotochnyj-sbor-dannyx-s-ispolzovaniem-cepochek-svyazannyx-curl-zaprosov-chast-1/)


Basic Usage Sample
-------------
``` php
...
require "RollingCurlMini.inc.php";
...
$o_mc = new RollingCurlMini(10);
...
$o_mc->add($url, $postdata, $callback, $userdata, $options, $headers);
...
$o_mc->execute();
...
```


Callbacks
-------------
Any request may have an individual callback - function/method to be called as this request is completed.
Callback accepts 4 parameters and may look like the following one:

``` php
/**
 * @param string $content - content of request response
 * @param string $url - URL of requested resource
 * @param array $info - cURL handle info
 * @param mixed $userdata - user-defined data passed with add() method
 */
function request_callback($content, $url, $info, $userdata) {
}
```


License
-------------
* [Apache License 2.0] (http://www.apache.org/licenses/LICENSE-2.0)
