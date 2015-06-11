<?php

/**
 * Don Holt "32.87,-79.97,32.89,-79.92";
 * Seattle "45.219,-122.325,47.610,-122.107"
 * Charleston "32.746705,-80.049185,32.899666,-79.834793"
 */

include "lib/slack-api/Slack.php";
include "lib/incident.php";
date_default_timezone_set("America/New_York");
define("LIFESPAN", 600);

# OPTIONS
# -----------------------------------------------

$optionsPath = "options.json";
if (!file_exists($optionsPath)) {
    exit("$optionsPath is needed.\n");
}
$options = json_decode(file_get_contents($optionsPath));

# TOKENS
# -----------------------------------------------

$tokensPath = "tokens.json";
if (!file_exists($tokensPath)) {
    exit("$tokensPath is needed.\n");
}
$serviceTokens = json_decode(file_get_contents($tokensPath));

# KNOWN INCIDENTS
# -----------------------------------------------

$knownIncidentsPath = "knownIncidents.json";
/** @var $knownIncidents incident[] */
$knownIncidents = array();
if (file_exists($knownIncidentsPath)) {
    $knownIncidents = json_decode(file_get_contents($knownIncidentsPath));
    if ($knownIncidents === null || !is_array($knownIncidents)) {
        echo "Known incidents did not decode correctly\n";
        $knownIncidents = array();
    }
}

# GET ACTIVE INCIDENTS
# -----------------------------------------------

/** @var $activeIncidentsFromService incident[] */
$activeIncidentsFromService = array();
$bingBaseUrl = "http://dev.virtualearth.net/REST/v1/Traffic/Incidents/";
$bingUrl = $bingBaseUrl . $options->area . "/?key=" . getToken("bing", $serviceTokens);
$bingJsonResponse = file_get_contents($bingUrl);
$bingResponse = json_decode($bingJsonResponse);
foreach ($bingResponse->resourceSets[0]->resources as $rawIncident) {
    $incident = new incident();
    $incident->id = $rawIncident->incidentId;
    $incident->description = $rawIncident->description;
    $incident->congestion = $rawIncident->congestion;
    $incident->roadClosed = $rawIncident->roadClosed;
    $incident->severity = $rawIncident->severity;
    $incident->type = $rawIncident->type;
    $incident->startTime = getTimestampFromJsDateMs($rawIncident->start);
    $incident->endTime = getTimestampFromJsDateMs($rawIncident->end);
    $incident->lastModified = getTimestampFromJsDateMs($rawIncident->lastModified);
    $incident->lastSeen = time();
    $activeIncidentsFromService[] = $incident;
}

# FIND NEW AND UPDATED INCIDENTS
# -----------------------------------------------

/** @var $newIncidents incident[] */
$newIncidents = array();
$updatedIncidents = array();
foreach ($activeIncidentsFromService as $activeIncidentFromService) {
    $incidentIsNew = true;
    foreach ($knownIncidents as &$knownIncident) {
        if ($activeIncidentFromService->id == $knownIncident->id) {
            if ($activeIncidentFromService->lastModified != $knownIncident->lastModified) {
                $updatedIncidents[] = array(
                    "old" => $knownIncident,
                    "new" => $activeIncidentFromService
                );
                $knownIncident = unserialize(serialize($activeIncidentFromService));
            }
            $incidentIsNew = false;
            $knownIncident->lastSeen = time();
            break;
        }
    }
    if ($incidentIsNew) {
        $newIncidents[] = $activeIncidentFromService;
    }
}

# CHAT TO SLACK
# -----------------------------------------------

foreach ($newIncidents as $newIncident) {
    $attachments = createNewIncidentAttachments($newIncident);
    $result = postAlert($attachments);
    if (!$result) {
        // TODO: Remove this failure from the $newIncidents array so it doesn't get
        // saved as a known.
    }
}

$actualUpdatedIncidentCount = 0;
foreach ($updatedIncidents as $updatedIncident) {
    /** @var $old incident */
    /** @var $new incident */
    $old = $updatedIncident["old"];
    $new = $updatedIncident["new"];
    // Find changed values
    $propertiesToChat = array();
    foreach ($new as $key => $newValue) {
        if ($key == "lastModified" || $key == "lastSeen") {
            continue;
        }
        if ($newValue != $old->$key) {
            $propertiesToChat[$key] = $newValue;
        }
    }
    if (count($propertiesToChat) < 1) {
        continue;
    }
    $actualUpdatedIncidentCount++;
    $attachments = createUpdateIncidentAttachments($old, $new, $propertiesToChat);
    $result = postAlert($attachments);
}

