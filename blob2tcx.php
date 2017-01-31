<?php
// if ($argc != 2) {
//     echo "${argv[0]} json\n";
//     exit(0);
// }
//
//
// $name = $argv[1];
function utcdate() {
    return gmdate("Y-m-d\TH:i:s\Z");
}

function element($dom, $parent, $element_name) {
    $element = $dom->createElement($element_name);
    $parent->appendChild($element);
    return $element;
}

function element_with_text_node($dom, $parent, $element_name, $element_text) {
    $element = $dom->createElement($element_name);
    $parent->appendChild($element);
    $node = $dom->createTextNode($element_text);
    $element->appendChild($node);
    return $element;
}

function element_with_attributes($dom, $parent, $element_name, $attributes) {
    $element = $dom->createElement($element_name);
    if(isset($parent))
        $parent->appendChild($element);
    foreach($attributes as $key => $value) {
        $attribute = $dom->createAttribute($key);
        $element->appendChild($attribute);
        $attribute_text = $dom->createTextNode($value);
        $attribute->appendChild($attribute_text);
    }
    return $element;
}

function blob2tcx($dirname, $mode, $meta)
{
    echo $meta['start_time'], " - ", $meta['end_time'];
    if (!array_key_exists('location_data', $meta) || !strlen($meta['location_data'])) {
        echo " no location data to decode\n";
        return;
    }
    $name = $dirname.'/blobs/'.$mode.'/'.$meta['location_data'];
    $string = file_get_contents($name);

    if (!strlen($string))
        return;

    if (ord($string[0]) == 0x1f && ord($string[1]) == 0x8b) {
        $json = json_decode(gzdecode($string), TRUE);
    } else
        $json = json_decode($string, TRUE);
	
	
    if (!array_key_exists('live_data', $meta) || !strlen($meta['live_data'])) {
        echo " no live data to decode\n";
        return;
    }
    $name_live = $dirname.'/blobs/'.$mode.'/'.$meta['live_data'];
    $string_live = file_get_contents($name_live);

    if (!strlen($string_live))
        return;

    if (ord($string_live[0]) == 0x1f && ord($string_live[1]) == 0x8b) {
        $json_live = json_decode(gzdecode($string_live), TRUE);
    } else
        $json_live = json_decode($string_live, TRUE);

    $dom_tcx = new DOMDocument('1.0', 'UTF-8');
    $dom_tcx->standalone = false;
    $dom_tcx->formatOutput = true;

    //root node
    $tcx = element_with_attributes($dom_tcx, NULL, 'TrainingCenterDatabase', array(
        'xmlns' => 'http://www.garmin.com/xmlschemas/TrainingCenterDatabase/v2',
        'xmlns:xsi' => 'http://www.w3.org/2001/XMLSchema-instance',
        'xsi:schemaLocation' => 'http://www.garmin.com/xmlschemas/TrainingCenterDatabase/v2 http://www.garmin.com/xmlschemas/TrainingCenterDatabasev2.xsd'
    ));
    $tcx = $dom_tcx->appendChild($tcx);


    $tcx_activities = element($dom_tcx, $tcx, 'Activities');
//     $tcx_activities = $tcx->appendChild($tcx_activities);

    $excercise = 'Other';
    switch($meta['exercise_type']) {
    case 1002: $excercise = 'Running'; break;
    case 11007: $excercise = 'Biking'; break;
    }
    $start_time = DateTime::createFromFormat ( "Y-m-d H:i:s.u" , $meta['start_time']);
    $tcx_activity = element_with_attributes($dom_tcx, $tcx_activities, 'Activity', array('Sport' => $excercise));
    element_with_text_node($dom_tcx, $tcx_activity, 'Id', gmdate("Y-m-d\TH:i:s\Z", $start_time->getTimestamp()));
    $lap = element_with_attributes($dom_tcx, $tcx_activity, 'Lap', array('StartTime' => gmdate("Y-m-d\TH:i:s\Z", $start_time->getTimestamp())));

    element_with_text_node($dom_tcx, $lap, 'TotalTimeSeconds', ($meta['duration']/1000));
    element_with_text_node($dom_tcx, $lap, 'DistanceMeters', $meta['distance']);
    element_with_text_node($dom_tcx, $lap, 'MaximumSpeed', strlen($meta['max_speed']) ? $meta['max_speed']*3.6 : '0');
    element_with_text_node($dom_tcx, $lap, 'Calories', strlen($meta['calorie']) ? $meta['calorie'] : '0');
    $lap_average_heart_rate_bpm = element_with_attributes($dom_tcx, $lap, 'AverageHeartRateBpm', array('xsi:type'=>'HeartRateInBeatsPerMinute_t'));
        element_with_text_node($dom_tcx, $lap_average_heart_rate_bpm, 'Value', strlen($meta['mean_heart_rate']) ? $meta['mean_heart_rate'] : 100);
    $lap_maximum_heart_rate_bpm = element_with_attributes($dom_tcx, $lap, 'MaximumHeartRateBpm', array('xsi:type'=>'HeartRateInBeatsPerMinute_t'));
        element_with_text_node($dom_tcx, $lap_maximum_heart_rate_bpm, 'Value', strlen($meta['max_heart_rate']) ? $meta['max_heart_rate'] : 200);
    element_with_text_node($dom_tcx, $lap, 'Intensity', 'Active');
    element_with_text_node($dom_tcx, $lap, 'TriggerMethod', 'Manual');

    $track = element($dom_tcx, $lap, 'Track');
    $loc_cnt = 0;
    $live_cnt = 0;
    $heart = 0;
    
    while (($loc_cnt < sizeof($json)) || ($live_cnt < sizeof($json_live))) {
	$data = null;
	if ($loc_cnt < sizeof($json)) {
		$data = $json[$loc_cnt];
	}
	
	$data_live = null;
	if ($live_cnt < sizeof($json_live)) {
		$data_live = $json_live[$live_cnt];
	}
	
	$live_time = 9999999999999;
	$loc_time = 9999999999999;
	if (!is_null($data)) {
		$loc_time = $data['start_time'];
	}
	if (!is_null($data_live)) 
		$live_time = $data_live['start_time'];
		
	if  ($loc_time  < $live_time) {
		$trackpoint = element($dom_tcx, $track, 'Trackpoint');
		element_with_text_node($dom_tcx, $trackpoint, 'Time', gmdate("Y-m-d\TH:i:s\Z", $data['start_time']/1000));
		$trackpoint_pos = element($dom_tcx, $trackpoint, 'Position');
		element_with_text_node($dom_tcx, $trackpoint_pos, 'LatitudeDegrees', $data['latitude']);
		element_with_text_node($dom_tcx, $trackpoint_pos, 'LongitudeDegrees', $data['longitude']);
		if(array_key_exists('altitude', $data))
		    element_with_text_node($dom_tcx, $trackpoint_pos, 'AltitudeMeters', $data['altitude']);
		if ($heart > 0) {
			$heart_rate_bpm = element_with_attributes($dom_tcx, $trackpoint, 'HeartRateBpm', array('xsi:type'=>'HeartRateInBeatsPerMinute_t'));
			element_with_text_node($dom_tcx, $heart_rate_bpm, 'Value', $heart);
		}
		$loc_cnt = $loc_cnt + 1;
	} else {
		if(array_key_exists('heart_rate', $data_live)) {
			$heart = $data_live['heart_rate'];
		}
		$live_cnt = $live_cnt + 1;
	}
    }

//     header("Content-Type: text/xml");
    file_put_contents(basename($meta['location_data']).'.tcx', $dom_tcx->saveXML());
    echo " created: ", basename($meta['location_data']).'.tcx', "\n";
}
?>


