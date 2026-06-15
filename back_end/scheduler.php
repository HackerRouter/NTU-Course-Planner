<?php
set_time_limit(55);

require("config.php");

# Get the database
$database_course = json_decode(file_get_contents("data/parsed/json/". $year . "_" . $semester . "_data.json"), true);
$database_exam = json_decode(file_get_contents("data/parsed/json/". $year . "_" . $semester . "_exam_data.json"), true);

/* ---------------------------------------------------------------------------------------------- */

# make times as key at MON, TUE, etc.
$times = array( "0830" => array(),"0900" => array(),"0930" => array(),"1000" => array(),"1030" => array(),"1100" => array(),"1130" => array(),"1200" => array(),"1230" => array(),"1300" => array(),"1330" => array(),
                "1400" => array(),"1430" => array(),"1500" => array(),"1530" => array(),"1600" => array(),"1630" => array(),"1700" => array(),"1730" => array(),"1800" => array(),"1830" => array(),"1900" => array(),
                "1930" => array(),"2000" => array(),"2030" => array(),"2100" => array(),"2130" => array(),"2200" => array(),"2230" => array(),"2300" => array());

# Global time slot definitions for reuse
$TIME_SLOTS = array("0830", "0900", "0930", "1000", "1030", "1100", "1130", "1200", "1230", "1300", "1330",
                    "1400", "1430", "1500", "1530", "1600", "1630", "1700", "1730", "1800", "1830", "1900",
                    "1930", "2000", "2030", "2100", "2130", "2200", "2230", "2300");
$TIME_INDEX = array_flip($TIME_SLOTS);
$LUNCH_TIMES = array("1100", "1130", "1200", "1230", "1300");
$DAYS = array("MON", "TUE", "WED", "THU", "FRI", "SAT");

# Cache for remarks_to_weeks to avoid redundant parsing
$remarks_cache = array();

# STRUCTURE FOR TIMETABLE
$timetable = array(
    "MON" => $times,
    "TUE" => $times,
    "WED" => $times,
    "THU" => $times,
    "FRI" => $times,
    "SAT" => $times
    );

$all_timetable = array();
$too_many_solutions = FALSE;
$search_iterations = 0;
$MAX_ITERATIONS = 5000000;
$search_start_time = microtime(true);
$MAX_SEARCH_TIME = 45;

# Sleep More: branch-and-bound tracking
$best_sleep_score = 0;

/* ---------------------------------------------------------------------------------------------- */

# Get the string of courses from the form and split it to an array
$input_courses = explode(",", preg_replace("/\s+/", "", strtoupper($_REQUEST["courses"])));
$user_major= strtoupper($_REQUEST["major"]);
$user_free_times_selection = $_REQUEST["freetime"];
$need_lunch_break = isset($_REQUEST["need_lunch_break"]) && $_REQUEST["need_lunch_break"] === 'true' ? true : false;
$need_cc_break = isset($_REQUEST["need_cc_break"]) && $_REQUEST["need_cc_break"] === 'true' ? true : false;
$need_sleep_more = isset($_REQUEST["need_sleep_more"]) && $_REQUEST["need_sleep_more"] === 'true' ? true : false;
$cannot_teleport = isset($_REQUEST["cannot_teleport"]) && $_REQUEST["cannot_teleport"] === 'true' ? true : false;



# Check sent data
if (isset($input_courses)) {
    $len = count($input_courses);
    for ($i = 0; $i < $len; $i++) {
        if ($input_courses[$i] === "") {
            unset($input_courses[$i]);
        }
    }

    # Put dummy data for user selected free time
    $timetable = select_free_time($timetable, $user_free_times_selection);

    $result = array("validation_result" => validate_input($input_courses, $database_course));
    if ($result["validation_result"]) {
        $result["exam_schedule_validation"] = check_exam_schedule($input_courses);
        $result["exam_schedule"] = $exam_schedule;
    }

    # Filter HW0188 based on the user major
    filter_HW0188_timetable($user_major);

    # Filter HY0001 off when generating timetable
    {
        $key = array_search('HY0001', $input_courses, true);
        if ($key !== FALSE) {
            unset($input_courses[$key]);
        }
        # "reindex" the array
        $input_courses = array_values($input_courses);
    }

    # Precompute slot indices for all course details
    foreach ($database_course as &$course_data) {
        foreach ($course_data['index'] as &$idx) {
            $idx['_has_morning'] = false;
            foreach ($idx['details'] as &$det) {
                $det['_start_idx'] = time_to_slot_idx($det['time']['start']);
                $det['_end_idx'] = time_to_slot_idx($det['time']['end']);
                if ($det['_start_idx'] === 0) {
                    $idx['_has_morning'] = true;
                }
            }
            unset($det);
        }
        unset($idx);

        if ($need_sleep_more) {
            usort($course_data['index'], function($a, $b) {
                return (int)$a['_has_morning'] - (int)$b['_has_morning'];
            });
        }
    }
    unset($course_data);

    # Generate all possible timetables
    generate_timetable($input_courses, $timetable);
    
    $result["timetable"] = $all_timetable;
    $result["too_many_results"] = $too_many_solutions;

    echo json_encode($result);
}

