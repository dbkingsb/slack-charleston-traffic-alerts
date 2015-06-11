<?php

class incident {
    public $id;
    public $congestion;
    public $description;
    public $startTime;
    public $endTime;
    public $lastModified;
    public $lastSeen;

    /**
     * @var bool
     */
    public $roadClosed;

    /**
     * @var int
     */
    public $severity;


    /**
     * @var int
     */
    public $type;


    private static $severity_descriptions = array(
       1 => "Low Impact",
       2 => "Minor",
       3 => "Moderate",
       4 => "Serious"
    );

    private static $type_descriptions = array(
       1  => "Accident",
       2  => "Congestion",
       3  => "Disabled Vehicle",
       4  => "Mass Transit",
       5  => "Miscellaneous",
       6  => "Other News",
       7  => "Planned Event",
       8  => "Road Hazard",
       9  => "Construction",
       10 => "Alert",
       11 => "Weather"
    );

    function severity_as_string() {
       return array_key_exists($this->severity, self::$severity_descriptions)
          ? self::$severity_descriptions[$this->severity]
          : "Unknown";
    }

    function type_as_string() {
       return array_key_exists($this->type, self::$type_descriptions)
          ? self::$type_descriptions[$this->type]
          : "Unknown";
    }
}
