<?php

// nyancoind rpc auth
$auth = array('nyancoinrpc', 'password');

// memcache
$mc = new Memcached("nyan.space.peers");
$mc->addServer('127.0.0.1', 11211);

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

// json-rpc
require_once('lib/Requests.php');
Requests::register_autoloader();

$url = "http://127.0.0.1:33700";
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
	global $mc;
	
	$r = $mc->get('peers');
	if ($r === FALSE) {
		// no cached peers yet
		$r = array();
	}
	
	$now = time();
	$ttl = 60*3; // 3 minute ttl
	$ts = $mc->get('ts');
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
			$mc->set('peers', $r);
			$cached = $now-1;
		} else {
			// Failure
			$ttl = 5; // try again in 5 seconds
		}
		$mc->set('ts', $now + $ttl); 
	}
	
	return array_merge($r, array('cached' => $cached));
}

// render
echo $twig->render('peers.html', array('peers' => getPeers(), 'debug' => json_encode(getPeers())));