/* ---------------------------------------------------------------------------------------------- */

# Check whether it is a valid course code
function validate_input ($input_courses, $database_course) {
    foreach ($input_courses as $course) {
        if (!array_key_exists($course, $database_course)) {
            return false;
        }
    }

    return true;
}

# Put dummy data on the timetable template for blocking the USER FREE TIME SELECTION
function select_free_time ($timetable, $user_selection) {
    foreach ($user_selection as $day => $times) {
        foreach ($times as $time => $free_time) {
            if ($free_time === "true") {
                $timetable[$day][$time] = true;
            }
        }
    }

    return $timetable;
}

# If there is a clash, stop it there
function check_exam_schedule ($input_courses) {
    global $database_exam, $database_course, $exam_schedule;
    $ret = array("ok" => true, "conflict" => []);

    foreach ($input_courses as $course) {
        $exam = get_exam_details($course, $database_exam);
        if ($exam === -1) {
            $exam_date = -1;
            $exam_time = -1;

            $exam = [];
            $exam["au"] = trim($database_course[$course]['au']);;
            $exam["code"] = $course;
            $exam["date"] = -1;
            $exam["day"] = -1;
            $exam["duration"] = -1;
            $exam["end_time"] = -1;
            $exam["name"] = trim($database_course[$course]["name"]);
            $exam["time"] = -1;
            $exam_schedule[$course][$course] = $exam;
        } else {
            $exam_date = $exam["date"];
            $exam_time = $exam["time"];

            // parse time
            $hour = intval($exam["time"][0]);
            $minutes = intval($exam["time"][2] . $exam["time"][3]);
            if ($exam["time"][5] . $exam["time"][6] === "pm") {
                $hour += 12;
            }
            $exam["time"] = pad($hour) . pad($minutes);

            $time = ($hour * 60 + $minutes) + $exam["duration"] * 60;
            $hour = (int) ($time / 60);
            $minutes = (int) ($time % 60);

            $exam["end_time"] = pad($hour) . pad($minutes);
            $exam["au"]= trim($database_course[$course]['au']);

            if (isset($exam_schedule[$exam_date][$exam_time])) {
                $ret['ok'] = false;
                array_push($ret['conflict'], [$exam_schedule[$exam_date][$exam_time]["code"], $exam["code"]]);
            } else {
                $exam_schedule[$exam_date][$exam_time] = $exam;
            }
        }
    }

    return $ret;
}


# Get exam details based on the course ID
function get_exam_details ($course_id, $database_exam) {
    if (!array_key_exists($course_id, $database_exam)) {
        return -1;
    }

    return $database_exam[$course_id];
}

/* ---------------------------------------------------------------------------------------------- */

/*
    Data to store in ONE SLOT:
    - Course ID
    - Index number
    - Flag
*/

