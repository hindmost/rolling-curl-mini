<?php
/**
 * multipurpose multi-curl scraping (crawling) framework
*/

require_once dirname(__FILE__). '/RollingCurlMini.php';

class RollingScraperAbstract
{
    /**
     * Config values
     */
    protected $aConfig = array(
        'state_time_storage' => '', // temporal section of state storage (file path)
        'state_data_storage' => '', // data section of  state storage (file path)
        'scrape_life' => 0, // expiration time (secs) of scraped data
        'run_timeout' => 30, // max. time (secs) to execute scraper script
        'run_pages_loops' => 20, // max. number of loops through pages
        'run_pages_buffer' => 500, // page requests buffer size
        'curl_threads' => 10, // number of multi-curl threads
        'curl_options' => array( // default CURL options used in multi-curl requests
            CURLOPT_NOBODY => 0,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_USERAGENT => 'RollingScraper',
        ),
    );

    /**
     * [state property:] timestamp of last scraping start
     */
    protected $tStateStart = 0;

    /**
     * [state property:] timestamp of last scraping end
     */
    protected $tStateEnd = 0;

    /**
     * [state property:] max. time (secs) to execute scraper script
     */
    protected $nStateTimeout = 0;

    /**
     * [state property:] timestamp of last scraping run start
     */
    protected $tStateRun = 0;

    /**
     * [state property:] timestamp of last scraping run end
     */
    protected $tStateStop = 0;

    /**
     * [state property:] # of pages loop
     */
    protected $iStateLoop = 0;

    /**
     * [state property:] array of all registered page request URLS
     */
    protected $aStateUrls = array();

    /**
     * [state property:] array of page request data
     */
    protected $aStateReqs = array();

    protected $oCurl = 0;
    protected $bBuffOver = false;


    function __construct() {
        $this->oCurl = new RollingCurlMini($this->aConfig['curl_threads']);
        $this->oCurl->setOptions($this->aConfig['curl_options']);
    }

    /**
     * Modify config values
     * @param array $aCfg - new config values
     */
    protected function modConfig($aCfg) {
        if (isset($aCfg['curl_options'])) {
            $this->aConfig['curl_options'] = $aCfg['curl_options'] +
                $this->aConfig['curl_options'];
            unset($aCfg['curl_options']);
        }
        $this->aConfig = array_merge($this->aConfig,
            array_intersect_key($aCfg, $this->aConfig)
        );
    }

    /**
     * Reset state properties
     * @param bool $bEnd - scraping end flag
     */
    protected function resetState($bEnd = false) {
        $this->nStateTimeout = $this->tStateRun = $this->tStateStop = 0;
        if ($bEnd)
            $this->tStateEnd = time();
        else {
            $this->tStateStart = time(); $this->tStateEnd = 0;
        }
        $this->aStateUrls = $this->aStateReqs = array();
    }

    /**
     * Restore temporal state properties from appropriate storage section
     * @return bool
     */
    protected function restoreStateTime() {
        $arr = $this->_restore($this->aConfig['state_time_storage']);
        if (!$arr || count($arr) != 5) return false;
        list($this->tStateStart, $this->tStateEnd,
            $this->nStateTimeout, $this->tStateRun, $this->tStateStop) = $arr;
        return true;
    }

    /**
     * Save temporal state properties in appropriate storage section
     */
    protected function saveStateTime() {
        $this->_save($this->aConfig['state_time_storage'], array(
            $this->tStateStart, $this->tStateEnd,
            $this->nStateTimeout, $this->tStateRun, $this->tStateStop
        ));
    }

    /**
     * Restore data state properties from appropriate storage section
     * @return bool
     */
    protected function restoreStateData() {
        $arr = $this->_restore($this->aConfig['state_data_storage']);
        if (!$arr || count($arr) != 3) return false;
        list($this->iStateLoop, $this->aStateUrls, $this->aStateReqs) = $arr;
        return true;
    }

    /**
     * Save data state properties in appropriate storage section
     */
    protected function saveStateData() {
        $this->_save($this->aConfig['state_data_storage'], array(
            $this->iStateLoop, $this->aStateUrls, $this->aStateReqs
        ));
    }

    /**
     * Get state progress - data to be used in client-side output
     * @return array
     */
    function getStateProgress() {
        return array(
            $this->tStateStart, $this->tStateEnd,
            $this->nStateTimeout, $this->tStateRun, $this->tStateStop,
            $n = count($this->aStateUrls), $n? $n- count($this->aStateReqs) : 0
        );
    }

