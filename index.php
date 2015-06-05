<?php

include "lib/slack-api/Slack.php";
date_default_timezone_set("America/New_York");
$counter_newIncidents = 0;
$counter_updatedIncidents = 0;
$counter_removedIncidents = 0;

# TOKENS

$tokens = array();
$tokensPath = "tokens.txt";
if (!file_exists($tokensPath)) {
   exit("$tokensPath is needed with at least 'slack:[yourToken]'.\n");
}
$lines = file($tokensPath);
foreach($lines as $line) {
    $contents = explode(":", $line);
    $tokens[trim($contents[0])] = trim($contents[1]);
}

# KNOWN INCIDENTS
# ID, last modified

$knownIncidents = array();
$knownIncidentsPath = "knownIncidents.csv";
$knownIncidentsLines = file_exists($knownIncidentsPath) ? file($knownIncidentsPath) : array();
foreach($knownIncidentsLines as $line) {
    $contents = explode(",", $line);
    $knownIncidents[trim($contents[0])] = trim($contents[1]);
}

# GET AND PROCESS SERVICE INCIDENTS

// Don Holt "32.87,-79.97,32.89,-79.92";
// Seattle "45.219,-122.325,47.610,-122.107"
// Charleston "32.746705,-80.049185,32.899666,-79.834793"
$latLongBox = "32.746705,-80.049185,32.899666,-79.834793";
$bingBaseUrl = "http://dev.virtualearth.net/REST/v1/Traffic/Incidents/";
$bingUrl = $bingBaseUrl . $latLongBox . "/?key=" . $tokens["bing"];
$bingJsonResponse = file_get_contents($bingUrl);
$bingResponse = json_decode($bingJsonResponse, true);

// Find new or changed incidents
$incidentsToReport = array();
$serviceIncidentIds = array();
foreach ($bingResponse["resourceSets"][0]["resources"] as $incident) {
    $serviceIncidentIds[] = $incident["incidentId"];
    // If we've already reported this incident in its current state
    if (in_array($incident["incidentId"], array_keys($knownIncidents))) {
        if ($incident["lastModified"] == $knownIncidents[$incident["incidentId"]]) {
            // Nothing changed, skip
            continue;
        }
        else {
            // Changed, remove this entry
            unset($knownIncidents[$incident["incidentId"]]);
            // Add to report
            $incidentsToReport[$incident["incidentId"]] = $incident;
            $incidentsToReport[$incident["incidentId"]]["update"] = true;
            $counter_updatedIncidents++;
        }
    }
    else {
        // Add to report
        $incidentsToReport[$incident["incidentId"]] = $incident;
        $counter_newIncidents++;
    }
    // Add to known incidents
    $knownIncidents[$incident["incidentId"]] = $incident["lastModified"];
}

# KNOWN INCIDENT BOOK KEEPING

// TODO: Known incidents need to be objects to report info on them after their gone from the service
$knownIncidentsIdsToRemove = array();
foreach ($knownIncidents as $knownId => $knownDate) {
    if (!in_array($knownId, $serviceIncidentIds)) {
        $msg = "*Update* - Incident '$knownId'' is no longer being reported";
        postNewIncident($msg, $tokens["slack"]);
        $knownIncidentsIdsToRemove[] = $knownId;
        $counter_removedIncidents++;
    }
}
foreach ($knownIncidentsIdsToRemove as $id) {
    unset($knownIncidents[$id]);
}

// Write known incidents to file
$knownIncidentsCSVRows = array();
foreach ($knownIncidents as $id => $date) {
    $knownIncidentsCSVRows[] = "$id,$date";
}
file_put_contents($knownIncidentsPath, implode("\n", $knownIncidentsCSVRows));

# POST NEW INCIDENTS TO SLACK

foreach ($incidentsToReport as $incident) {
    $date = getLastModifiedDate($incident);
    $state = "NEW";
    if ($incident["update"]) {
        $state = "UPDATE";
    }
    $msg = "*$state* - {$incident["description"]} _($date)_ ";
    $result = postNewIncident($msg, $tokens["slack"]);
    if ($result["ok"] != 1) {
       echo "Failed to post incident '{$incident["incidentId"]}''\n";
    };
}

exit(
    "New incidents: $counter_newIncidents\n" .
    "Updated incidents: $counter_updatedIncidents\n" .
    "Removed incidents: $counter_removedIncidents\n"
);

# FUNCTIONS

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

function getLastModifiedDate($incident, $format="H:i:s") {
    $rawTimestamp = $incident["lastModified"];
    $timestamp = substr($rawTimestamp,6,-5);
    return date($format, $timestamp);
}


