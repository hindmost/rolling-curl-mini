<?php

require_once dirname(__FILE__). '/RollingCurlMini.inc.php';

class Scraper
{
    const N_THREADS = 10;
    const N_MAXLOOPS = 2;
    const FILE_COOKIE = 'cookie-%03d.txt';
    const URL_SITE = 'http://www.imdb.com';
    const RX_TITLE = '/<title>([^<>]+)</isu';
    const RX_XTRA_URL = '/Director\:\s*<\/h4>\s*<a\s+href="([^"]+)"/isu';
    const RX_XTRA_DATA1 = '/<span\s+[^>]*itemprop="name">([^<>]+)</isu';
    const RX_XTRA_DATA2 = '/<span\s+itemprop="awards">(?:<b>|)([^<>]+)</isu';

    static protected $A_CURL_OPTS = array(
        CURLOPT_NOBODY => 0,
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_SSL_VERIFYPEER => 0,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:12.0) Gecko/20100101 Firefox/12.0',
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLINFO_HEADER_OUT => true,
        CURLOPT_HEADER => true,
    );

    static protected $A_DB_ITEMS = array(
        1 => array('url' => 'http://www.imdb.com/title/tt0111161/'),
        4 => array('url' => 'http://www.imdb.com/title/tt0068646/'),
        6 => array('url' => 'http://www.imdb.com/title/tt0468569/'),
    );

    static protected $A_DB_UAGENTS = array(
        'Mozilla/5.0 (compatible; MSIE 9.0; Windows NT 6.1; Trident/5.0; MRIE9)',
        'Opera/9.80 (Windows NT 5.1; U; Edition Next; ru) Presto/2.10.238 Version/12.00',
        'Mozilla/5.0 (Windows NT 6.1; rv:12.0a2) Gecko/20120203 Firefox/12.0a2',
    );

    protected $oMc = 0;
    protected $sCookieFile = '';
    protected $aItems = array();
    protected $aUagents = array();
    protected $aResults = array();


    public function __construct() {
        $this->sCookieFile = dirname(__FILE__). '/'. self::FILE_COOKIE;
    }

    /**
     * Run the scraper
     * @param int $nThreads
     */
    public function run($nThreads = 0) {
        $this->loadItems();
        $this->loadUagents();
        if ($nThreads <= 0)
            $nThreads = self::N_THREADS;
        $this->oMc = new RollingCurlMini($nThreads);
        $this->oMc->setOptions(self::$A_CURL_OPTS);
        for ($l = 0; $l < self::N_MAXLOOPS && count($this->aItems); $l++) {
            foreach ($this->aItems as $id => $a_item)
                $this->requestItem($id);
            $this->oMc->execute();
        }
        return $this->aResults;
    }

    public function getItems() {
        return $this->aItems;
    }

    protected function loadItems() {
        $this->aItems = self::$A_DB_ITEMS;
    }

    protected function loadUagents() {
        $this->aUagents = self::$A_DB_UAGENTS;
    }

    /**
     * Add item request to the request queue
     * @param int $id - item ID
     */
    protected function requestItem($id) {
        if (!isset($this->aItems[$id])) return;
        $url = $this->aItems[$id]['url'];
        $this->oMc->add(
            $url, 0, array($this, 'handleItem'), $id,
            $this->buildItemOptions($id, $url, true)
        );
    }

    /**
     * Handle response of item request
     * @param string $cont - content of response
     * @param string $url
     * @param array $aInfo
     * @param int $id - item ID
     */
    public function handleItem($cont, $url, $aInfo, $id) {
        if (!isset($this->aItems[$id])) return;
        if (!$cont) return;
        echo $aInfo['request_header']. $aInfo['response_header']. "\n";
        $url_xtra = $this->processItem($id, $cont);
        if (!$url_xtra)
            return;
        $this->aItems[$id]['url_xtra'] = $url_xtra;
        $this->requestItemXtra($id);
    }

    /**
     * Process content of item page (movie info)
     * @param int $id - item ID
     * @param string $cont - content of item page
     * @return string - URL of item extra resource
     */
    protected function processItem($id, $cont) {
        if (!preg_match(self::RX_XTRA_URL, $cont, $arr)) return false;
        $url_xtra = self::URL_SITE. trim($arr[1]);
        $title = preg_match(self::RX_TITLE, $cont, $arr)? trim($arr[1]) : '';
        $this->aResults[$id] = array(
            'title' => $title, 'url' => $this->aItems[$id]['url'],
        );
        return $url_xtra;
    }

    /**
     * Add item extra request to the request queue
     * @param int $id - item ID
     */
    protected function requestItemXtra($id) {
        if (!isset($this->aItems[$id])) return;
        $url = $this->aItems[$id]['url_xtra'];
        $this->oMc->add(
            $url, 0, array($this, 'handleItemXtra'), $id,
            $this->buildItemOptions($id, $url)
        );
    }

    /**
     * Handle response of item extra request
     * @param string $cont
     * @param string $url
     * @param array $aInfo
     * @param int $id - item ID
     */
    public function handleItemXtra($cont, $url, $aInfo, $id) {
        if (!isset($this->aItems[$id])) return;
        if (!$cont) return;
        echo $aInfo['request_header']. $aInfo['response_header']. "\n";
        $this->processItemXtra($id, $cont);
        unset($this->aItems[$id]);
    }

    /**
     * Process content of item extra resource (movie director info)
     * @param int $id - item ID
     * @param string $cont - content of item extra resource
     */
    protected function processItemXtra($id, $cont) {
        unset($this->aItems[$id]);
        $this->aResults[$id]['director'] = $name =
            preg_match(self::RX_XTRA_DATA1, $cont, $arr)? trim($arr[1]) : '';
        $this->aResults[$id]['director_awards'] = $awards =
            preg_match(self::RX_XTRA_DATA2, $cont, $arr)? trim($arr[1]) : '';
    }

    /**
     * Build cURL options for the current request in an item requests series
     * @param int $id - item ID
     * @param string $url - URL of requested resource
     * @param bool $b1st - is it first request in an item requests series
     */
    protected function buildItemOptions($id, $url, $b1st = false) {
        if (!isset($this->aItems[$id])) return false;
        $ra_job = &$this->aItems[$id];
        $s_cookie = sprintf($this->sCookieFile, $id);
        if ($b1st && file_exists($s_cookie)) unlink($s_cookie);
        $a_opts = array(
            CURLOPT_COOKIEFILE => $s_cookie, CURLOPT_COOKIEJAR => $s_cookie
        );
        if ($n = count($this->aUagents)) {
            if ($b1st)
                $ra_job['i_uagent'] = mt_rand(0, $n- 1);
            $a_opts[CURLOPT_USERAGENT] = $this->aUagents[$ra_job['i_uagent']];
        }
        if ($ra_job['url_prev'])
            $a_opts[CURLOPT_REFERER] = $ra_job['url_prev'];
        $ra_job['url_prev'] = $url;
        return $a_opts;
    }
}


$scraper = new Scraper();
echo '<h1>RollingCurlMini usage example</h1><pre><h2>HTTP traffic:</h2>';
$result = $scraper->run();
echo '<h2>Result:</h2>';
print_r($result);
echo '</pre>';
