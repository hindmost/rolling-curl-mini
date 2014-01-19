<?php

require_once 'RollingCurlMini.inc.php';

class Scraper
{
    const N_THREADS = 10;
    const N_MAXLOOPS = 3;
    const FILE_COOKIE = 'cookie-%03d.txt';

    static protected $A_CURL_OPTS = array(
        CURLOPT_NOBODY => 0,
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_SSL_VERIFYPEER => 0,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_AUTOREFERER => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:12.0) Gecko/20100101 Firefox/12.0',
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLINFO_HEADER_OUT => true,
    );

    static protected $A_DB_ITEMS = array(
        1 => array('url' => 'http://example.com/page/1/'),
        4 => array('url' => 'http://example.com/page/4/'),
        5 => array('url' => 'http://example.com/page/5/'),
    );

    static protected $A_DB_PROXIES = array(
        '500.500.500.1:80',
        '500.500.500.2:80',
        '500.500.500.3:80',
    );

    static protected $A_DB_UAGENTS = array(
        'Mozilla/5.0 (compatible; MSIE 9.0; Windows NT 6.1; Trident/5.0; MRIE9)',
        'Opera/9.80 (Windows NT 5.1; U; Edition Next; ru) Presto/2.10.238 Version/12.00',
        'Mozilla/5.0 (Windows NT 6.1; rv:12.0a2) Gecko/20120203 Firefox/12.0a2',
    );

    protected $oMc = 0;
    protected $sCookieFile = '';
    protected $aItems = array();
    protected $aProxies = array();
    protected $aUagents = array();


    public function __construct() {
        $this->sCookieFile = dirname(__FILE__). '/'. self::FILE_COOKIE;
    }

    /**
     * Run the scraper
     * @param int $nThreads
     */
    public function run($nThreads = 0) {
        $this->loadItems();
        if (!$this->aItems) return;
        $this->loadProxies();
        $this->loadUagents();
        if ($nThreads <= 0)
            $nThreads = self::N_THREADS;
        $this->oMc = new RollingCurlMini($nThreads);
        $this->oMc->setOptions(self::$A_CURL_OPTS);
        for ($l = 0; $l < N_MAXLOOPS && count($this->aItems); $l++) {
            foreach ($this->aItems as $id => $a_item)
                $this->requestItem($id);
            $this->oMc->execute();
        }
    }

    protected function loadItems() {
        $this->aItems = self::$A_DB_ITEMS;
    }

    protected function loadProxies() {
        $this->aProxies = self::$A_DB_PROXIES;
    }

    protected function loadUagents() {
        $this->aUagents = self::$A_DB_UAGENTS;
    }

    /**
     * Add an item request to the request queue
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
        if (!$cont) {
            $this->excludeItemProxy($id);
            return;
        }
        $url_xtra = $this->processItem($cont);
        if (!$url_xtra)
            return;
        $this->aItems[$id]['url_xtra'] = $url_xtra;
        $this->requestItemXtra($id);
    }

    /**
     * Process content of item page
     * @param int $id - item ID
     * @param string $cont - content of item page
     * @return string - URL of item extra resource
     */
    protected function processItem($id, $cont) {
        $url_xtra = '';
        // parse content of item page, process the results
        // as well as find URL of item extra resource 
        // ... 
        return $url_xtra;
    }

    /**
     * Add an item extra request to the request queue
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
        $this->processItemXtra($cont);
        unset($this->aItems[$id]);
    }

    /**
     * Process content of item extra resource
     * @param int $id - item ID
     * @param string $cont - content of item extra resource
     */
    protected function processItemXtra($id, $cont) {
        // parse content of item extra resource and process the results
        // ...
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
        if ($n1 = count($this->aProxies)) {
            if ($b1st)
                $ra_job['i_proxy'] = mt_rand(0, $n1- 1);
            $a_opts[CURLOPT_PROXY] = $this->aProxies[$ra_job['i_proxy']];
            if ($n2 = count($this->aUagents)) {
                if ($b1st)
                    $ra_job['i_uagent'] = mt_rand(0, $n2- 1);
                $a_opts[CURLOPT_USERAGENT] = $this->aUagents[$ra_job['i_uagent']];
            }
        }
        if ($ra_job['url_prev'])
            $a_opts[CURLOPT_REFERER] = $ra_job['url_prev'];
        $ra_job['url_prev'] = $url;
        return $a_opts;
    }

    /**
     * Exclude proxy used on item request
     * @param int $id - item ID
     */
    protected function excludeItemProxy($id) {
        if (!isset($this->aItems[$id]) || !$this->aItems[$id]['i_proxy']) return;
        $i = $this->aItems[$id]['i_proxy'];
        if (!isset($this->aProxies[$i])) return;
        unset($this->aProxies[$i]);
        $this->aProxies = array_values($this->aProxies);
    }
}


$o_scrp = new Scraper();
$o_scrp->run();