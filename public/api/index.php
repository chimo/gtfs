<?php

require '../../private/config.php';

if (!isset($_SERVER['HTTP_SECRET']) || $_SERVER['HTTP_SECRET'] !== $config['secret']) {
    header($_SERVER['SERVER_PROTOCOL'] . ' 403 Forbidden');
    exit(0);
}

if (!isset($_GET['stop']) || !isset($_GET['agency'])) {
    header($_SERVER['SERVER_PROTOCOL'] . ' 400 Bad Request');
    exit(0);
}

// DB connection
$dbh = new PDO('pgsql:host=localhost;dbname=' . $config['dbName'],
    $config['dbUser'], $config['dbPassword']);

// our agency_id is null, so use feed_id...
$query = 'SELECT agency_timezone FROM agency WHERE feed_id = :agencyId';

$statement = $dbh->prepare($query);

$agency = $_GET['agency'];
$statement->execute(array(':agencyId' => $agency));

$row = $statement->fetch();
$timezone = $row['agency_timezone'];
date_default_timezone_set($timezone);

// Get current date/time
$d = explode('%', date('Y-m-d%H:i'));
$date = $d[0];
$time = $d[1];

// Stop id
$stop = $_GET['stop'];

// Based on: https://stackoverflow.com/a/51455985
$query = <<<SQL
SELECT departure_time, route_long_name, trip_headsign
FROM
(
    SELECT
     st.departure_time,
     tr.service_id,
     rte.route_short_name,
     rte.route_long_name,
     tr.trip_headsign
    FROM stop_times AS st
    JOIN trips AS tr ON tr.trip_id = st.trip_id
    JOIN routes AS rte ON rte.route_id = tr.route_id
    JOIN calendar AS cal ON cal.service_id = tr.service_id
    WHERE st.stop_id = :stopId
    AND :date between cal.start_date and cal.end_date
    AND (CASE EXTRACT(DOW FROM TIMESTAMP '$date')
        WHEN 1 THEN monday
        WHEN 2 THEN tuesday
        WHEN 3 THEN wednesday
        WHEN 4 THEN thursday
        WHEN 5 THEN friday
        WHEN 6 THEN saturday
        WHEN 0 THEN sunday
        END) = true
    AND st.departure_time >= :time
    ORDER BY tr.route_id, st.departure_time
) Q
ORDER BY route_short_name, service_id, departure_time
LIMIT 3 OFFSET 0;
SQL;

// TODO: Error handling from here down

$statement = $dbh->prepare($query);

$statement->bindParam(':stopId', $stop, PDO::PARAM_STR);
$statement->bindParam(':date', $date, PDO::PARAM_STR);
$statement->bindParam(':time', $time, PDO::PARAM_STR);

$statement->execute();

$rows = $statement->fetchAll();

error_log(print_r($rows, true));

$results = [];

foreach ($rows as $row) {
    $results[] = $row['departure_time'];
}

// convert results to json
$json = json_encode($results);

header('Content-Type: application/json');
echo $json;

