<?php
/**
 * multi-curl functions wrapper class
 * a fork of Rolling Curl (http://code.google.com/p/rolling-curl/)
*/

class RollingCurlMini
{
    /**
     * max. number of simultaneous connections allowed
     */
    protected $nThreads = 10;

    /**
     * shared cURL options
     */
    protected $aOptions = array();

    /**
     * shared cURL request headers
     */
    protected $aHeaders = array();

    /**
     * default callback (called as each request is completed)
     */
    protected $fnCallback = 0;

    /**
     * timeout used for curl_multi_select function
     */
    protected $nTimeout = 10;

    /**
     * the request queue
     */
    protected $aRequests = array();

    /**
     * flag telling if the request queue execution has to be broken (aborted)
     */
    protected $bBreak = false;


    /**
     * @param int $nThreads
     */
    function __construct($nThreads = 0) {
        $this->setThreads($nThreads);
    }

    /**
     * @param int $nThreads
     */
    function setThreads($nThreads) {
        if ($nThreads > 0)
            $this->nThreads = $nThreads;
    }

    /**
     * @param array $aOptions
     */
    function setOptions($aOptions) {
        if (is_array($aOptions) && count($aOptions))
            $this->aOptions = $aOptions;
    }

    /**
     * @param array $aHeaders
     */
    function setHeaders($aHeaders) {
        if (is_array($aHeaders) && count($aHeaders))
            $this->aHeaders = $aHeaders;
    }

    /**
     * @param callback $fnCallback
     */
    function setCallback($fnCallback) {
        if (is_callable($fnCallback))
            $this->fnCallback = $fnCallback;
    }

    /**
     * @param int $nTimeout
     */
    function setTimeout($nTimeout) {
        if ($nTimeout > 0)
            $this->nTimeout = $nTimeout;
    }

    /**
     * Add a request to the request queue
     * @param string $url - requested URL
     * @param array|string|true $postdata - POST data
     * @param callback $fnCallback - individual callback (called as a request is completed)
     * @param mixed $userdata - user-defined data
     * @param array $aOptions - individual cURL options
     * @param array $aHeaders - individual cURL request headers
     */
    function add($url, $postdata = 0, $fnCallback = 0, $userdata = 0,
            $aOptions = 0, $aHeaders = 0) {
        $this->aRequests[] = array(
            $url, $postdata, $fnCallback? $fnCallback : $this->fnCallback, $userdata,
            $aOptions, $aHeaders
        );
    }

    /**
     * @return int
     */
    function count() {
        return count($this->aRequests);
    }

    /**
     * Reset the request queue
     */
    function reset() {
        $this->aRequests = array();
    }

    /**
     * Execute the request queue
     */
    function execute() {
        $this->bBreak = false;
        if (count($this->aRequests) < $this->nThreads)
            $this->nThreads = count($this->aRequests);
        // the request map that maps the request queue to request curl handles
        $a_handles = $a_reqs_map = array();
        $hcm = curl_multi_init();

        // start processing the initial request queue
        for ($i = 0; $i < $this->nThreads; $i++) {
            $a_handles[$i] = $hc = curl_init();
            curl_setopt_array($hc, $this->buildOptions($this->aRequests[$i]));
            curl_multi_add_handle($hcm, $hc);
            // add curl handle of a request to the request map
            $key = (string) $hc;
            $a_reqs_map[$key] = $i;
        }

        $n = $this->nThreads;
        do {
            while (($code = curl_multi_exec($hcm, $flag)) == CURLM_CALL_MULTI_PERFORM) ;
            if ($code != CURLM_OK)
                break;
            // a request is just completed, find out which one
            while ($a_done = curl_multi_info_read($hcm)) {
                $hc = $a_done['handle'];
                $output = curl_multi_getcontent($hc);
                $a_info = curl_getinfo($hc);
                if (!(curl_errno($hc) == 0 && intval($a_info['http_code']) == 200))
                    $output = 0;
                $key = (string) $hc;
                $i = $a_reqs_map[$key];
                list($url, , $callback, $userdata, $a_opts, ) = $this->aRequests[$i];
                if ($output &&
                    (isset($this->aOptions[CURLOPT_HEADER]) || isset($a_opts[CURLOPT_HEADER]))) {
                    $k = intval($a_info['header_size']);
                    $a_info['response_header'] = substr($output, 0, $k);
                    $output = substr($output, $k);
                }
                // remove curl handle of completed request from multi handle:
                curl_multi_remove_handle($hcm, $hc);
                $this->aRequests[$i] = 0;
                if (isset($a_handles[$i]))
                    unset($a_handles[$i]);
                // call the callback function and pass response info and user data to it
                if (is_callable($callback)) {
                    call_user_func($callback, $output, $url, $a_info, $userdata);
                }
                if ($this->bBreak) {
                    foreach ($a_handles as $i => $hc)
                        curl_multi_remove_handle($hcm, $hc);
                    break;
                }
                // add/start a new request to the request queue
                if ($n < count($this->aRequests) && isset($this->aRequests[$n])) {
                    curl_setopt_array($hc, $this->buildOptions($this->aRequests[$n]));
                    curl_multi_add_handle($hcm, $hc);
                    // add curl handle of a new request to the request map
                    $a_reqs_map[$key] = $n;
                    $a_handles[$n] = $hc;
                    $n++;
                }
                else {
                    unset($a_reqs_map[$key]);
                }
            }
            // wait for activity on any connection
            if ($flag && !$this->bBreak)
                curl_multi_select($hcm, $this->nTimeout);
        }
        while (($flag || count($a_reqs_map)) && !$this->bBreak);
        $this->reset();
        curl_multi_close($hcm);
    }

    /**
     * Ask to break execution of the request queue
     */
    function requestBreak() {
        $this->bBreak = true;
    }

    /**
     * Build individual cURL options for a request
     * @param array $aRequest - request data
     * @return array
     */
    protected function buildOptions($aRequest) {
        list($url, $postdata, , , $a_opts, $a_hdrs) = $aRequest;
        // merge shared and individual cURL options
        $a_options = $a_opts? $a_opts + $this->aOptions : $this->aOptions;
        // merge shared and individual request headers
        $a_headers = $a_hdrs? $a_hdrs + $this->aHeaders : $this->aHeaders;
        // set request URL
        $a_options[CURLOPT_URL] = $url;
        // set request headers
        if ($a_headers)
            $a_options[CURLOPT_HTTPHEADER] = $a_headers;
        // set value of referer if it has not specified
        if (!isset($a_options[CURLOPT_REFERER]) && !$a_options[CURLOPT_REFERER])
            $a_options[CURLOPT_REFERER] = substr($url, 0, strrpos($url, '/') + 1);
        // enable POST method and set POST parameters
        if ($postdata) {
            $a_options[CURLOPT_POST] = 1;
            $a_options[CURLOPT_POSTFIELDS] = is_array($postdata)?
                http_build_query($postdata) : $postdata;
        }
        return $a_options;
    }
}
