<?php
class VisitProxyClient  {
	private $apiKey;
	public $baseUrl;
	private $sessionId;
	private $body;
	private $proxyUrl;
	private $format;
	private $lang;
	private $resultStatus;
	private $resultCode;
	private $enableDebug;
	private $resultLocation;
	
	/**
	 * @return unknown
	 */
	public function getBody() {
		return $this->body;
	}
	
	public function setFormat($format) {
		$this->format = $format;
	}
	public function setLanguage($lang) {
		$this->lang = $lang;
	}
	
	private function debug($string, $name) {
		if ($this->enableDebug) {
			firep($string, $name);
		}
	}

	const PROXY_URL = "http://proxy.citybreak.com";
	
	function __construct($apiKey, $baseUrl, $url = "", $language = "en-US") {
		$this->apiKey = $apiKey;
		$this->baseUrl = $baseUrl;
		$this->proxyUrl = strlen($url) > 0 ? $url : PROXY_URL;
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
		if(isset($_SESSION['visitSessionId']))
			$cookie = "ASP.NET_SessionId=".$_SESSION['visitSessionId'];
		
		if (strlen($cookie)>0)
			$header = "Cookie: ".$cookie;
			
		if (strlen($url) > 0) {
			$requestUri = $url;	
		} else {
			$requestUri = str_replace($this->baseUrl,"",$_SERVER['REQUEST_URI']);
		}
		
		$postData = trim(file_get_contents('php://input'));
		$context_options = array ('http' => array ('method' => $method, 'header' => $header. "\r\n", 'content' => $postData, 'max_redirects' => 0, 'ignore_errors' => true ) );

		$resultCode = 0;
		$resultText  = "";
		if(strlen($requestUri) == 0 || $requestUri[0] != "/")
			$requestUri = "/" . $requestUri;
		
		$proxyUri = $this->proxyUrl.$requestUri.$this->constructParams();
		$this->debug($proxyUri, "Proxy url");
		$this->debug(var_export($postData, true), "Post data");
		if (function_exists('curl_init')) {
			$this->debug("Curl", "Request type");
			$curl = curl_init($proxyUri);
			curl_setopt($curl, CURLOPT_COOKIE, $cookie);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($curl, CURLOPT_HEADER, false);
			curl_setopt($curl, CURLOPT_HEADERFUNCTION, array(&$this,'readHeader'));
			
			if ($method == "POST") {
				curl_setopt($curl, CURLOPT_POST, true);
				curl_setopt($curl, CURLOPT_POSTFIELDS,$postData);
			}
			$this->body = curl_exec($curl);

			curl_close($curl);
		} else {
			$this->debug("FOpen", "Request type");
			$this->debug($context_options, "Context options");
			$context = stream_context_create ( $context_options );
			
			$handle = fopen ($proxyUri, 'r', false, $context );
			if($handle === false) {
				$this->handleError();
				return;
			}
			$this->debug("Handle success");
			$result = "";
			while (!feof($handle)) {
	           	$result .= fread($handle, 4096);
	       	}
			
			$meta = stream_get_meta_data ( $handle );
			$this->debug($meta, "Meta");
	       	fclose($handle);
			if(isset($meta["wrapper_data"]["headers"]))
				$headers = $meta["wrapper_data"]["headers"];
			else
				$headers = $meta["wrapper_data"];

			$matches = array();
			foreach($headers as $header) {
				$this->readHeader(null, $header);
			}
			$this->body = $result;
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
		//extracting example data: filename from header field Content-Disposition
		if (preg_match("/Set-Cookie: ASP.NET_SessionId=([A-Za-z0-9]*);/",$header,$matches)) {
			$_SESSION['visitSessionId'] = $matches[1];
		}
		if (preg_match("/HTTP\/[0-9].[0-9] ([0-9]*) /",$header,$matches)) {
			$this->resultStatus = $header;
			$this->resultCode=$matches[1];
		}
		if (strpos($header, "Location:") === 0) {
			$this->resultLocation = $header;
		}
		
		
		return strlen($header);
		
	}
	
	private function constructParams()  {
		$reqParam = (strpos($_SERVER['REQUEST_URI'],"?") === FALSE) ? "?" : "&";
		$reqParam .= "apikey=".urlencode($this->apiKey);
		$reqParam .= "&baseurl=".urlencode($this->baseUrl);
		$reqParam .= "&culture=".urlencode($this->lang);
//		$reqParam .= "&rewrite=".urlencode($this->usingRewrite ? 1:0);
		if ($this->format)
			$reqParam .= "&format=".urlencode($this->format);
		$reqParam .= "&remoteIp=".urlencode($_SERVER['REMOTE_ADDR']);
		
		return $reqParam;
	}
	
	public function baseUrlCalled() {
		return true;
	}
	
	private function handleError() {
		$this->body = "<p>There was a problem connecting to the information system</p>";
	}
}
