<?php

require_once("PluploadHandler.php");

$ph = new PluploadHandler(array(
	'target_dir' => '../uploads/',
	'allow_extensions' => 'jpg,jpeg,png'
));

$ph->send_nocache_headers();
$ph->send_cors_headers();

if ($result = $ph->handle_upload()) {
	die(json_encode(array(
		'OK' => 1,
		'info' => $result
	)));
} else {
	die(json_encode(array(
		'OK' => 0,
		'error' => array(
			'code' => $ph->get_error_code(),
			'message' => $ph->get_error_message()
		)
	)));
}