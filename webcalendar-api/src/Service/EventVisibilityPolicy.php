<?php

declare(strict_types=1);

namespace App\Service;

use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;

/**
 * Who may read one event, fetched by id rather than found in a listing.
 *
 * A listing scopes itself in SQL -- EventScope::forUser() is
 * `cal_access = 'P' OR cal_create_by = :login` -- but a route handed an id has
 * already loaded the row before anyone asks whether the caller should have it.
 * This is that question, and it deliberately gives the same answer the listing
 * would: too strict and a row visible in the list is refused when clicked, too
 * loose and the id becomes a way to read the whole installation.
 *
 * It lives here rather than in a controller because more than one route takes
 * an event id, and two copies of an access rule are two rules eventually.
 */
final class EventVisibilityPolicy
{
    public function __construct(
        private readonly AccessPermissionRepository $accessPerms,
    ) {}

    public function forReader(Event $event, User $actor): EventVisibility
    {
        $owner = $event->createdBy();

        if ($owner === $actor->login() || $actor->isAdmin()) {
            return EventVisibility::Full;
        }

        $grant = $this->accessPerms->findGrantsFor($actor->login(), [$owner])[$owner] ?? null;
        $granted = $grant !== null && $grant['can_view'];

        if ($event->access() !== AccessLevel::PUBLIC && !$granted) {
            return EventVisibility::Hidden;
        }

        // see_time_only is the free/busy reading of someone's calendar, and
        // CONFIDENTIAL is the level that answers to it. A PUBLIC entry is
        // public whatever the grant says, which is how the listing reads it.
        if ($granted && $grant['see_time_only'] && $event->access() === AccessLevel::CONFIDENTIAL) {
            return EventVisibility::TimeOnly;
        }

        return EventVisibility::Full;
    }
}
