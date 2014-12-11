Rolling Curl Mini
===============

Rolling Curl Mini is a fork of [Rolling Curl] (http://code.google.com/p/rolling-curl/).
It allows to process multiple HTTP requests in parallel using cURL PHP library.

For more information read [this article] (http://savreen.com/mnogopotochnyj-sbor-dannyx-s-ispolzovaniem-cepochek-svyazannyx-curl-zaprosov-chast-1/) (in russian).

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



Rolling Scraper Abstract
===============

Rolling Scraper Abstract is a multipurpose scraping (crawling) framework which uses facilities of multi-curl and RollingCurlMini class.
It is a base PHP class which implement common functionality of a multi-curl scraper.
Particular functionality should be implemented in derived classes.
Particular scraper class should extend RollingScraperAbstract class and implement (override) two mandatory methods: _initPages and _handlePage.

Basic Usage Sample
-------------
``` php
class MyScraper extends RollingScraperAbstract
{
    ...
    public function __construct() {
        ...
        $this->modConfig(array(
            'state_time_storage' => '...', // temporal section of state storage (file path)
            'state_data_storage' => '...', // data section of state storage (file path)
            'scrape_life' => 0, // expiration time (secs) of scraped data
            'run_timeout' => 30, // max. time (secs) to execute scraper script
            'run_pages_loops' => 20, // max. number of loops through pages
            'run_pages_buffer' => 500, // page requests buffer size
            'curl_threads' => 10, // number of multi-curl threads
            'curl_options' => array(...), // CURL options used in multi-curl requests
        ));
        parent::__construct();
    }

    /**
     * Initialize the starting list of page requests
     */
    protected function _initPages() {
        ...
        // add page request. $url - page URL
        $this->addPage($url);
        ...
    }

    /**
     * Process response of a page request
     * @param string $cont - page content
     * @param string $url - url of request
     * @param array $aInfo - CURL info data
     * @param int $index - # of page request
     * @param array $aData - custom request data (part of request data)
     * @return bool
     */
    protected function _handlePage($cont, $url, $aInfo, $index, $aData) {
        ...
    }
    ...
}

$scraper = new MyScraper();
$bool = $scraper->run();
list($time_start, $time_end, , $time_run_start, , $n_pages_total, $n_pages_passed) =
    $scraper->getStateProgress();
if ($time_end) {
    echo sprintf('Completed at %s', date('Y.m.d, H:i:s', $time_end));
}
else {
    if ($bool)
        echo sprintf('In progress: %d/%d pages', $n_pages_passed, $n_pages_total);
    else
        echo 'Cancelled since another script instance is still running';
}
```
