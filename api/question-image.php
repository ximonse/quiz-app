<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../ai/images.php';
$name=$_GET['file']??'';
if (!is_string($name) || !preg_match('/^(ai_[a-f0-9]{32}|img_[a-f0-9]{8})\.(png|jpg|jpeg|webp|gif)$/Di',$name) || !is_file(IMAGE_DIR.$name)) {
    http_response_code(404); exit;
}
$info=@getimagesize(IMAGE_DIR.$name);
if (!$info || !in_array($info['mime'],['image/png','image/jpeg','image/webp','image/gif'],true)) { http_response_code(404); exit; }
header('Content-Type: '.$info['mime']); header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=86400');
header('Content-Security-Policy: default-src \'none\'; sandbox');
readfile(IMAGE_DIR.$name);
