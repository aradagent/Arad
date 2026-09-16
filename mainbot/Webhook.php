<?php
/**
 * @author      => Alireza jarayedi / @iamAlira / @Alirea
 * @version     => 2
 * @copyright   => Copyright (c) 2022, ©️Nova Code
 * @link        => https://nova-code.ir
 * @internal    => ©️Nova Code
 */

require_once 'config.php';

header('Content-type: application/json; charset=utf-8');
$directory = explode('/' , $_SERVER['SCRIPT_URI']);
unset($directory[count($directory)-1]);

$options = array(
    CURLOPT_URL => "https://api.telegram.org/bot" . $bot->token . "/setWebhook",
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => [
        "url" => implode('/' , $directory) . '/update.php'
    ],
    CURLOPT_RETURNTRANSFER => true,
);
// echo implode('/' , $directory) . '/update.php', PHP_EOL;
$ch = curl_init();
curl_setopt_array($ch, $options);
$response = curl_exec($ch);
curl_close($ch);
var_dump($response);


?>