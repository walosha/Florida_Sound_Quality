<?php
/**
 * Competition events helpers.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/pagination.php';

/**
 * All events (for dropdowns / small catalogs).
 *
 * @return list<array<string, mixed>>
 */
function listEvents(): array
{
    return dbFetchAll(
        'SELECT * FROM events ORDER BY event_date DESC, name ASC, id DESC'
    );
}

/**
 * Events catalog (paginated) for admin tables.
 *
 * @return array{rows:list<array<string,mixed>>,total:int,page:int,per_page:int,total_pages:int,offset:int,from:int,to:int}
 */
function listEventsPaginated(int $page = 1, int $perPage = PAGINATION_DEFAULT_PER_PAGE): array
{
    return dbPaginate(
        'SELECT COUNT(*) AS cnt FROM events',
        'SELECT * FROM events ORDER BY event_date DESC, name ASC, id DESC',
        [],
        $page,
        $perPage
    );
}

/**
 * @return array<string, mixed>|null
 */
function findEventById(int $id): ?array
{
    return dbFetchOne('SELECT * FROM events WHERE id = ?', [$id]);
}

/**
 * @return array{ok:bool,errors:array<string,string>,event:?array<string,mixed>}
 */
function createEvent(string $name, string $eventDate): array
{
    $errors = [];
    $name = trim($name);
    $eventDate = trim($eventDate);

    if ($name === '') {
        $errors['name'] = 'Event name is required.';
    } elseif (mb_strlen($name) > 255) {
        $errors['name'] = 'Event name is too long.';
    }

    if ($eventDate === '') {
        $errors['event_date'] = 'Event date is required.';
    } else {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $eventDate);
        if (!$dt || $dt->format('Y-m-d') !== $eventDate) {
            $errors['event_date'] = 'Event date must be YYYY-MM-DD.';
        }
    }

    if ($errors !== []) {
        return ['ok' => false, 'errors' => $errors, 'event' => null];
    }

    try {
        dbQuery(
            'INSERT INTO events (name, event_date) VALUES (?, ?)',
            [$name, $eventDate]
        );
    } catch (PDOException $e) {
        if ((int) $e->getCode() === 23000) {
            return [
                'ok'     => false,
                'errors' => ['name' => 'An event with this name and date already exists.'],
                'event'  => null,
            ];
        }
        throw $e;
    }

    $id = (int) db()->lastInsertId();
    return ['ok' => true, 'errors' => [], 'event' => findEventById($id)];
}

/**
 * Soft-delete is not used; hard delete only when no scores reference the event.
 *
 * @return array{ok:bool,error:?string}
 */
function deleteEvent(int $eventId): array
{
    $event = findEventById($eventId);
    if ($event === null) {
        return ['ok' => false, 'error' => 'Event not found.'];
    }

    $used = dbFetchOne(
        'SELECT id FROM scores WHERE event_id = ? LIMIT 1',
        [$eventId]
    );
    if ($used !== null) {
        return ['ok' => false, 'error' => 'Cannot delete an event that already has scores. Leave it for history.'];
    }

    // Also block if denormalized name/date still used without event_id? Optional — only FK check.
    dbQuery('DELETE FROM events WHERE id = ?', [$eventId]);
    return ['ok' => true, 'error' => null];
}
