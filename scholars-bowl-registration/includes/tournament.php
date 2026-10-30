<?php
/**
 * Tournament structure for an event (added in 1.1.0): the divisions run that day,
 * their format, and the award settings.
 *
 * Stored as JSON in the events table's tournament_config column:
 *   {"version":1,"divisions":[{key,name,session,classifications,format,seeding_rounds,
 *     bracket_size,third_place_game}],"awards":{class_places,division_places,individual_places}}
 *
 * The events table's morning_classifications / afternoon_classifications columns stay the
 * source the rest of the plugin reads; on save they are derived from the divisions.
 * Events saved before 1.1.0 have no tournament_config: their divisions are synthesized
 * from the defaults, restricted to the event's own classifications and sessions.
 */

if (!defined('ABSPATH')) {
    exit;
}

class SBR_Tournament {
    const CLASSIFICATIONS = array('Private', '1A', '2A', '3A', '4A', '5A', '6A', '7A');
    const SESSIONS = array('morning' => 'Morning', 'afternoon' => 'Afternoon');
    const FORMATS = array('bracket' => 'Seeding rounds, then a bracket', 'round_robin' => 'Round robin only');
    const BRACKET_SIZES = array(2, 4, 8);

    /** Default divisions for a new event. */
    public static function default_divisions() {
        $bracket = array('format' => 'bracket', 'seeding_rounds' => 3, 'bracket_size' => 4, 'third_place_game' => true);
        return array(
            array('key' => 'private', 'name' => 'Private', 'session' => 'morning', 'classifications' => array('Private')) + $bracket,
            array('key' => 'public-1a-3a', 'name' => 'Public 1A–3A', 'session' => 'morning', 'classifications' => array('1A', '2A', '3A')) + $bracket,
            array('key' => 'public-4a-7a', 'name' => 'Public 4A–7A', 'session' => 'afternoon', 'classifications' => array('4A', '5A', '6A', '7A')) + $bracket,
        );
    }

    public static function default_awards() {
        return array('class_places' => 2, 'division_places' => 1, 'individual_places' => 3);
    }

    public static function default_config() {
        return array('version' => 1, 'divisions' => self::default_divisions(), 'awards' => self::default_awards());
    }

    public static function split_list($value) {
        $parts = array_map('trim', explode(',', (string) $value));
        return array_values(array_filter($parts, 'strlen'));
    }

    /** Known classifications in canonical order, then any others in their given order. */
    public static function sort_classifications($list) {
        $list = array_values(array_unique(array_map('strval', (array) $list)));
        $known = array_values(array_intersect(self::CLASSIFICATIONS, $list));
        $other = array_values(array_diff($list, self::CLASSIFICATIONS));
        return array_merge($known, $other);
    }

    private static function slug_key($name, $used) {
        $base = sanitize_title(remove_accents(str_replace(array('–', '—'), '-', (string) $name)));
        if ($base === '') {
            $base = 'division';
        }
        $key = $base;
        $n = 2;
        while (in_array($key, $used, true)) {
            $key = $base . '-' . $n++;
        }
        return $key;
    }

    /**
     * The event's tournament config: stored JSON if present and valid, otherwise
     * synthesized from the defaults and the event's classification columns.
     *
     * @param object|array $event An events row.
     */
    public static function get_config($event) {
        $event = (object) $event;
        $stored = isset($event->tournament_config) ? (string) $event->tournament_config : '';
        if ($stored !== '') {
            $decoded = json_decode($stored, true);
            if (is_array($decoded) && !empty($decoded['divisions']) && is_array($decoded['divisions'])) {
                $clean = self::normalize($decoded, false);
                if (empty($clean['errors'])) {
                    return $clean['config'];
                }
            }
        }
        return self::synthesize($event);
    }

    /** Divisions for an event saved before 1.1.0. */
    public static function synthesize($event) {
        $event = (object) $event;
        $by_session = array(
            'morning' => self::split_list(isset($event->morning_classifications) ? $event->morning_classifications : ''),
            'afternoon' => self::split_list(isset($event->afternoon_classifications) ? $event->afternoon_classifications : ''),
        );
        $divisions = array();
        $covered = array('morning' => array(), 'afternoon' => array());
        foreach (self::default_divisions() as $d) {
            $classes = array_values(array_intersect($d['classifications'], $by_session[$d['session']]));
            if (!$classes) {
                continue;
            }
            $d['classifications'] = $classes;
            $divisions[] = $d;
            $covered[$d['session']] = array_merge($covered[$d['session']], $classes);
        }
        // Classifications the defaults don't place in that session get a division of their own.
        $used = wp_list_pluck($divisions, 'key');
        foreach ($by_session as $session => $list) {
            $left = array_values(array_diff($list, $covered[$session]));
            if (!$left) {
                continue;
            }
            $left = self::sort_classifications($left);
            $name = ($left === array('Private')) ? 'Private' : self::SESSIONS[$session] . ' ' . implode(', ', $left);
            $key = self::slug_key($name, $used);
            $used[] = $key;
            $divisions[] = array('key' => $key, 'name' => $name, 'session' => $session, 'classifications' => $left,
                'format' => 'bracket', 'seeding_rounds' => 3, 'bracket_size' => 4, 'third_place_game' => true);
        }
        return array('version' => 1, 'divisions' => $divisions, 'awards' => self::default_awards(), 'synthesized' => true);
    }

