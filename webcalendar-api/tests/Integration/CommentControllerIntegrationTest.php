<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\Api\CommentController;
use Symfony\Component\HttpFoundation\Request;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;

final class CommentControllerIntegrationTest extends IntegrationTestCase
{
    private CommentController $controller;
    private int $eventId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new CommentController($this->factory, $this->pdo);

        // Create a test event
        $event = new Event(
            id: new EventId(0),
            uid: 'comment-test-' . bin2hex(random_bytes(4)) . '@test',
            name: 'Comment Test Event',
            description: '',
            location: '',
            start: new \DateTimeImmutable('2026-07-01 10:00:00'),
            duration: 60,
            createdBy: 'admin',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
        );
        $this->factory->getEventService()->createEvent($event, $this->adminUser);
        $created = $this->factory->getEventRepository()->findByUid($event->uid());
        $this->eventId = $created !== null ? $created->id()->value() : 1;
    }

    private function makeWebCalUser(string $login = 'admin', bool $isAdmin = true): \App\Security\WebCalendarUser
    {
        $coreUser = $isAdmin ? $this->adminUser : $this->normalUser;
        return new \App\Security\WebCalendarUser($coreUser, null);
    }

    public function testCreateComment(): void
    {
        $request = Request::create('/api/v2/events/' . $this->eventId . '/comments', 'POST', [], [], [], [], json_encode(['text' => 'Hello world']));
        $user = $this->makeWebCalUser();

        $response = $this->controller->create($this->eventId, $request, $user);

        $this->assertSame(201, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('Hello world', $data['data']['text']);
        $this->assertSame('admin', $data['data']['user_login']);
    }

    public function testListComments(): void
    {
        $user = $this->makeWebCalUser();

        // Create two comments
        $req1 = Request::create('', 'POST', [], [], [], [], json_encode(['text' => 'First']));
        $req2 = Request::create('', 'POST', [], [], [], [], json_encode(['text' => 'Second']));
        $this->controller->create($this->eventId, $req1, $user);
        $this->controller->create($this->eventId, $req2, $user);

        // List
        $response = $this->controller->list($this->eventId, $user);
        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertCount(2, $data['data']);
        $this->assertSame('First', $data['data'][0]['text']);
        $this->assertSame('Second', $data['data'][1]['text']);
    }

    public function testDeleteOwnComment(): void
    {
        $user = $this->makeWebCalUser();

        $req = Request::create('', 'POST', [], [], [], [], json_encode(['text' => 'Delete me']));
        $createResponse = $this->controller->create($this->eventId, $req, $user);
        $createData = json_decode((string) $createResponse->getContent(), true);
        $commentId = $createData['data']['id'];

        $deleteResponse = $this->controller->delete($this->eventId, $commentId, $user);
        $this->assertSame(204, $deleteResponse->getStatusCode());

        // Verify deleted
        $listResponse = $this->controller->list($this->eventId, $user);
        $listData = json_decode((string) $listResponse->getContent(), true);
        $this->assertCount(0, $listData['data']);
    }

    public function testCannotDeleteOtherUserComment(): void
    {
        $admin = $this->makeWebCalUser('admin', true);
        $alice = $this->makeWebCalUser('alice', false);

        // Admin creates comment
        $req = Request::create('', 'POST', [], [], [], [], json_encode(['text' => 'Admin comment']));
        $createResponse = $this->controller->create($this->eventId, $req, $admin);
        $createData = json_decode((string) $createResponse->getContent(), true);
        $commentId = $createData['data']['id'];

        // Alice tries to delete
        $deleteResponse = $this->controller->delete($this->eventId, $commentId, $alice);
        $this->assertSame(403, $deleteResponse->getStatusCode());
    }

    public function testEmptyCommentRejected(): void
    {
        $user = $this->makeWebCalUser();
        $req = Request::create('', 'POST', [], [], [], [], json_encode(['text' => '']));

        $response = $this->controller->create($this->eventId, $req, $user);
        $this->assertSame(400, $response->getStatusCode());
    }

    public function testCommentsDisabledReturnsEmpty(): void
    {
        $this->factory->getConfigService()->updateSetting('DISABLE_COMMENTS', 'Y');
        $user = $this->makeWebCalUser();

        $listResponse = $this->controller->list($this->eventId, $user);
        $listData = json_decode((string) $listResponse->getContent(), true);
        $this->assertSame([], $listData['data']);

        $req = Request::create('', 'POST', [], [], [], [], json_encode(['text' => 'Should fail']));
        $createResponse = $this->controller->create($this->eventId, $req, $user);
        $this->assertSame(403, $createResponse->getStatusCode());
    }
}
