<?php

require_once __DIR__ . '/vendor/autoload.php';

require_once __DIR__ . '/lib/services/HomeService.php';

$homeService = ProNode::Service('/v1/home', new HomeService());
$homeService->get('/hello', 'hello');

ProNode::start();
