<?php
namespace AzuraCast\Sync;

use Doctrine\ORM\EntityManager;
use Entity;
use InfluxDB\Database;

class Analytics extends SyncAbstract
{
    public function run()
    {
        /** @var EntityManager $em */
        $em = $this->di[EntityManager::class];

        // Clear out any non-daily statistics.
        $em->createQuery('DELETE FROM Entity\Analytics a WHERE a.type != :type')
            ->setParameter('type', 'day')
            ->execute();

        // Pull statistics in from influx.

        /** @var Database $influx */
        $influx = $this->di[Database::class];

        $resultset = $influx->query('SELECT * FROM "1d"./.*/ WHERE time > now() - 14d', [
            'epoch' => 's',
        ]);

        $results_raw = $resultset->getSeries();
        $results = [];
        foreach ($results_raw as $serie) {
            $points = [];
            foreach ($serie['values'] as $point) {
                $points[] = array_combine($serie['columns'], $point);
            }

            $results[$serie['name']] = $points;
        }

        $new_records = [];
        $earliest_timestamp = time();

        foreach ($results as $stat_series => $stat_rows) {
            $series_split = explode('.', $stat_series);
            $station_id = ($series_split[1] == 'all') ? null : $series_split[1];

            foreach ($stat_rows as $stat_row) {
                if ($stat_row['time'] < $earliest_timestamp) {
                    $earliest_timestamp = $stat_row['time'];
                }

                $new_records[] = [
                    'station_id' => $station_id,
                    'type' => 'day',
                    'timestamp' => $stat_row['time'],
                    'number_min' => (int)$stat_row['min'],
                    'number_max' => (int)$stat_row['max'],
                    'number_avg' => round($stat_row['value']),
                ];
            }
        }

        $em->createQuery('DELETE FROM Entity\Analytics a WHERE a.timestamp >= :earliest')
            ->setParameter('earliest', $earliest_timestamp)
            ->execute();

        $all_stations = $em->getRepository(Entity\Station::class)->findAll();
        $stations_by_id = [];
        foreach($all_stations as $station) {
            $stations_by_id[$station->getId()] = $station;
        }

        foreach ($new_records as $row) {
            if (empty($row['station_id']) || isset($stations_by_id[$row['station_id']])) {
                $record = new Entity\Analytics(
                    $row['station_id'] ? $stations_by_id[$row['station_id']] : null,
                    $row['type'],
                    $row['timestamp'],
                    $row['number_min'],
                    $row['number_max'],
                    $row['number_avg']
                );
                $em->persist($record);
            }
        }

        $em->flush();
    }
}