    /**
     * Save state progress into JSON file so it can be read by AJAX request on client [MAY be overridden]
     */
    protected function _saveStateProgress() {
    }

    /**
     * Main entry point
     * @return bool
     */
    function run() {
        if (!$this->oCurl) return false;
        $this->restoreStateTime();
        $t0 = time();
        if ($this->tStateRun && $t0-$this->tStateRun-$this->nStateTimeout <= 10)
            return false;
        $n_life = $this->aConfig['scrape_life'];
        if ($this->tStateEnd) {
            if ($n_life && $t0- $this->tStateEnd <= $n_life)
                return false;
            $this->resetState();
        }
        if ($this->tStateStop &&
            $n_life && $t0 - $this->tStateStop > $n_life)
            $this->resetState();
        if ($this->tStateStop)
            $this->restoreStateData();
        $this->nStateTimeout = $this->aConfig['run_timeout'];
        set_time_limit($this->nStateTimeout? $this->nStateTimeout + 30 : 0);
        $this->tStateRun = $t0;
        $this->tStateStop = $this->tStateEnd = 0;
        $this->saveStateTime();
        $this->_saveStateProgress();
        $b = false;
        try {
            $b = $this->runPages();
        }
        catch (Exception $obj) {
        }
        if (!$b) {
            $this->tStateRun = 0; $this->tStateStop = time();
        }
        $this->saveStateTime(); $this->saveStateData();
        $this->_saveStateProgress();
        return true;
    }

    /**
     * Walk through web pages
     * @return bool
     */
    private function runPages() {
        $this->_beforeRun();
        $this->_readPauseFlag();
        $this->oCurl->reset();
        $this->oCurl->setCallback(array($this, 'callbackOnPage'));
        $l = &$this->iStateLoop;
        if (!count($this->aStateUrls)) {
            $this->_initPages();
            $l = 0;
            if (!count($this->aStateUrls)) return false;
            $this->oCurl->execute();
        }
        $n_loops = $this->aConfig['run_pages_loops'];
        $n_buff = $this->aConfig['run_pages_buffer'];
        for (; $l < $n_loops && count($this->aStateReqs); $l++) {
            $this->oCurl->reset();
            $j = 0; $b = false;
            foreach ($this->aStateReqs as $arr) {
                $this->addReq($arr);
                if ($n_buff && $j++ < $n_buff) continue;
                $this->oCurl->execute();
                $b = true;
            }
            if (!$b)
                $this->oCurl->execute();
            $this->_afterRunLoop();
        }
        $this->_beforeEnd($this->aStateUrls);
        $this->resetState(true);
        return true;
    }

    /**
     * Method to be called before each scraping run [MAY be overridden]
     */
    protected function _beforeRun() {
    }

    /**
     * Method to be called after each loop of scraping run [MAY be overridden]
     */
    protected function _afterRunLoop() {
    }

    /**
     * Method to be called before scraping end [MAY be overridden]
     * @param array $aUrls - page request URLs which will be deleted on scraping end
     */
    protected function _beforeEnd($aUrls) {
    }

    /**
     * Initialize the starting list of page requests [MUST be overridden]
     */
    protected function _initPages() {
    }

    /**
     * Callback on response of a page request
     * @param string $cont - response content
     * @param string $url - URL of request
     * @param array $aInfo - CURL info data
     * @param array $aReq - request data (data associated with request)
     */
    function callbackOnPage($cont, $url, $aInfo, $aReq) {
        $this->detectStop();
        list($i_req, ) = array_splice($aReq, 0, 2);
        $b = $this->_handlePage($cont, $url, $aInfo, $i_req, $aReq);
        if (!$b) return;
        $this->removePageReq($i_req);
        $this->_saveStateProgress();
    }

    /**
     * Process response of a page request [MUST be overridden]
     * @param string $cont - page content
     * @param string $url - url of request
     * @param array $aInfo - CURL info data
     * @param int $index - # of page request
     * @param array $aData - custom request data (part of request data)
     * @return bool
     */
    protected function _handlePage($cont, $url, $aInfo, $index, $aData) {
        return true;
    }

