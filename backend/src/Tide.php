<?php
declare(strict_types=1);

namespace App;

use InvalidArgumentException;
use PDO;

final class Tide
{
    public static function listStations(PDO $db): array
    {
        $rows = $db->query('SELECT slug, name, datum_m FROM stations ORDER BY id')->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $res = self::residuals($db, $r['slug']);
            $thr = self::threshold($db);
            $out[] = [
                'slug' => $r['slug'],
                'name' => $r['name'],
                'datum_m' => (float)$r['datum_m'],
                'threshold_m' => $thr,
                'threshold_source' => 'global',
                'max_abs_residual_m' => $res['max_abs_residual_m'] ?? 0.0,
                'ok' => ($res['max_abs_residual_m'] ?? 0) <= $thr,
            ];
        }
        return $out;
    }

    public static function getStation(PDO $db, string $slug): ?array
    {
        $st = $db->prepare('SELECT * FROM stations WHERE slug = ?');
        $st->execute([$slug]);
        $row = $st->fetch();
        if (!$row) {
            return null;
        }
        return [
            'slug' => $row['slug'],
            'name' => $row['name'],
            'datum_m' => (float)$row['datum_m'],
            'threshold_m' => self::threshold($db, $slug),
            'threshold_source' => self::thresholdSource($db, $slug),
            'override_m' => self::overrideValue($db, $slug),
            'global_threshold_m' => self::threshold($db),
            'constituents' => self::listConstituents($db, $slug) ?? [],
        ];
    }

    public static function listConstituents(PDO $db, string $slug): ?array
    {
        $id = self::stationId($db, $slug);
        if ($id === null) {
            return null;
        }
        $st = $db->prepare('SELECT name, speed_deg_per_hour, amplitude_m, phase_deg FROM constituents WHERE station_id = ? ORDER BY id');
        $st->execute([$id]);
        return array_map(static function ($r) {
            return [
                'name' => $r['name'],
                'speed_deg_per_hour' => (float)$r['speed_deg_per_hour'],
                'amplitude_m' => (float)$r['amplitude_m'],
                'phase_deg' => (float)$r['phase_deg'],
            ];
        }, $st->fetchAll());
    }

    public static function saveConstituents(PDO $db, string $slug, array $items): array
    {
        $id = self::stationId($db, $slug);
        if ($id === null) {
            throw new InvalidArgumentException('station not found');
        }
        if (!$items) {
            throw new InvalidArgumentException('items required');
        }
        foreach ($items as $it) {
            $amp = (float)($it['amplitude_m'] ?? -1);
            $spd = (float)($it['speed_deg_per_hour'] ?? 0);
            if ($amp < 0 || $spd <= 0 || empty($it['name'])) {
                throw new InvalidArgumentException('invalid constituent');
            }
        }
        $db->prepare('DELETE FROM constituents WHERE station_id = ?')->execute([$id]);
        $ins = $db->prepare('INSERT INTO constituents(station_id, name, speed_deg_per_hour, amplitude_m, phase_deg) VALUES (?,?,?,?,?)');
        foreach ($items as $it) {
            $ins->execute([
                $id,
                (string)$it['name'],
                (float)$it['speed_deg_per_hour'],
                (float)$it['amplitude_m'],
                (float)($it['phase_deg'] ?? 0),
            ]);
        }
        return ['items' => self::listConstituents($db, $slug)];
    }

    public static function levelAt(array $constituents, float $datum, float $tHours): float
    {
        $sum = $datum;
        foreach ($constituents as $c) {
            $rad = deg2rad($c['speed_deg_per_hour'] * $tHours + $c['phase_deg']);
            $sum += $c['amplitude_m'] * cos($rad);
        }
        return round($sum, 4);
    }

    public static function forecast(PDO $db, string $slug, int $hours, int $stepMin): ?array
    {
        $st = self::getStation($db, $slug);
        if ($st === null) {
            return null;
        }
        $hours = max(1, min(168, $hours));
        $stepMin = max(5, min(120, $stepMin));
        $points = [];
        for ($m = 0; $m <= $hours * 60; $m += $stepMin) {
            $t = $m / 60.0;
            $points[] = ['t_hours' => round($t, 4), 'level_m' => self::levelAt($st['constituents'], $st['datum_m'], $t)];
        }
        return ['slug' => $slug, 'points' => $points];
    }

    public static function residuals(PDO $db, string $slug): ?array
    {
        $st = self::getStation($db, $slug);
        if ($st === null) {
            return null;
        }
        $id = self::stationId($db, $slug);
        $q = $db->prepare('SELECT t_hours, level_m FROM observations WHERE station_id = ? ORDER BY t_hours');
        $q->execute([$id]);
        $thr = self::threshold($db, $slug);
        $items = [];
        $maxAbs = 0.0;
        foreach ($q->fetchAll() as $obs) {
            $pred = self::levelAt($st['constituents'], $st['datum_m'], (float)$obs['t_hours']);
            $res = round((float)$obs['level_m'] - $pred, 4);
            $maxAbs = max($maxAbs, abs($res));
            $items[] = [
                't_hours' => (float)$obs['t_hours'],
                'observed_m' => (float)$obs['level_m'],
                'predicted_m' => $pred,
                'residual_m' => $res,
                'over' => abs($res) > $thr,
            ];
        }
        return [
            'slug' => $slug,
            'threshold_m' => $thr,
            'threshold_source' => self::thresholdSource($db, $slug),
            'max_abs_residual_m' => round($maxAbs, 4),
            'items' => $items,
        ];
    }

    public static function settings(PDO $db): array
    {
        return ['residual_threshold_m' => self::threshold($db)];
    }

    public static function saveSettings(PDO $db, array $body): array
    {
        $v = (float)($body['residual_threshold_m'] ?? 0);
        if ($v <= 0 || $v > 5) {
            throw new InvalidArgumentException('residual_threshold_m out of range');
        }
        $old = self::threshold($db);
        $db->prepare('INSERT INTO settings(key, value) VALUES(?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
            ->execute(['residual_threshold_m', (string)$v]);
        if (abs($old - $v) > 1e-9) {
            self::audit($db, 'global', null, 'set_global', $old, $v, 'global', 'global');
        }
        return self::settings($db);
    }

    public static function getStationThreshold(PDO $db, string $slug): ?array
    {
        if (self::stationId($db, $slug) === null) {
            return null;
        }
        return [
            'slug' => $slug,
            'threshold_m' => self::threshold($db, $slug),
            'source' => self::thresholdSource($db, $slug),
            'override_m' => self::overrideValue($db, $slug),
            'global_m' => self::threshold($db),
        ];
    }

    public static function setStationThreshold(PDO $db, string $slug, array $body): ?array
    {
        $id = self::stationId($db, $slug);
        if ($id === null) {
            return null;
        }
        $v = (float)($body['threshold_m'] ?? 0);
        if ($v <= 0 || $v > 5) {
            throw new InvalidArgumentException('threshold_m out of range');
        }
        $oldOverride = self::overrideValue($db, $slug);
        $oldSource = $oldOverride !== null ? 'override' : 'global';
        $oldValue = $oldOverride ?? self::threshold($db);
        $db->prepare('INSERT INTO station_thresholds(station_id, threshold_m, updated_at) VALUES(?,?,?) ON CONFLICT(station_id) DO UPDATE SET threshold_m = excluded.threshold_m, updated_at = excluded.updated_at')
            ->execute([$id, $v, gmdate('Y-m-d\TH:i:s\Z')]);
        if ($oldSource === 'global' || abs($oldValue - $v) > 1e-9) {
            self::audit($db, 'station', $slug, 'set_override', $oldValue, $v, $oldSource, 'override');
        }
        return self::getStationThreshold($db, $slug);
    }

    public static function clearStationThreshold(PDO $db, string $slug): ?array
    {
        $id = self::stationId($db, $slug);
        if ($id === null) {
            return null;
        }
        $oldOverride = self::overrideValue($db, $slug);
        if ($oldOverride !== null) {
            $db->prepare('DELETE FROM station_thresholds WHERE station_id = ?')->execute([$id]);
            self::audit($db, 'station', $slug, 'clear_override', $oldOverride, self::threshold($db), 'override', 'global');
        }
        return self::getStationThreshold($db, $slug);
    }

    public static function listAudits(PDO $db, ?string $station, int $limit): array
    {
        $limit = max(1, min(200, $limit));
        $where = '';
        $args = [];
        if ($station === 'global') {
            $where = "WHERE a.scope = 'global'";
        } elseif ($station !== null && $station !== '') {
            $where = "WHERE a.scope = 'station' AND a.station_slug = ?";
            $args[] = $station;
        }
        $st = $db->prepare(
            "SELECT a.*, s.name AS station_name
             FROM threshold_audits a
             LEFT JOIN stations s ON s.slug = a.station_slug
             $where
             ORDER BY a.id DESC
             LIMIT $limit"
        );
        $st->execute($args);
        return array_map(static function ($r) {
            return [
                'id' => (int)$r['id'],
                'scope' => $r['scope'],
                'station_slug' => $r['station_slug'],
                'station_name' => $r['station_name'],
                'action' => $r['action'],
                'old_value' => $r['old_value'] === null ? null : (float)$r['old_value'],
                'new_value' => $r['new_value'] === null ? null : (float)$r['new_value'],
                'old_source' => $r['old_source'],
                'new_source' => $r['new_source'],
                'created_at' => $r['created_at'],
            ];
        }, $st->fetchAll());
    }

    private static function audit(PDO $db, string $scope, ?string $slug, string $action, ?float $old, ?float $new, string $oldSource, string $newSource): void
    {
        $db->prepare('INSERT INTO threshold_audits(scope, station_slug, action, old_value, new_value, old_source, new_source, created_at) VALUES(?,?,?,?,?,?,?,?)')
            ->execute([$scope, $slug, $action, $old, $new, $oldSource, $newSource, gmdate('Y-m-d\TH:i:s\Z')]);
    }

    private static function threshold(PDO $db, ?string $slug = null): float
    {
        if ($slug !== null) {
            $ov = self::overrideValue($db, $slug);
            if ($ov !== null) {
                return $ov;
            }
        }
        $st = $db->query("SELECT value FROM settings WHERE key = 'residual_threshold_m'")->fetch();
        return $st ? (float)$st['value'] : 0.15;
    }

    private static function overrideValue(PDO $db, string $slug): ?float
    {
        $st = $db->prepare('SELECT t.threshold_m FROM station_thresholds t JOIN stations s ON s.id = t.station_id WHERE s.slug = ?');
        $st->execute([$slug]);
        $row = $st->fetch();
        return $row ? (float)$row['threshold_m'] : null;
    }

    private static function thresholdSource(PDO $db, string $slug): string
    {
        return self::overrideValue($db, $slug) !== null ? 'override' : 'global';
    }

    private static function stationId(PDO $db, string $slug): ?int
    {
        $st = $db->prepare('SELECT id FROM stations WHERE slug = ?');
        $st->execute([$slug]);
        $row = $st->fetch();
        return $row ? (int)$row['id'] : null;
    }
}
