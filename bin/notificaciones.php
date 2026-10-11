<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../services/bootstrap.php';
echo json_encode((new NotificationService($db))->process(),JSON_UNESCAPED_UNICODE).PHP_EOL;
