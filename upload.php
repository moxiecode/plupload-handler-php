<?php

require_once("PluploadHandler.php");

PluploadHandler::no_cache_headers();
PluploadHandler::cors_headers();

if (!PluploadHandler::handle(array(
	'target_dir' => 'uploads/',
	'allow_extensions' => 'jpg,jpeg,png'
))) {
	die(json_encode(array('OK' => 0, 'error' => PluploadHandler::get_error())));
} else {
	die(json_encode(array('OK' => 1)));
}