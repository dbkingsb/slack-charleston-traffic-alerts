<?php

include "lib/slack-api/Slack.php";
date_default_timezone_set("America/New_York");

########
# TOKENS
########
$tokens = array();
if (!file_exists("token.txt")) {
   exit("token.txt needed with at least 'slack:<yourToken>'.\n");
}
$lines = file("token.txt");
foreach($lines as $line) {
    $contents = explode(":", $line);
    $tokens[trim($contents[0])] = trim($contents[1]);
}

###################
# KNOWN INCIDENTS
# ID, last modified
###################
$knownIncidents = array();
$knownIncidentsPath = "knownIncidents.csv";
$knownIncidentsLines = file_exists($knownIncidentsPath) ? file($knownIncidentsPath) : array();
foreach($knownIncidentsLines as $line) {
    $contents = explode(",", $line);
    $knownIncidents[trim($contents[0])] = trim($contents[1]);
}

###################################
# GET AND PROCESS SERVICE INCIDENTS
###################################

$bingBaseUrl = "http://dev.virtualearth.net/REST/v1/Traffic/Incidents/";
$donHoltBox = "32.87,-79.97,32.89,-79.92";
$somewhereTest = "45.219,-122.325,47.610,-122.107";
$charlestonBox = "32.746705,-80.049185,32.899666,-79.834793";
$bingUrl = $bingBaseUrl . $somewhereTest . "/?key=" . $tokens["bing"];
$bingJsonResponse = file_get_contents($bingUrl);
$bingResponse = json_decode($bingJsonResponse, true);

$incidentsToReport = array();

foreach ($bingResponse["resourceSets"][0]["resources"] as $incident) {
    // If we've already reported this incident in its current state
    if (in_array($incident["incidentId"], array_keys($knownIncidents))) {
        if ($incident["lastModified"] == $knownIncidents[$incident["incidentId"]]) {
            // Nothing changed, skip
            continue;
        }
        else {
            // Changed, remove this entry
            unset($knownIncidents[$incident["incidentId"]]);
        }
    }
    // Add to known incidents
    $knownIncidentsLines[] = $incident["incidentId"] . "," . $incident["lastModified"];
    // Add to report
    $incidentsToReport[] = $incident;
}

// Write known incidents to file
for ($i=0; $i<count($knownIncidentsLines); $i++) {
    $knownIncidentsLines[$i] = trim($knownIncidentsLines[$i]);
}
file_put_contents($knownIncidentsPath, implode("\n", $knownIncidentsLines));

#############################
# PUSH NEW INCIDENTS TO SLACK
#############################

foreach ($incidentsToReport as $incident) {
    // Extract date
    $rawTimestamp = $incident["lastModified"];
    $timestamp = substr($rawTimestamp,6,-5);
    $date = date("m.d.y H:i:s", $timestamp);
    // Make message
    $msg = $date . " " . $incident["description"];
    $result = postNewIncident($msg, $tokens["slack"]);
    print_r($result);
}

exit;

###########
# FUNCTIONS
###########

function postNewIncident($msg, $token) {
    $slack = new Slack($token);
    $results = $slack->call(
        "chat.postMessage",
        array(
            "channel" => "#bot-dev",
            "username" => "Charleston Traffic Alerts",
            "icon_url" => "http://itblog.sandisk.com/wp-content/uploads/2014/02/Pommi_Traffic_Sign.png",
            "icon_emoji" => ":vertical_traffic_light:", //construction, vertical_traffic_light, rotating_light
            "text" => $msg
        )
    );
    return $results;
}