# Generate ALL POSSIBLE timetables!
$temp_timetable = $timetable;
function generate_timetable ($input_courses, $temp_timetable) {
    global $database_course, $all_timetable, $too_many_solutions, $need_lunch_break, $need_cc_break, $need_sleep_more, $cannot_teleport, $best_sleep_score, $DAYS, $search_iterations, $MAX_ITERATIONS, $search_start_time, $MAX_SEARCH_TIME;
    $original_timetable = $temp_timetable;

    # Too many solutions found
    if ($too_many_solutions) {
        return;
    }

    # One solution is found
    if (count($input_courses) == 0) {
        // Don't store empty keys
        foreach ($temp_timetable as $day => $times) {
            foreach ($times as $time => $indices) {
                if (empty($indices))
                    unset($temp_timetable[$day][$time]);
            }
            if (empty($temp_timetable[$day]))
                unset($temp_timetable[$day]);
        }
        
        // Sleep More: branch-and-bound - compute score and compare with best
        if ($need_sleep_more) {
            $score = compute_sleep_score($temp_timetable);
            if ($score > $best_sleep_score) {
                $best_sleep_score = $score;
                $all_timetable = array($temp_timetable);
            } else if ($score === $best_sleep_score) {
                if (count($all_timetable) >= 10000) {
                    $too_many_solutions = TRUE;
                    return;
                }
                array_push($all_timetable, $temp_timetable);
            }
            return;
        }
        
        if (count($all_timetable) >= 10000) {
            $too_many_solutions = TRUE;
            return;
        }
        array_push($all_timetable, $temp_timetable);

        return;
    }

    // Sleep More: upper-bound pruning
    if ($need_sleep_more && $best_sleep_score >= 0) {
        $occupied_mornings = 0;
        foreach ($DAYS as $d) {
            if (!empty($temp_timetable[$d]["0830"])) {
                $occupied_mornings++;
            }
        }
        if ((6 - $occupied_mornings) < $best_sleep_score) {
            return;
        }
    }

    # Data retrieval
    $course = $database_course[$input_courses[0]];
    $course_id = $input_courses[0];
    $indices = $course["index"]; # Contains all index of a subject

    # Checking of timetable (clash or not) for EACH AVAILABLE INDEX
    foreach ($indices as $index) {
        if ($too_many_solutions) return;

        $search_iterations++;
        if ($search_iterations >= $MAX_ITERATIONS || ($search_iterations & 4095) === 0 && (microtime(true) - $search_start_time) >= $MAX_SEARCH_TIME) {
            $too_many_solutions = TRUE;
            return;
        }

        $index_no = $index["index_number"];
        $index_details = $index["details"];
        $skip = false;

        foreach ($index_details as $detail) {
            # Check for clash, for each index detail (for each lecture, each tutorial in one index)
            $clash = check_clash($course_id, $index_no, $detail, $temp_timetable);

            if ($clash) {
                $skip = true;
                break;
            }

            # Assign to timetable
            $temp_timetable = assign_course($course_id, $index_no, $detail, $temp_timetable);
        }

        # Skip the recursion as there is a clash in this index
        # Continue to the next index
        if ($skip) {
            $temp_timetable = $original_timetable;
            continue;
        }

        # Reduce to get termination condition later
        $popped = array_shift($input_courses);
        generate_timetable($input_courses, $temp_timetable); # Recursion

        # Backtracking
        $temp_timetable = $original_timetable;
        array_unshift($input_courses, $popped);
    }
}

# Compute sleep score: count days with no class at 0830
function compute_sleep_score($timetable) {
    global $DAYS;
    $score = 0;
    foreach ($DAYS as $day) {
        if (!isset($timetable[$day]) || !isset($timetable[$day]["0830"]) || empty($timetable[$day]["0830"])) {
            $score++;
        }
    }
    return $score;
}

function time_to_slot_idx($time_str) {
    global $TIME_SLOTS, $TIME_INDEX;
    static $cache = array();
    if (isset($cache[$time_str])) {
        return $cache[$time_str];
    }
    if (isset($TIME_INDEX[$time_str])) {
        $cache[$time_str] = $TIME_INDEX[$time_str];
        return $TIME_INDEX[$time_str];
    }
    $lo = 0;
    $hi = count($TIME_SLOTS);
    while ($lo < $hi) {
        $mid = ($lo + $hi) >> 1;
        if ($TIME_SLOTS[$mid] < $time_str) {
            $lo = $mid + 1;
        } else {
            $hi = $mid;
        }
    }
    $cache[$time_str] = $lo;
    return $lo;
}

