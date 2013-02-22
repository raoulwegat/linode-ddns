<?php
//
// DDNS script to change IP address on Linode hosted domain. 
// Call via cron.
//

date_default_timezone_set("Australia/Melbourne");

// logging
class Logging{  
	private $log_file = '/path/to/logs/ddns.log';  
	private $fp = null;
	public function lwrite($message){  
		if (!$this->fp) $this->lopen();  
		$script_name = pathinfo($_SERVER['PHP_SELF'], PATHINFO_FILENAME);  
		$time = date('H:i:s');  
		fwrite($this->fp, "$time ($script_name) $message\n");  
		}  
	private function lopen(){  
		$lfile = $this->log_file;  
		$today = date('Ymd');  
		$this->fp = fopen($lfile . '_' . $today, 'a') or exit("Can't open $lfile!");  
	}  
}

// set the vars
//

// Remote script on your server to get your public facing IP. It just has one line in it.
// echo $_SERVER['REMOTE_ADDR']; 
//
$url = "http://mywebserver.com/myip.php";

// Get this from the linode console
//
$api_key="GETTHISFROMLINODE";

$path = substr(__FILE__, 0, strlen(__FILE__) - strlen($_SERVER['SCRIPT_FILENAME']));

// This file is where the IP gets written to
//
$last_external_ip_file = "/path/to/public_ip.txt";

// The hostname you want updating and it's domain name
//
$ddns_hostname = "remote";
$ddns_domain = "mydomain.com";

$port = "80";
$proto = "tcp";
$log = new Logging();

// Get the url the smart way
function get_url($url) {
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_TIMEOUT, 10);
	curl_setopt($ch, CURLOPT_HEADER, 0);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_VERBOSE, 0);
	curl_setopt($ch, CURLOPT_HTTPHEADER, array("Cache-Control:no-cache","Pragma: no-cache")  );
	$data = curl_exec($ch);
	return $data;
}

// Check external IP address
function get_external_ip() {
        global $url;
        $external_ip=get_url($url);
        return $external_ip;
}

// Check previous external IP address plus update with most recent
function get_last_external_ip($external_ip) {
	global $last_external_ip_file;
	if (file_exists($last_external_ip_file)) {
		$last_external_ip = file_get_contents($last_external_ip_file);
	}
	$output = fopen("$last_external_ip_file", 'w');
	fwrite($output, $external_ip);
	fclose($output);
	return $last_external_ip;
}

// get the domain_id
function get_linode_domain_id($domain) {
	global $api_key;
	$json_url = "https://api.linode.com/?api_key=$api_key&api_action=domain.list";
	$json_file = get_url($json_url);
	$json_output = json_decode($json_file);
	if(count($json_output->ERRORARRAY) > 0) {
		return 0;
	} else {
		foreach ( $json_output->DATA as $data ) {
			$linode_domain = $data->DOMAIN;
			$linode_domain_id = $data->DOMAINID;
			if ( $domain == $linode_domain ) {
				return $linode_domain_id;
			}
		}
	}
}

// get the domain resource_id for the www A record
function get_linode_domain_resource_id($domain_id) {
	global $api_key, $ddns_hostname;
	$json_url = "https://api.linode.com/?api_key=$api_key&api_action=domain.resource.list&DomainID=$domain_id";
	$json_file = get_url($json_url);
	$json_output = json_decode($json_file);
	if(count($json_output->ERRORARRAY) > 0) {
		return 0;
	} else {
		foreach ( $json_output->DATA as $data ){
			$domain_resource_id = $data->RESOURCEID;
			$domain_resource_name = $data->NAME;
			if ( $domain_resource_name == $ddns_hostname ) {
				return $domain_resource_id;
			}
		}
	}
}

// Change the domain resource www A record IP address
function update_linode_domain_resource($domain_id, $resource_id, $target) {
	global $api_key;
	$url_update = "https://api.linode.com/?api_key=$api_key&api_action=domain.resource.update&DomainID=$domain_id&ResourceID=$resource_id&target=$target&TTL_sec=60";
	$json_file = get_url($url_update);
	$json_output = json_decode($json_file);
	if(count($json_output->ERRORARRAY) > 0) {
		return 0;
	} else {
		return $json_output->DATA->ResourceID;
	}

}

// Update the nominated A record IP address
function change_dns($target) {
	global $ddns_domain;
	$linode_domain_id = get_linode_domain_id($ddns_domain);
	$linode_domain_resource_id = get_linode_domain_resource_id($linode_domain_id);
	$resource_id_update = update_linode_domain_resource($linode_domain_id, $linode_domain_resource_id, $target);
}

$external_ip = get_external_ip();
$last_external_ip = get_last_external_ip($external_ip);

// Change the DNS if required and log the change
if ($external_ip != 0) {
	if ($external_ip != $last_external_ip) {
		change_dns($external_ip);
		$log->lwrite("DDNS external ip changed to ".$external_ip);
	} else {
		$log->lwrite("DDNS no change");
	}
}




?>

