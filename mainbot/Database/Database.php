<?php

#--------------------------------->DATABASE<---------------------------------#
/**
 * In this project, Medoo library is used to connect to the database
 * Document : https://medoo.in/doc
 * Github   : https://github.com/catfan/Medoo
 */

 /**
 * @author      => Alireza jarayedi / @iamAlira / @Alirea
 * @version     => 2
 * @copyright   => Copyright (c) 2022, ©️Nova Code
 * @link        => https://nova-code.ir
 * @internal    => ©️Nova Code
 */
require_once 'medoo/vendor/autoload.php';
use Medoo\Medoo ;


const database  = 'aradexch_bot'; # Name Database
const username  = 'aradexch_bot'; # username Database
const password  = 'dA!G&&aSq7-1'; # password Database

try{
	$DBP = new Medoo([
    	'type'      => 'mysql'       ,# type Database
		'host'      => 'localhost'   ,
		'database'  => database  	 , 
		'username'  => username  	 , 
		'password'  => password  	 , 
    	'charset'   => 'utf8mb4' 	 , 
		'collation' => 'utf8mb4_general_ci'
	]);
} catch(PDOException $error){
	if(isset($bot))
	{
		$bot->DBError($error , function($error) {
			ERROR_DB($error);
		});
	}else{
		ERROR_DB($error);
	}
}

function ERROR_DB($error)
{
	http_response_code(503);
	echo json_encode([
		'error' => $error->getMessage(),
		'code'  => $error->getCode(),
		'message' => 'Error DATABASE',
	]);
}
?>