function check_clash($course_id, $index_no, $detail, $temp_timetable) {
    global $need_cc_break, $cannot_teleport, $need_lunch_break, $TIME_SLOTS, $TIME_INDEX, $LUNCH_TIMES;
    
    $day = $detail["day"];
    $start_idx = $detail["_start_idx"];
    $end_idx = $detail["_end_idx"];

    $new_weeks = remarks_to_weeks_cached($detail["remarks"]);

    for ($i = $start_idx; $i < $end_idx; $i++) {
        $slot = $temp_timetable[$day][$TIME_SLOTS[$i]];

        if ($slot === true) return true;

        if (is_array($slot) && count($slot) > 0) {
            foreach ($slot as $existing) {
                $exist_weeks = remarks_to_weeks_cached($existing["remarks"]);

                $overlap = false;
                for ($w = 0; $w < 13; $w++) {
                    if ($new_weeks[$w] && $exist_weeks[$w]) {
                        $overlap = true;
                        break;
                    }
                }

                if ($overlap) {
                    if ($existing["id"] === $course_id) {
                        continue;
                    } else {
                        return true;
                    }
                }
            }
        }
    }

    if ($need_lunch_break) {
        if (!check_lunch_break_early($day, $detail["time"]["start"], $detail["time"]["end"], $temp_timetable)) {
            return true;
        }
    }

    if ($cannot_teleport) {
        if (!check_cannot_teleport_early($course_id, $detail, $temp_timetable)) {
            return true;
        }
    }

    if ($need_cc_break) {
        if (!check_cc_break_conflict($course_id, $detail, $temp_timetable)) {
            return true;
        }
    }

    return false;
}


# Assign course for each index detail one by one
function assign_course($course_id, $index_no, $detail, $temp_timetable) {
    global $TIME_SLOTS;

    $data = array(
        "id" => $course_id,
        "index" => $index_no,
        "flag" => $detail["flag"],
        "type" => $detail["type"],
        "location" => $detail["location"],
        "group" => $detail["group"],
        "remarks" => $detail["remarks"]
    );

    $start_idx = $detail["_start_idx"];
    $end_idx = $detail["_end_idx"];
    $day = $detail["day"];

    for ($i = $start_idx; $i < $end_idx; $i++) {
        $temp_timetable[$day][$TIME_SLOTS[$i]][] = $data;
    }

    return $temp_timetable;
}

/* ---------------------------------------------------------------------------------------------- */
# Check CC break constraint during course assignment (early constraint)
function check_cc_break_conflict($new_course_id, $new_detail, $temp_timetable) {
    global $TIME_SLOTS, $TIME_INDEX;
    
    $day = $new_detail["day"];
    $new_start_idx = $new_detail["_start_idx"];
    $new_end_idx = $new_detail["_end_idx"] - 1;
    
    if (!isset($temp_timetable[$day])) {
        return true;
    }
    
    $is_new_cc = (bool) preg_match('/^(ML|CC)\d+/', $new_course_id);
    $slot_count = count($TIME_SLOTS);
    
    if ($is_new_cc) {
        $new_before = $new_start_idx - 1;
        $new_after = $new_end_idx + 1;
        
        if ($new_before >= 0 && !empty($temp_timetable[$day][$TIME_SLOTS[$new_before]])) {
            return false;
        }
        if ($new_after < $slot_count && !empty($temp_timetable[$day][$TIME_SLOTS[$new_after]])) {
            return false;
        }
        return true;
    }
    
    $scan_start = max(0, $new_start_idx - 1);
    $scan_end = min($slot_count - 1, $new_end_idx + 1);
    
    for ($si = $scan_start; $si <= $scan_end; $si++) {
        $slot = $temp_timetable[$day][$TIME_SLOTS[$si]];
        if (empty($slot)) continue;
        
        foreach ($slot as $course) {
            if (!preg_match('/^(ML|CC)\d+/', $course['id'])) continue;
            
            $cc_start = $si;
            $cc_end = $si;
            for ($j = $si + 1; $j < $slot_count; $j++) {
                $next_slot = $temp_timetable[$day][$TIME_SLOTS[$j]];
                if (empty($next_slot)) break;
                $found = false;
                foreach ($next_slot as $nc) {
                    if ($nc['id'] === $course['id'] && $nc['index'] === $course['index']) {
                        $found = true;
                        break;
                    }
                }
                if ($found) { $cc_end = $j; } else { break; }
            }
            
            $cc_before = $cc_start - 1;
            $cc_after = $cc_end + 1;
            
            if ($cc_before >= 0 && $new_start_idx <= $cc_before && $cc_before <= $new_end_idx) {
                return false;
            }
            if ($cc_after < $slot_count && $new_start_idx <= $cc_after && $cc_after <= $new_end_idx) {
                return false;
            }
        }
    }
    
    return true;
}

/* ---------------------------------------------------------------------------------------------- */
/* ---------------------------------------------------------------------------------------------- */

function filter_HW0188_timetable ($user_major) {
    global $database_course;
    $HW0188_unfiltered = $database_course["HW0188"]["index"];
    $HW0188_filtered = array();

    foreach ($HW0188_unfiltered as $i) {
        if (strpos($i["details"][0]["group"], $user_major) !== false) {
            array_push($HW0188_filtered, $i);
        }
    }

    $database_course["HW0188"]["index"] = $HW0188_filtered;
}

