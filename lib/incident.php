<?php

class incident {
    var $id;
    var $congestion;
    var $description;
    var $startTime;
    var $endTime;
    var $lastModified;
    var $lastSeen;

    /**
     * @var bool
     */
    var $roadClosed;

    /**
     * @var int
     * 1: LowImpact
     * 2: Minor
     * 3: Moderate
     * 4: Serious
     */
    var $severity;

    /**
     * @var int
     * 1: Accident
     * 2: Congestion
     * 3: DisabledVehicle
     * 4: MassTransit
     * 5: Miscellaneous
     * 6: OtherNews
     * 7: PlannedEvent
     * 8: RoadHazard
     * 9: Construction
     * 10: Alert
     * 11: Weather
     */
    var $type;
}