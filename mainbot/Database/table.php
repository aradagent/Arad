<?php
/**
 * @author      => Alireza jarayedi / @iamAlira / @Alirea
 * @version     => 2
 * @copyright   => Copyright (c) 2022, ©️Nova Code
 * @link        => https://nova-code.ir
 * @internal    => ©️Nova Code
 */

header("Content-Type: application/json; charset=utf-8'");

require_once 'Database.php';

#--------------------------------->Create Table Database<---------------------------------#
$tables = [
    'account' => 
    [
        [
            "id" => [
                "INT",
                "NOT NULL",
                "AUTO_INCREMENT",
                "PRIMARY KEY"
            ],
            "step" => [
                "VARCHAR(70)"
            ],
            "chat_id" => [
                "BIGINT",
                "NOT NULL",
                "UNIQUE"
            ],
            'data' => [
                'JSON'
            ],
        ],
        [
            'CHARSET' => 'utf8mb4',
            'COLLATE' => 'utf8mb4_general_ci'
        ]
    ],
    'Block_list' => 
    [
        [
            'id' => [
                'INT',
                'NOT NULL',
                'AUTO_INCREMENT',
                'PRIMARY KEY'
            ],
            'chat_id' => [
                'BIGINT',
                'NOT NULL',
                'UNIQUE'
            ],
        ],
        [
            'CHARSET' => 'utf8mb4',
            'COLLATE' => 'utf8mb4_general_ci'
        ]
    ],
    'category' => [
        [
            'id' => [
                'INT',
                'NOT NULL',
                'AUTO_INCREMENT',
                'PRIMARY KEY'
            ],
            'name' => [
                'VARCHAR(70)',
            ],
            'parent_id' => [
                'INT'
            ]
        ],
        [
            'CHARSET' => 'utf8mb4',
            'COLLATE' => 'utf8mb4_general_ci'
        ]
    ],
    'settings' => [
        [
            'id' => [
                'INT',
                'NOT NULL',
                'AUTO_INCREMENT',
                'PRIMARY KEY'
            ],
            'name' => [
                'VARCHAR(70)',
                'NOT NULL',
                'UNIQUE'
            ],
            'value' => [
                'TEXT'
            ]
        ],
        [
            'CHARSET' => 'utf8mb4',
            'COLLATE' => 'utf8mb4_general_ci'
        ]
    ]
];

$ControllTABLE = new TABLE($tables , null , $DBP);
$ControllTABLE->handleProcess([
    'drop' => function($ControllTABLE) {
        $ControllTABLE->Drop();
        $this->handleClient(204 , 'success');
    },
    'create' => function($ControllTABLE ) {
        $ControllTABLE->Create();
        $this->handleClient(200 , 'success');
    },
    'restart' => function( $ControllTABLE) {
        $ControllTABLE->Drop();
        $ControllTABLE->Create();
        $this->handleClient(200 , 'success');
    }
] , function ( $ControllTABLE) {
    $ControllTABLE->handleClient(404 , '404 NOT Found');
});






?>




<?php

class TABLE {

    public function __construct(
        public array $tables,
        public  $key,
        public  $database
    ) {}

    public function Create()
    {
        foreach($this->tables as $table => $valu)
        {
            try{
                $this->database->create($table , $valu[0] , $valu[1]);
            }catch(PDOException $error) {
                echo $error->getMessage() , "<br>" , "<br>";
            }
        }
    }

    public function Drop()
    {
        foreach($this->tables as $table => $valu)
        {
            try{
                $this->database->drop($table);
            }catch(PDOException $error) {
                echo $error->getMessage() , "<br>" , "<br>";
            }
        }
    }

    public function handleClient(int $error ,string $message , $if_die = false)
    {
        http_response_code($error);
        echo json_encode([
            'error'  => $error,
            'message'=> $message
        ]);
        if($if_die)
            die;
    }
    public function handleProcess(array $handle , callable $ifnot)
    {
        if(!is_null($this->key))
            if($_GET['key'] != $this->key) {
                $this->handleClient(403 , 'access denied');
        }

        foreach($_GET as $key => $value)
        {
            if(isset($handle[$key]))
            {
                $handle[$key]($this);
                $this->handleClient(200 , 'success');
            }
        }    
        $ifnot($this);


    }
}

?>