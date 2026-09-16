<?php
/**
 * @author      => Alireza jarayedi / @iamAlira / @Alirea
 * @version     => 2
 * @copyright   => Copyright (c) 2022, ©️Nova Code
 * @link        => https://nova-code.ir
 * @internal    => ©️Nova Code
 */

date_default_timezone_set('Asia/Tehran');

require 'Database/Database.php';
require 'request/request.php';
require 'strings/settings.php';

#-------------------------------------> set define variables  <-------------------------------------#
const token    = '5937135973:AAEwK4lxar3xRM_mwvapLWNuw26VUv2c6e4' ;  // token bot  
const report = 5330629504;
const Admins   = [
    5330629504,
    1016239559,
    484167219
];
const user_bot = 'aradexchange_bot';
const CHANNEL = 'AradTransfer';
const poshtiban = 'AradTransfer_admin';
const CronJob = true;
const GROUP = 'aradcustomers';
const INFO = 'infoaradexchange';

$bot = new sisoog(
    token, 
    Admins,
    report
);

$bot->DATAUSER = [
    'type' => '',
    'name' => '',
    'country' => '',
    'arz'  => '',
    'meghdar_arz' => '',
    'mablagh_pishnehad' => '',
    'pay' => '',
    'info' => '',
];

$bot->token  = token;
$bot->set_Admin(Admins);
$bot->setDb($DBP);
unset($DBP);
#------> programer <------#
const curl   = 'curl';
error_reporting (E_ALL ^ E_NOTICE);
?>