# KNOWN INCIDENT BOOK KEEPING
# -----------------------------------------------
foreach ($newIncidents as $newIncident) {
    $knownIncidents[] = $newIncident;
}
$removedIncidentCount = 0;
for ($i = 0, $l = count($knownIncidents); $i < $l; $i++) {
    if ($knownIncidents[$i]->lastSeen < time() - LIFESPAN) {
        unset($knownIncidents[$i]);
        $removedIncidentCount++;
    }
}
array_values($knownIncidents);
file_put_contents($knownIncidentsPath, json_encode($knownIncidents));

# EXIT
# -----------------------------------------------

$exitMsg = date("y-m-d H:i:s") . "\n";
if (count($newIncidents) || $actualUpdatedIncidentCount || $removedIncidentCount) {
    $exitMsg .=
        "Active incidents: " . count($knownIncidents) . "\n" .
        "New incidents: " . count($newIncidents) . "\n" .
        "Updated incidents: " . $actualUpdatedIncidentCount . "\n" .
        "Removed incidents: $removedIncidentCount\n";
}
exit($exitMsg);

# -----------------------------------------------
# FUNCTIONS
# -----------------------------------------------

/**
 * @var $incident incident
 * @return attachments
 */
function createNewIncidentAttachments($incident)
{
    $date = getFormattedDateTime($incident->startTime);
    $fields = array(
        array(
            "title" => "Type",
            "value" => $incident->type_as_string(),
            "short" => true
        ),
        array(
            "title" => "Severity",
            "value" => $incident->severity_as_string(),
            "short" => true
        )
    );
    if ($incident->congestion) {
        $fields[] = array(
            "title" => "Congestion",
            "value" => $incident->congestion,
            "short" => true
        );
    }
   if ($incident->roadClosed) {
        $fields[] = array(
            "title" => "Road Closed",
            "value" => "Yes, avoid!",
            "short" => true
        );
    }
    $attachments = array(
        array(
            "fallback" => "*NEW _($date)_*\n{$incident->description}",
            "pretext" => "New traffic alert reported on $date",
            "title" => $incident->description,
            "color" => "#FF0000",
            "mrkdwn_in" => array("pretext"),
            "fields" => $fields
        )
    );
    return $attachments;
}

/**
 * @var $old incident
 * @var $new incident
 * @return attachments
 */
function createUpdateIncidentAttachments($old, $new, $changedProperties)
{
    $date = getFormattedDateTime($new->lastModified);
    $time = getFormattedDateTime($new->lastModified, "h:iA");
    $fields = array();
    foreach ($changedProperties as $key => $value) {
        $fields[] = array(
            "title" => ucfirst($key),
            "value" => $value,
            "short" => true
        );
    }
    $attachments = array(
        array(
            "fallback" => "*UPDATE _($date)_*\n{$old->description}",
            "pretext" => "*Update* of \"{$old->description}\" at $time",
            "title" => "The following properties have changed:",
            "color" => "#FF0000",
            "mrkdwn_in" => array("pretext"),
            "fields" => $fields
        )
    );
    return $attachments;
}

function postAlert($attachments)
{
    global $options, $serviceTokens;
    $slack = new Slack(getToken("slack", $serviceTokens));
    $results = $slack->call(
        "chat.postMessage",
        array(
            "channel" => $options->channel,
            "username" => "Charleston Traffic Alerts",
            "icon_emoji" => ":vertical_traffic_light:", //construction, vertical_traffic_light, rotating_light
            "attachments" => json_encode($attachments)
        )
    );
    if (!$results["ok"]) {
        echo "Failed to post message: '{$results["error"]}'\n";
        return false;
    }
    return true;
}

function getFormattedDateTime($timestamp, $format = "D. M. jS, h:iA")
{
    return date($format, $timestamp);
}

function getToken($serviceName, $serviceTokens)
{
    $token = false;
    foreach ($serviceTokens as $serviceToken) {
        if ($serviceToken->serviceName == $serviceName) {
            $token = $serviceToken->token;
        }
    }
    return $token;
}

function getTimestampFromJsDateMs($rawTimestamp)
{
    return intval(substr($rawTimestamp, 6, -5));
}
