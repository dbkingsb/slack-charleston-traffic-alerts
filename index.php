<?php

/**
 * TODO: Channel in ignored file
 * TODO: script output on single line
 */

include "lib/slack-api/Slack.php";
include "lib/incident.php";
date_default_timezone_set("America/New_York");
define("LIFESPAN", 600);

# TOKENS
# -----------------------------------------------

$tokensPath = "tokens.json";
if (!file_exists($tokensPath)) {
    exit("$tokensPath is needed with at least 'slack:[yourToken]'.\n");
}
$serviceTokens = json_decode(file_get_contents($tokensPath));

# KNOWN INCIDENTS
# -----------------------------------------------

$knownIncidentsPath = "knownIncidents.json";
/** @var $knownIncidents incident[] */
$knownIncidents = array();
if (file_exists($knownIncidentsPath)) {
    $knownIncidents = json_decode(file_get_contents($knownIncidentsPath), true);
    if ($knownIncidents === null || !is_array($knownIncidents)) {
        echo "Known incidents did not decode correctly\n";
        $knownIncidents = array();
    }
}

# GET ACTIVE INCIDENTS
# -----------------------------------------------

/** @var $activeIncidentsFromService incident[] */
$activeIncidentsFromService = array();
// Don Holt "32.87,-79.97,32.89,-79.92";
// Seattle "45.219,-122.325,47.610,-122.107"
// Charleston "32.746705,-80.049185,32.899666,-79.834793"
$latLongBox = "32.746705,-80.049185,32.899666,-79.834793";
$bingBaseUrl = "http://dev.virtualearth.net/REST/v1/Traffic/Incidents/";
$bingUrl = $bingBaseUrl . $latLongBox . "/?key=" . getToken("bing",$serviceTokens);
$bingJsonResponse = file_get_contents($bingUrl);
$bingResponse = json_decode($bingJsonResponse);
foreach ($bingResponse->resourceSets[0]->resources as $rawIncident) {
    $incident = new incident();
    $incident->id           = $rawIncident->incidentId;
    $incident->description  = $rawIncident->description;
    $incident->congestion   = $rawIncident->congestion;
    $incident->roadClosed   = $rawIncident->roadClosed;
    $incident->severity     = $rawIncident->severity;
    $incident->type         = $rawIncident->type;
    $incident->startTime    = getTimestampFromJsDateMs($rawIncident->start);
    $incident->endTime      = getTimestampFromJsDateMs($rawIncident->end);
    $incident->lastModified = getTimestampFromJsDateMs($rawIncident->lastModified);
    $incident->lastSeen     = time();
    $activeIncidentsFromService[] = $incident;
}

# FIND NEW AND UPDATED INCIDENTS
# -----------------------------------------------

/** @var $newIncidents incident[] */
$newIncidents = array();
$updatedIncidents = array();
foreach ($activeIncidentsFromService as $activeIncidentFromService) {
    $incidentIsNew = true;
    foreach ($knownIncidents as $knownIncident) {
        if ($activeIncidentFromService->id == $knownIncident->id) {
            if ($activeIncidentFromService->lastModified != $knownIncident->lastModified) {
                $updatedIncidents[] = array(
                    "old" => $knownIncident,
                    "new" => $activeIncidentFromService
                );
            }
            else {
                $incidentIsNew = false;
            }
            $knownIncident->lastSeen = time();
            break;
        }
    }
    if ($incidentIsNew) {
        $newIncidents[] = $activeIncidentFromService;
    }
}

# KNOWN INCIDENT BOOK KEEPING
# -----------------------------------------------
foreach ($newIncidents as $newIncident) {
    $knownIncidents[] = $newIncident;
}
$removedIncidentCount = 0;
for($i=0,$l = count($knownIncidents); $i<$l; $i++) {
    if ($knownIncidents[$i]->lastSeen < time() - LIFESPAN) {
        unset($knownIncidents[$i]);
        $removedIncidentCount++;
    }
}
file_put_contents($knownIncidentsPath, json_encode($knownIncidents));

# CHAT TO SLACK
# -----------------------------------------------

foreach ($newIncidents as $newIncident) {
    $date = getFormattedDate($newIncident->lastModified);
    $msg = "*NEW* - {$newIncident->description} _($date)_ ";
    $result = postNewIncident($msg, getToken("slack",$serviceTokens));
    if ($result["ok"] != 1) {
       echo "Failed to post incident '{$newIncident->id}'\n";
    };
}

foreach ($updatedIncidents as $updatedIncident) {
    /** @var $old incident */
    /** @var $new incident */
    $old = $updatedIncidents["old"];
    $new = $updatedIncidents["new"];
    // Find changed values
    $propertiesToChat = array();
    foreach ($new as $key => $newValue) {
        if ($newValue != $old[$key]) {
            $propertiesToChat[$key] = $newValue;
        }
    }
    // Format message
    $date = getFormattedDate($new->lastModified);
    $msg = "*UPDATE* on {$new->description}\n";
    foreach ($propertiesToChat as $key => $value) {
        $msg .= "* *$key*: $value\n";
    }
    $msg = trim($msg);
    // Send
    $result = postNewIncident($msg, getToken("slack",$serviceTokens));
    if ($result["ok"] != 1) {
        echo "Failed to post incident '{$new->id}'\n";
    };
}

exit(
    "Active incidents: " . count($knownIncidents) . "\n" .
    "New incidents: " . count($newIncidents) . "\n" .
    "Updated incidents: " . count($updatedIncidents) . "\n" .
    "Removed incidents: $removedIncidentCount\n"
);

# -----------------------------------------------
# HELPERS
# -----------------------------------------------

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

function getFormattedDate($timestamp, $format="H:i:s") {
    return date($format, $timestamp);
}

function getToken($serviceName, $serviceTokens) {
    $token = false;
    foreach ($serviceTokens as $serviceToken) {
        if ($serviceToken->serviceName == $serviceName) {
            $token = $serviceToken->token;
        }
    }
    return $token;
}

function getTimestampFromJsDateMs($rawTimestamp) {
    return intval(substr($rawTimestamp,6,-5));
}
