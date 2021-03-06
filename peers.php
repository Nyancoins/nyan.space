<?php
require_once('peers.inc.php');

// nyancoind rpc auth
$auth = array(RPCUSER, RPCPASS);

// json-rpc
require_once('lib/Requests.php');
Requests::register_autoloader();

$url = RPCURL;	// 'http://127.0.0.1:37000'
$headers = array('Content-Type' => 'application/json');
$jsonRequest = array(
	"jsonrpc" => "1.0",
	"id" => "nyan.space.peers",
);

function do_json($data) {
	global $url, $jsonRequest, $headers, $auth;
	
	$options = array(
		'auth' => $auth
	);
	
	$d = array_merge($jsonRequest, $data);
	return Requests::post($url, $headers, json_encode($d), $options);
}

// functions
function getPeers() {
	
	$r = apcu_fetch('peers');
	if ($r === FALSE) {
		// no cached peers yet
		$r = array();
	}
	
	$now = time();
	$ttl = 60*1; // 1 minute ttl
	$ts = apcu_fetch('ts');
	$cached = $ts-$ttl;
	if($ts <= $now) { 
		// ttl expired, update
		$resp = do_json(array(
			"method" => "getpeerinfo",
			"params" => array()
		));
		if ($resp->success) {
			// Success
			$r = json_decode($resp->body, TRUE);
			apcu_store('peers', $r, $ttl);
			$cached = $now-1;
		} else {
			// Failure
			$ttl = 5; // try again in 5 seconds
		}
		apcu_store('ts', $now + $ttl); 
	}
	
	return array_merge($r, array('cached' => $cached));
}

if(isset($_GET['json'])) {
	header('Content-Type: application/json');
	die(json_encode(getpeers()));
}


// templates
require_once('lib/Twig/Autoloader.php');
Twig_Autoloader::register();
$loader = new Twig_Loader_Filesystem('tpl');
$twig = new Twig_Environment($loader, array(
	'cache' => 'tpl_c',
	'debug' => FALSE,
));
$twig->addFilter(new Twig_SimpleFilter('timeago', function ($datetime) {

  $time = time() - $datetime;

  $units = array (
	31536000 => 'year',
	2592000 => 'month',
	604800 => 'week',
	86400 => 'day',
	3600 => 'hour',
	60 => 'minute',
	1 => 'second'
  );

  foreach ($units as $unit => $val) {
	if ($time < $unit) continue;
	$numberOfUnits = floor($time / $unit);
	return /*($val == 'second')? 'a few seconds ago' : */
		   (($numberOfUnits>1) ? $numberOfUnits : 'a')
		   .' '.$val.(($numberOfUnits>1) ? 's' : '').' ago';
  }

})); // thanks http://stackoverflow.com/a/26311354

$twig->addFilter(new Twig_SimpleFilter('fixport', function ($txt) {
	$p = explode(':', $txt);
	if(count($p) != 2) {
		return $txt;
	}
	
	return $p[0] . ':33701'; // all Nyancoin wallets listen on 33701 by default, this isn't the best solution for inbound connections but it'll have to do
}));

// render
echo $twig->render('peers.html', array('peers' => getPeers(), 'debug' => json_encode(getPeers())));
