<?php
class VisitProxyClient  {
	private $apiKey;
	private $onlineid;
	private $legacymode;
	public $baseUrl;
	private $sessionId;
	private $body;
	private $proxyUrl;
	private $online3Url;
	private $format;
	private $lang;
	private $resultStatus;
	private $resultCode;
	private $enableDebug;
	private $resultLocation;
	private $requestUri;
	private $useOob;
	private $oobData;
	private $contentType;
	private $lastModified;
	private $cacheControl;
	private $expires;


	public function getBody() {
		return $this->body;
	}

	public function setFormat($format) {
		$this->format = $format;
	}

	public function setLanguage($lang) {
		$this->lang = $lang;
	}

	public function setOob($set) {
		$this->useOob = ($set === true);
	}

	public function getData($key) {
		if (array_key_exists($key, $this->oobData)) {
			return $this->oobData[$key];
		}
		return false;
	}

	public function hasData($key) {
		if (array_key_exists($key, $this->oobData)) {
			return true;
		}
		return false;
	}

	private function debug($string, $name) {
		if ($this->enableDebug) {
			firep($string, $name);
		}
	}

	const PROXY_URL = "http://proxy.citybreak.com";
	const PROXY_TEST_URL = "http://proxy.test.citybreak.com";
	const PROXY_TEST2_URL = "http://proxy.test2.citybreak.com";
	const ONLINE3_URL = "http://online3.citybreak.com";
	const ONLINE3_TEST_URL = "http://online3.test.citybreak.com";
	const ONLINE3_TEST2_URL = "http://online3.test2.citybreak.com";

	function __construct($apiKey, $baseUrl, $onlineid, $legacymode, $url = "", $language = "en-US") {
		$this->apiKey = $apiKey;
		$this->onlineid = $onlineid;
		$this->legacymode = $legacymode;
		$this->baseUrl = $baseUrl;
		$proxyUrl = self::PROXY_URL;
		$online3url = self::ONLINE3_URL;
		if(isset($_GET["env"])) {
			switch($_GET["env"]) {
				case "test":
					$proxyUrl = self::PROXY_TEST_URL;
					$online3url = self::ONLINE3_TEST_URL;
					break;
				case "test2":
					$proxyUrl = self::PROXY_TEST2_URL;
					$online3url = self::ONLINE3_TEST2_URL;
					break;
			}
		}
		$this->proxyUrl = strlen($url) > 0 ? $url : $proxyUrl;
		$this->online3Url = $online3url;
		$this->format = "html";
		$this->lang = $language;
		$this->resultStatus = "HTTP/1.1 200 OK";
		$this->resultCode = 200;
		$this->resultLocation = "";
		if (function_exists("firep")) {
			$this->enableDebug = true;
		} else {
			$this->enableDebug = false;
		}
		$this->errorCodeReceived = false;
	}

	function makeRequest($url = "", $noheader = false) {
		session_start();
		$method = $_SERVER['REQUEST_METHOD'];
		$cookie = "";
		$header = "";
		if (isset($_SESSION['visitSessionId' + $this->legacymode ? "_legacy" : ""])) {
			$cookie = "ASP.NET_SessionId=" . $_SESSION['visitSessionId'] ."; ";
		}
		if (!$this->legacymode) {
			$cookie .= "CitybreakProxyClient=UserHostAddress=".$_SERVER['REMOTE_ADDR']."&"."UserAgent=".urlencode($_SERVER['HTTP_USER_AGENT']).";";
		}

		if (strlen($cookie) > 0) {
			$header = "Cookie: " . $cookie;
		}

		if (strlen($url) > 0) {
			$this->requestUri = $url;
		} else {
			$this->requestUri = str_replace($this->baseUrl, "", $_SERVER['REQUEST_URI']);
		}

		$postData = trim(file_get_contents('php://input'));
		$context_options = array('http' => array ('method' => $method, 'header' => $header. "\r\n", 'content' => $postData, 'max_redirects' => 0, 'ignore_errors' => true));

		$resultCode = 0;
		$resultText  = "";
		if (strlen($this->requestUri) == 0 || $this->requestUri[0] != "/") {
			$this->requestUri = "/" . $this->requestUri;
		}

		if ($this->legacymode) {
			$proxyUri = $this->proxyUrl . $this->requestUri . $this->constructParams();
		} else {
			$proxyUri = $this->online3Url . "/" . $this->onlineid . "/". $this->lang . "/" . $this->lang . $this->requestUri;
		}
		$this->debug($proxyUri, "Proxy url");
		$this->debug(var_export($postData, true), "Post data");

		if (function_exists('curl_init')) {
			$this->debug("Curl", "Request type");
			$curl = curl_init($proxyUri);
			curl_setopt($curl, CURLOPT_COOKIE, $cookie);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($curl, CURLOPT_HEADER, false);
			curl_setopt($curl, CURLOPT_HEADERFUNCTION, array(&$this, 'readHeader'));
			curl_setopt($curl, CURLOPT_USERAGENT, "Mozilla/5.0 (compatible; CitybreakProxyClient)");

			if ($method == "POST") {
				curl_setopt($curl, CURLOPT_POST, true);
				curl_setopt($curl, CURLOPT_POSTFIELDS, $postData);
			}

			$this->body = curl_exec($curl);
			curl_close($curl);

			if ($_SERVER['SCRIPT_NAME'] != '/cron.php') {
				$this->flushData();
			}

		} else {
			$this->debug("FOpen", "Request type");
			$this->debug($context_options, "Context options");
			$context = stream_context_create($context_options);

			$handle = fopen($proxyUri, 'r', false, $context);
			if ($handle === false) {
				$this->handleError();
				return;
			}
			$this->debug("Handle success", "Handle success");
			$result = "";
			while (!feof($handle)) {
	           	$result .= fread($handle, 4096);
	       	}

			$meta = stream_get_meta_data($handle);
			$this->debug($meta, "Meta");
	       	fclose($handle);
			if (isset($meta["wrapper_data"]["headers"])) {
				$headers = $meta["wrapper_data"]["headers"];
			} else {
				$headers = $meta["wrapper_data"];
			}
			$matches = array();
			foreach($headers as $header) {
				$this->readHeader(null, $header);
			}
			$this->body = $result;

			if ($_SERVER['SCRIPT_NAME'] != '/cron.php') {
				$this->flushData();
			}

		}

		if ($this->useOob) {
			$this->oobData = $this->ExtractOobData();
		}

		if (!$noheader && strlen($this->resultStatus) > 0) {
			header($this->resultStatus);
			if ($this->resultCode >= 300 && $this->resultCode <= 399) {
				header($this->resultLocation);
			} else if ($this->resultCode != 200) {
				$this->handleError();
			}
		}
	}