    /**
     * Validate and normalize a config (from the admin form or from storage).
     *
     * @param array $input   array('divisions' => [...], 'awards' => [...])
     * @param bool  $strict  Admin input: report every problem. Storage: same rules.
     * @return array array('config' => array, 'errors' => string[])
     */
    public static function normalize($input, $strict = true) {
        $errors = array();
        $divisions = array();
        $used_keys = array();
        $seen = array();
        $rows = isset($input['divisions']) && is_array($input['divisions']) ? array_values($input['divisions']) : array();
        foreach ($rows as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = 'Division ' . ($i + 1);
            $name = trim(sanitize_text_field(isset($row['name']) ? (string) $row['name'] : ''));
            $classes = isset($row['classifications']) && is_array($row['classifications']) ? array_map('strval', $row['classifications']) : array();
            $classes = array_values(array_unique(array_filter(array_map('trim', $classes), 'strlen')));
            if ($name === '' && !$classes) {
                continue; // an empty row is ignored
            }
            if ($name === '') {
                $errors[] = "$label needs a name.";
            }
            $label = $name !== '' ? '“' . $name . '”' : $label;
            if (mb_strlen($name) > 60) {
                $errors[] = "$label: the name is too long (60 characters at most).";
            }
            if (!$classes) {
                $errors[] = "$label needs at least one classification.";
            }
            foreach ($classes as $c) {
                if ($strict && !in_array($c, self::CLASSIFICATIONS, true)) {
                    $errors[] = "$label: unknown classification “" . $c . '”.';
                } elseif (isset($seen[$c])) {
                    $errors[] = "$c is in two divisions ($seen[$c] and $label). Each classification can be in only one division.";
                } else {
                    $seen[$c] = $label;
                }
            }
            $session = isset($row['session']) ? (string) $row['session'] : '';
            if (!isset(self::SESSIONS[$session])) {
                $errors[] = "$label: choose Morning or Afternoon.";
                $session = 'morning';
            }
            $format = isset($row['format']) ? (string) $row['format'] : 'bracket';
            if (!isset(self::FORMATS[$format])) {
                $errors[] = "$label: unknown format.";
                $format = 'bracket';
            }
            $seeding = isset($row['seeding_rounds']) && $row['seeding_rounds'] !== '' ? intval($row['seeding_rounds']) : 3;
            if ($seeding < 1 || $seeding > 12) {
                $errors[] = "$label: seeding rounds must be between 1 and 12.";
                $seeding = 3;
            }
            $size = isset($row['bracket_size']) && $row['bracket_size'] !== '' ? intval($row['bracket_size']) : 4;
            if (!in_array($size, self::BRACKET_SIZES, true)) {
                $errors[] = "$label: bracket size must be 2, 4 or 8.";
                $size = 4;
            }
            $third = isset($row['third_place_game']) ? filter_var($row['third_place_game'], FILTER_VALIDATE_BOOLEAN) : false;
            $key = isset($row['key']) ? sanitize_key((string) $row['key']) : '';
            if ($key === '' || in_array($key, $used_keys, true)) {
                $key = self::slug_key($name, $used_keys);
            }
            $used_keys[] = $key;
            $divisions[] = array(
                'key' => $key,
                'name' => $name,
                'session' => $session,
                'classifications' => self::sort_classifications($classes),
                'format' => $format,
                'seeding_rounds' => $seeding,
                'bracket_size' => $size,
                'third_place_game' => (bool) $third,
            );
        }
        if (!$divisions) {
            $errors[] = 'Add at least one division.';
        }
        $awards_in = isset($input['awards']) && is_array($input['awards']) ? $input['awards'] : array();
        $awards = self::default_awards();
        $limits = array('class_places' => array(0, 10, 'Places per classification'), 'division_places' => array(0, 10, 'Places per division'), 'individual_places' => array(0, 30, 'All-tournament certificates'));
        foreach ($limits as $k => $lim) {
            if (isset($awards_in[$k]) && $awards_in[$k] !== '') {
                $v = intval($awards_in[$k]);
                if ($v < $lim[0] || $v > $lim[1] || (string) $v !== trim((string) $awards_in[$k])) {
                    $errors[] = "{$lim[2]} must be a whole number from {$lim[0]} to {$lim[1]}.";
                } else {
                    $awards[$k] = $v;
                }
            }
        }
        return array('config' => array('version' => 1, 'divisions' => $divisions, 'awards' => $awards), 'errors' => $errors);
    }

