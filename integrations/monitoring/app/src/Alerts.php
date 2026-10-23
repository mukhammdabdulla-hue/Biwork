<?php
declare(strict_types=1);

/** Ogohlantirishlar: ro'yxat, tafsilot va foydalanuvchi amallari (ko'rdim, mas'ul, izoh, yopish). */
final class Alerts
{
    private const SEV_SQL = "CASE severity WHEN 'crit' THEN 0 WHEN 'warn' THEN 1 ELSE 2 END";

    /** @return array{items:list<array>,total:int,counts:array} */
    public static function list(array $f = []): array
    {
        $where = [];
        $args = [];
        $status = (string)($f['status'] ?? 'active');
        if ($status === 'active') {
            $where[] = "status IN ('open','seen')";
        } elseif ($status === 'closed') {
            $where[] = "status = 'closed'";
        }
        foreach (['severity', 'family', 'entity_type', 'entity_id', 'rule'] as $k) {
            if (!empty($f[$k])) {
                $where[] = "$k = ?";
                $args[] = (string)$f[$k];
            }
        }
        if (!empty($f['q'])) {
            $where[] = '(title LIKE ? OR entity_name LIKE ?)';
            $args[] = '%' . $f['q'] . '%';
            $args[] = '%' . $f['q'] . '%';
        }
        $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $order = $status === 'closed' ? 'closed_at DESC' : self::SEV_SQL . ', opened_at DESC';
        $limit = max(1, min(200, (int)($f['limit'] ?? 50)));
        $offset = max(0, (int)($f['offset'] ?? 0));
        $rows = Db::all("SELECT * FROM signals $w ORDER BY $order LIMIT $limit OFFSET $offset", $args);
        $total = (int)Db::val("SELECT COUNT(*) FROM signals $w", $args);
        $now = Clock::now();
        return ['items' => array_map(static fn($r) => self::shape($r, $now), $rows), 'total' => $total, 'counts' => self::counts($now)];
    }

    public static function counts(int $now): array
    {
        $c = ['active' => 0, 'closed' => 0, 'crit' => 0, 'warn' => 0, 'info' => 0, 'unseen' => 0, 'family' => []];
        foreach (Db::all('SELECT status, severity, family, COUNT(*) n FROM signals GROUP BY status, severity, family') as $r) {
            $n = (int)$r['n'];
            if ($r['status'] === 'closed') {
                $c['closed'] += $n;
                continue;
            }
            $c['active'] += $n;
            $c[$r['severity']] += $n;
            $c['family'][$r['family']] = ($c['family'][$r['family']] ?? 0) + $n;
            if ($r['status'] === 'open') {
                $c['unseen'] += $n;
            }
        }
        return $c;
    }

    public static function get(int $id): array
    {
        $r = Db::one('SELECT * FROM signals WHERE id = ?', [$id]);
        if (!$r) {
            throw new ApiError(404, 'not_found', 'Ogohlantirish topilmadi');
        }
        $a = self::shape($r, Clock::now());
        $a['log'] = array_map(static fn($l) => ['at' => (int)$l['at'], 'who' => $l['who'], 'kind' => $l['kind'], 'text' => $l['text']],
            Db::all('SELECT * FROM signal_log WHERE signal_id = ? ORDER BY at, id', [$id]));
        $a['people'] = array_map(static fn($p) => ['name' => $p['name'], 'role' => $p['role']], Db::all('SELECT name, role FROM people ORDER BY id'));
        return $a;
    }

    private static function shape(array $r, int $now): array
    {
        $end = $r['closed_at'] !== null ? (int)$r['closed_at'] : $now;
        return [
            'id' => (int)$r['id'], 'rule' => $r['rule'], 'rule_name' => Rules::DEFS[$r['rule']]['name'] ?? $r['rule'], 'family' => $r['family'],
            'family_name' => Domain::FAMILIES[$r['family']] ?? $r['family'], 'severity' => $r['severity'], 'severity_name' => Domain::SEVERITY[$r['severity']] ?? $r['severity'],
            'entity_type' => $r['entity_type'], 'entity_id' => $r['entity_id'], 'entity_name' => $r['entity_name'],
            'title' => $r['title'], 'why' => $r['why'], 'action' => $r['action'], 'evidence' => json_decode((string)$r['evidence'], true) ?: [],
            'status' => $r['status'], 'owner' => $r['owner'], 'opened_at' => (int)$r['opened_at'], 'updated_at' => (int)$r['updated_at'],
            'seen_at' => $r['seen_at'] === null ? null : (int)$r['seen_at'], 'closed_at' => $r['closed_at'] === null ? null : (int)$r['closed_at'],
            'closed_by' => $r['closed_by'], 'close_note' => $r['close_note'], 'age_min' => (int)round(($end - (int)$r['opened_at']) / 60),
        ];
    }

    /** Foydalanuvchi amali. $who — foydalanuvchi nomi. */
    public static function act(int $id, string $action, array $body, string $who): array
    {
        $r = Db::one('SELECT * FROM signals WHERE id = ?', [$id]);
        if (!$r) {
            throw new ApiError(404, 'not_found', 'Ogohlantirish topilmadi');
        }
        $now = Clock::now();
        $closed = $r['status'] === 'closed';
        switch ($action) {
            case 'seen':
                if (!$closed && $r['status'] === 'open') {
                    Db::update('signals', ['status' => 'seen', 'seen_at' => $now, 'updated_at' => $now], 'id = ?', [$id]);
                    Engine::log($id, $now, $who, 'seen', "Ko'rdi");
                }
                break;
            case 'assign':
                $owner = trim((string)($body['owner'] ?? ''));
                Db::update('signals', ['owner' => $owner === '' ? null : mb_substr($owner, 0, 80), 'updated_at' => $now], 'id = ?', [$id]);
                Engine::log($id, $now, $who, 'assigned', $owner === '' ? "Mas'ul olib tashlandi" : "Mas'ul: $owner");
                break;
            case 'comment':
                $t = trim((string)($body['text'] ?? ''));
                if ($t === '') {
                    throw new ApiError(422, 'empty', 'Izoh matnini yozing');
                }
                Engine::log($id, $now, $who, 'comment', mb_substr($t, 0, 500));
                Db::update('signals', ['updated_at' => $now], 'id = ?', [$id]);
                break;
            case 'close':
                if (!$closed) {
                    $note = mb_substr(trim((string)($body['note'] ?? '')), 0, 300);
                    Db::update('signals', ['status' => 'closed', 'closed_at' => $now, 'closed_by' => $who, 'close_note' => $note === '' ? null : $note, 'updated_at' => $now], 'id = ?', [$id]);
                    Engine::log($id, $now, $who, 'closed', 'Yopdi' . ($note !== '' ? ": $note" : ''));
                }
                break;
            case 'reopen':
                if ($closed) {
                    Db::update('signals', ['status' => 'open', 'closed_at' => null, 'closed_by' => null, 'close_note' => null, 'updated_at' => $now], 'id = ?', [$id]);
                    Engine::log($id, $now, $who, 'reopened', 'Qayta ochdi');
                }
                break;
            default:
                throw new ApiError(404, 'not_found', "Bunday amal yo'q");
        }
        return self::get($id);
    }
}
