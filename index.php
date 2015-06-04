<?php

include "lib/slack-api/Slack.php";

$token = file_get_contents("token.txt");

$slack = new Slack($token);

$results = $slack->call(
    "chat.postMessage",
    array(
        "channel" => "#bot-dev",
        "username" => "Charleston Traffic Alerts",
        "text" => "test from traffic"
    )
);

print_r($results);