	private function readHeader($curl, $header) {
		$this->debug($header, "Header");

		if (preg_match("/Set-Cookie: ASP.NET_SessionId=([A-Za-z0-9]*);/", $header, $matches)) {
			$_SESSION['visitSessionId' + $this->legacymode ? "_legacy" : ""] = $matches[1];
		}
		if (preg_match("/HTTP\/[0-9].[0-9] ([0-9]*) /", $header, $matches)) {
			$this->resultStatus = $header;
			$this->resultCode = $matches[1];
		}
		if (strpos($header, "Location:") === 0) {
			$this->resultLocation = $header;
		}
		if (strpos($header, "Content-Type:") === 0) {
			$this->contentType = $header;
		}

		if (strpos($header, "Cache-Control:") === 0) {
			$this->cacheControl = $header;
		}
		if (strpos($header, "Last-Modified:") === 0) {
			$this->lastModified = $header;
		}
		if (strpos($header, "Expires:") === 0) {
			$this->expires = $header;
		}
		return strlen($header);
	}

	private function flushData() {
		header("Expires:");
		if (strpos($this->contentType, "Content-Type: text/html") === false) {
			header($this->contentType);
			header($this->cacheControl);
			if ($this->lastModified)
				header($this->lastModified);
			if ($this->expires)
				header($this->expires);
			ob_clean();
			print $this->body;
			exit();
		}

		header($this->cacheControl);
		if ($this->lastModified)
			header($this->lastModified);
		if($this->expires)
			header($this->expires);

		return;
	}

	private function ExtractOobData() {
		$oobstart = strpos($this->body, "<!-- BEGIN OOB-DATA");
		$data = array();
		if ($oobstart > 0) {
			$oobend = strpos($this->body, "-->", $oobstart);
			$oobraw = trim(str_replace("<!-- BEGIN OOB-DATA", "", substr($this->body, $oobstart, ($oobend-$oobstart)-1)));
			$this->body = substr($this->body, 0, $oobstart);

			$oobxml = simplexml_load_string($oobraw);
			if (isset($oobxml->data)) {
				foreach ($oobxml->data->children() as $key => $value){
					$data[$key] = trim((string)$value);
				}
			}
		}

		return $data;
	}

	private function constructParams()  {
		$reqParam = (strpos($this->requestUri, "?") === FALSE) ? "?" : "&";
		$reqParam .= "apikey=".urlencode($this->apiKey);
		$reqParam .= "&baseurl=".urlencode($this->baseUrl);
		$reqParam .= "&culture=".urlencode($this->lang);

		if ($this->format) {
			$reqParam .= "&format=".urlencode($this->format);
		}

		if ($this->useOob) {
			$reqParam .= "&oob=true";
		}

		$reqParam .= "&remoteip=" . urlencode($_SERVER['REMOTE_ADDR']);

		return $reqParam;
	}

	private function handleError() {
		$this->body = "<p>There was a problem connecting to the information system</p>";
	}
}