    /**
     * Add page request
     * @param string $url - page URL
     * @param array $aData - custom request data (part of request data)
     * @param int $iParent - # of parent page request
     * @return int|false # of added page request
     */
    protected function addPage($url, $aData = 0, $iParent = false) {
        $this->detectStop();
        $n = count($this->aStateUrls);
        if ($n && in_array($url, $this->aStateUrls)) return false;
        $this->aStateUrls[$n] = $url;
        $this->aStateReqs[$n] = $arr = array_merge(
            array($n, $iParent), is_array($aData)? $aData : array()
        );
        if ($this->bBuffOver) return $n;
        $this->addReq($arr, $url);
        if ($this->aConfig['run_pages_buffer'] && $this->oCurl->count() >= $this->aConfig['run_pages_buffer'])
            $this->bBuffOver = true;
        return $n;
    }

    /**
     * Get URL of page request by index
     * @param int $index - index/# of page request
     * @return string|false URL of page request
     */
    protected function getPageByIndex($index) {
        return $this->aStateUrls[$index];
    }

    /**
     * Find page request by URL
     * @param string $url - URL
     * @return int|false # of found page request
     */
    protected function getPageByUrl($url) {
        return array_search($url, $this->aStateUrls);
    }

    /**
     * Remove page request data
     * @param int $index - # of page request
     */
    protected function removePageReq($index) {
        if (isset($this->aStateReqs[$index]))
            unset($this->aStateReqs[$index]);
    }

    /**
     * Add multi-CURL request
     * @param array $aReq - request data (data associated with request)
     * @param string $url - URL
     */
    protected function addReq($aReq, $url = '') {
        if (count($aReq) < 2) return;
        $url = $url? $url : $this->aStateUrls[$aReq[0]];
        $i = $aReq[1];
        $a_data = array_slice($aReq, 2);
        $a_opts = $this->_buildReqOptions($a_data);
        if ($i !== false && isset($this->aStateUrls[$i])) {
            $a_ref = array(CURLOPT_REFERER => $this->aStateUrls[$i]);
            $a_opts = $a_opts? array_merge($a_opts, $a_ref) : $a_ref;
        }
        $this->oCurl->add(
            $this->_fixReqUrl($url), $this->_buildReqPost($a_data), 0, $aReq,
            $a_opts, $this->_buildReqHeaders($a_data)
        );
    }

    /**
     * Fix (result to absolute) URL of request if needed [MAY be overridden]
     * @param string $url - given URL
     * @return string
     */
    protected function _fixReqUrl($url) {
        return $url;
    }

    /**
     * Build POST data for request [MAY be overridden]
     * @param array $aData - custom request data
     * @return array|0
     */
    protected function _buildReqPost($aData) {
        return 0;
    }

    /**
     * Build CURL options for request [MAY be overridden]
     * @param array $aData - custom request data
     * @return array|0
     */
    protected function _buildReqOptions($aData) {
        return 0;
    }

    /**
     * Build HTTP headers for request [MAY be overridden]
     * @param array $aData - custom request data
     * @return array|0
     */
    protected function _buildReqHeaders($aData) {
        return 0;
    }

    protected function detectStop() {
        if ($this->nStateTimeout && time() - $this->tStateRun - $this->nStateTimeout > 0)
            throw new Exception('RollingScraperAbstract paused by Timeout', 1);
        if ($this->_readPauseFlag())
            throw new Exception('RollingScraperAbstract paused by PauseFlag', 1);
    }

    /**
     * Check pause flag. If overridden, must also reset pause flag to false [MAY be overridden]
     * @return bool
     */
    protected function _readPauseFlag() {
        return false;
    }

    /**
     * Break (abort) scraping process
     */
    protected function breakRun() {
        $this->aStateUrls = $this->aStateReqs = array();
        $this->oCurl->requestBreak();
    }

    /**
     * Restore data from specified storage [MAY be overridden]
     * @param mixed $storage - storage
     * @return mixed
     */
    protected function _restore($storage) {
        if (!$storage || !is_readable($storage)) return false;
        $s = file_get_contents($storage);
        if (!$s) return false;
        return $this->_decode($s);
    }

    /**
     * Store data in specified storage [MAY be overridden]
     * @param mixed $storage - storage
     * @param mixed $data - data
     */
    protected function _save($storage, $data) {
        if (!$storage) return;
        file_put_contents($storage, $this->_encode($data));
    }

    /**
     * Encode (serialize) data to string to prepare it for storing [MAY be overridden]
     * @param mixed $data - data
     * @return string
     */
    protected function _encode($data) {
        return serialize($data);
    }

    /**
     * Decode (unserialize) data encoded by _encode() method [MAY be overridden]
     * @param string $s - encoded data
     * @return mixed
     */
    protected function _decode($s) {
        return unserialize($s);
    }
}
