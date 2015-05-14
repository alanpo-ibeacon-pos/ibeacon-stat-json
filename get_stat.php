<?php

try {

    $selfMac = isset($_POST["selfMac"]) ? $_POST["selfMac"] : null;
    $strFrom = isset($_POST["from"]) ? $_POST["from"] : null;
    $strTo = isset($_POST["to"]) ? $_POST["to"] : null;

    date_default_timezone_set('UTC');

    $to = !is_null($strTo) && strlen($strTo) > 0 ? DateTime::createFromFormat('Y-m-d H:i:s', $strTo) : new DateTime();
    $from = !is_null($strFrom) && strlen($strTo) > 0 ? DateTime::createFromFormat('Y-m-d H:i:s', $strFrom) : (new DateTime())->sub(new DateInterval('PT10S'));

    $db = new mysqli('moodle-db.cndunymmm6cz.ap-southeast-1.rds.amazonaws.com:3306', '2014fyp_ips', 'alanpo2593', '2014fyp_ips');

    $sql = 'SELECT uuid, major, minor
            FROM traces
            WHERE datetime BETWEEN \'' . $from->format('Y-m-d H:i:s') . '\' AND \'' . $to->format('Y-m-d H:i:s') . '\'';
    if (!empty($selfMac))
        $sql .= ' AND selfMac = ' . hexdec($selfMac);
    $sql .= ' GROUP BY uuid, major, minor';
    $q_mac_r = $db->query($sql);
    unset($sql);

    $arr_stat = array();

    while ($row_mac_r = $q_mac_r->fetch_assoc()) {
        $uuid = $row_mac_r['uuid'];
        $major = intval($row_mac_r['major']);
        $minor = intval($row_mac_r['minor']);

        $q_ib = $db->query('SELECT AVG(txpower) AS txpower, AVG(rssi) AS rssi FROM (
                                SELECT _idx, txpower, rssi, datetime
                                FROM (
                                    SELECT @rownum := @rownum + 1 AS _idx, txpower, rssi, datetime
                                    FROM traces, (SELECT @rownum := 0) r
                                    WHERE uuid = 0x' . bin2hex($uuid) . ' && major = ' . $major . ' && minor = ' . $minor . ' AND datetime BETWEEN \'' . $from->format('Y-m-d H:i:s') . '\' AND \'' . $to->format('Y-m-d H:i:s') . '\'
                                ) limited_traces
                                WHERE _idx >= ROUND(FOUND_ROWS() * 0.1)
                                && _idx < ROUND(FOUND_ROWS() * 0.9)
                            ) AS stat_traces');

        if ($db->errno) throw new Exception($db->error);
        if ($q_ib->num_rows != 1) throw new Exception('empty result when getting avg txpower and rssi for uuid: ' . bin2hex($uuid) . ', major: ' . $major . ', minor: ' . $minor);

        $row_ib = $q_ib->fetch_assoc();

        $q_seen = $db->query('SELECT MAX(datetime) AS `last`, MIN(datetime) AS `first`
                              FROM traces
                              WHERE uuid = 0x' . bin2hex($uuid) . ' && major = ' . $major . ' && minor = ' . $minor . '
                              AND datetime BETWEEN \'' . $from->format('Y-m-d H:i:s') . '\'
                              AND \'' . $to->format('Y-m-d H:i:s') . '\'');

        if ($db->errno) throw new Exception($db->error);
        if ($q_seen->num_rows != 1) throw new Exception('empty first/last seen result when getting for uuid: ' . bin2hex($uuid) . ', major: ' . $major . ', minor: ' . $minor);

        $row_seen = $q_seen->fetch_assoc();

        // actually a overhead-excluded version of arr_push
        $arr_stat[] = [
            'uuid' => bin2hex($uuid),
            'major' => $major,
            'minor' => $minor,
            'rssi' => floatval($row_ib['rssi']),
            'txpower' => floatval($row_ib['txpower']),
            'firstSeen' => $row_seen['first'],
            'lastSeen' => $row_seen['last']
        ];
    }

    header('Content-Type: application/json');
    echo json_encode($arr_stat, JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo $e->getMessage();
}