/* ---------------------------------------------------------------------------------------------- */


/* ---------------------------------------------------------------------------------------------- */
// Helper function
function pad ($num) {
    if ($num < 10) return "0" . $num;
    return $num;
}

/**
 *
 * @param  String $remarks      Remarks string
 * @return Array                Boolean array of size 13 (0-based), indicating whether course is held on week i or not
 */
function remarks_to_weeks ($remarks) {
    $ret = [];
    for ($i = 0; $i < 13; $i++) {
        $ret[$i] = false;
    }
    $start = stripos($remarks, "wk");
    if ($start === false) {
        for ($i = 0; $i < 13; $i++) {
            $ret[$i] = true;
        }
        return $ret;
    }
    $start += 2;

    $cur_val = 0;
    $cur_val2 = 0;
    $range = false;
    for ($i = $start; $i < strlen($remarks); $i++) {
        if ('0' <= $remarks[$i] && $remarks[$i] <= '9') {
            if ($range)
                $cur_val2 = $cur_val2 * 10 + intval($remarks[$i]);
            else
                $cur_val = $cur_val * 10 + intval($remarks[$i]);
        } else if ($remarks[$i] === '-') {
            $range = true;
        } else if ($remarks[$i] === ',') {
            if ($range) {
                for ($wk = $cur_val; $wk <= $cur_val2; $wk++) {
                    $ret[$wk] = true;
                }
            } else {
                $ret[$cur_val] = true;
            }

            $cur_val = 0;
            $cur_val2 = 0;
            $range = false;
        }
    }
    if ($cur_val !== 0)
        if ($range) {
            for ($wk = $cur_val; $wk <= $cur_val2; $wk++) {
                $ret[$wk] = true;
            }
        } else {
            $ret[$cur_val] = true;
        }

    return $ret;
}

function remarks_to_weeks_cached($remarks) {
    global $remarks_cache;
    if (isset($remarks_cache[$remarks])) {
        return $remarks_cache[$remarks];
    }
    $result = remarks_to_weeks($remarks);
    $remarks_cache[$remarks] = $result;
    return $result;
}

function check_lunch_break_early($day, $new_start, $new_end, $temp_timetable) {
    global $LUNCH_TIMES;

    $dominated_lunch_slots = array();
    foreach ($LUNCH_TIMES as $lt) {
        if ($lt >= $new_start && $lt < $new_end) {
            $dominated_lunch_slots[] = $lt;
        }
    }

    if (empty($dominated_lunch_slots)) {
        return true;
    }

    foreach ($LUNCH_TIMES as $lt) {
        if (in_array($lt, $dominated_lunch_slots)) {
            continue;
        }
        $slot = $temp_timetable[$day][$lt];
        if (empty($slot) || (is_array($slot) && count($slot) === 0)) {
            return true;
        }
    }

    return false;
}

function check_cannot_teleport_early($course_id, $detail, $temp_timetable) {
    global $TIME_SLOTS, $TIME_INDEX;

    $day = $detail["day"];
    $new_type = $detail["type"];
    $start_idx = $detail["_start_idx"];
    $end_idx = $detail["_end_idx"];
    $new_is_local = (strpos($new_type, 'LEC') === 0 || strpos($new_type, 'TUT') === 0);

    if (strpos($new_type, 'TUT') === 0 && $start_idx > 0) {
        $prev_slot_time = $TIME_SLOTS[$start_idx - 1];
        $prev_slot = $temp_timetable[$day][$prev_slot_time];
        if (is_array($prev_slot) && count($prev_slot) > 0) {
            foreach ($prev_slot as $prev_course) {
                if (strpos($prev_course['type'], 'LEC') !== 0 && strpos($prev_course['type'], 'TUT') !== 0) {
                    return false;
                }
            }
        }
    }

    if ($end_idx < count($TIME_SLOTS)) {
        $next_slot_time = $TIME_SLOTS[$end_idx];
        $next_slot = $temp_timetable[$day][$next_slot_time];
        if (is_array($next_slot) && count($next_slot) > 0) {
            foreach ($next_slot as $next_course) {
                if (strpos($next_course['type'], 'TUT') === 0) {
                    if (!$new_is_local) {
                        return false;
                    }
                }
            }
        }
    }

    return true;
}
