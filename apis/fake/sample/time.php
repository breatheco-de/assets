<?php 
	header("Content-type: application/json"); 
    header("Access-Control-Allow-Origin: *");
    
    $data = [
        "hours" => date('H'),
        "minutes" => date('i'),
        "seconds" => date('s')
    ];
    echo json_encode($data);