    /** Morning/afternoon classification lists (comma-separated, as the events table stores them). */
    public static function derive_session_classifications($config) {
        $out = array('morning' => array(), 'afternoon' => array());
        foreach ($config['divisions'] as $d) {
            $out[$d['session']] = array_merge($out[$d['session']], $d['classifications']);
        }
        return array(
            'morning' => implode(',', self::sort_classifications($out['morning'])),
            'afternoon' => implode(',', self::sort_classifications($out['afternoon'])),
        );
    }

    /** "8:30 AM" -> "08:30", "12:15 PM" -> "12:15", "13:00" -> "13:00"; null if unreadable. */
    public static function parse_time($value) {
        $v = strtoupper(trim((string) $value));
        if ($v === '') {
            return null;
        }
        if (!preg_match('/^(\d{1,2})(?:[:.](\d{2}))?\s*(A\.?M\.?|P\.?M\.?)?$/', $v, $m)) {
            return null;
        }
        $h = intval($m[1]);
        $min = isset($m[2]) && $m[2] !== '' ? intval($m[2]) : 0;
        $ampm = isset($m[3]) ? $m[3][0] : '';
        if ($min > 59) {
            return null;
        }
        if ($ampm !== '') {
            if ($h < 1 || $h > 12) {
                return null;
            }
            if ($ampm === 'A' && $h === 12) {
                $h = 0;
            } elseif ($ampm === 'P' && $h !== 12) {
                $h += 12;
            }
        } elseif ($h > 23) {
            return null;
        }
        return sprintf('%02d:%02d', $h, $min);
    }

    /** Sessions with at least one classification, for the API. */
    public static function sessions($event, $config = null) {
        $event = (object) $event;
        $config = $config ?: self::get_config($event);
        $classes = array('morning' => array(), 'afternoon' => array());
        foreach ($config['divisions'] as $d) {
            $classes[$d['session']] = array_merge($classes[$d['session']], $d['classifications']);
        }
        $times = array(
            'morning' => isset($event->morning_session_time) ? $event->morning_session_time : '',
            'afternoon' => isset($event->afternoon_session_time) ? $event->afternoon_session_time : '',
        );
        $out = array();
        foreach (self::SESSIONS as $key => $label) {
            if (!$classes[$key]) {
                continue;
            }
            $out[] = array(
                'key' => $key,
                'label' => $label,
                'start_time' => self::parse_time($times[$key]),
                'classifications' => self::sort_classifications($classes[$key]),
            );
        }
        return $out;
    }

    /**
     * When the roster locks, as a DateTimeImmutable in the site's time zone:
     * the event's roster_deadline if set, otherwise 11:59:59 pm the day before the event.
     */
    public static function roster_deadline($event) {
        $event = (object) $event;
        $tz = wp_timezone();
        if (!empty($event->roster_deadline) && strpos((string) $event->roster_deadline, '0000') !== 0) {
            try {
                return new DateTimeImmutable((string) $event->roster_deadline, $tz);
            } catch (Exception $e) {
                // fall through to the default
            }
        }
        $date = !empty($event->event_date) ? (string) $event->event_date : '';
        try {
            $day = new DateTimeImmutable($date . ' 00:00:00', $tz);
        } catch (Exception $e) {
            return null;
        }
        return $day->modify('-1 day')->setTime(23, 59, 59);
    }

    /** When a session starts on the event day (site time); 00:00 if the time can't be read. */
    public static function session_start($event, $session) {
        $event = (object) $event;
        $field = $session === 'afternoon' ? 'afternoon_session_time' : 'morning_session_time';
        $hm = self::parse_time(isset($event->$field) ? $event->$field : '') ?: '00:00';
        try {
            return new DateTimeImmutable($event->event_date . ' ' . $hm . ':00', wp_timezone());
        } catch (Exception $e) {
            return null;
        }
    }

    /** Divisions/awards in the shape the REST API returns. */
    public static function api_divisions($config) {
        $out = array();
        foreach ($config['divisions'] as $d) {
            $out[] = array(
                'key' => (string) $d['key'],
                'name' => (string) $d['name'],
                'session' => (string) $d['session'],
                'classifications' => array_values($d['classifications']),
                'format' => (string) $d['format'],
                'seeding_rounds' => intval($d['seeding_rounds']),
                'bracket_size' => intval($d['bracket_size']),
                'third_place_game' => (bool) $d['third_place_game'],
            );
        }
        return $out;
    }

    public static function api_awards($config) {
        $a = isset($config['awards']) ? $config['awards'] : array();
        $d = self::default_awards();
        return array(
            'class_places' => intval(isset($a['class_places']) ? $a['class_places'] : $d['class_places']),
            'division_places' => intval(isset($a['division_places']) ? $a['division_places'] : $d['division_places']),
            'individual_places' => intval(isset($a['individual_places']) ? $a['individual_places'] : $d['individual_places']),
        );
    }
}
