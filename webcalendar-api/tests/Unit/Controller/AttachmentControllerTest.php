<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Api\AttachmentController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use WebCalendar\Core\Domain\Entity\Blob;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Repository\BlobRepositoryInterface;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\BlobType;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;

final class AttachmentControllerTest extends TestCase
{
    private EventRepositoryInterface&MockObject $eventRepo;
    private BlobRepositoryInterface&MockObject $blobRepo;
    private AttachmentController $controller;

    protected function setUp(): void
    {
        $this->eventRepo = $this->createMock(EventRepositoryInterface::class);
        $this->blobRepo = $this->createMock(BlobRepositoryInterface::class);
        $this->controller = AttachmentController::createForTest($this->eventRepo, $this->blobRepo);
    }

    private function makeUser(string $login): User
    {
        return new User($login, ucfirst($login), 'Smith', $login . '@example.com', false, true);
    }

    private function makeEvent(int $id, string $createdBy): Event
    {
        return new Event(
            id: new EventId($id),
            uid: "event-{$id}@test",
            name: "Event {$id}",
            description: '',
            location: '',
            start: new \DateTimeImmutable('2026-04-01 10:00:00'),
            duration: 60,
            createdBy: $createdBy,
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
        );
    }

    private function makeBlob(int $id, int $eventId, string $login): Blob
    {
        return new Blob(
            id: $id,
            eventId: $eventId,
            login: $login,
            name: "file{$id}.pdf",
            description: '',
            size: 1024,
            mimeType: 'application/pdf',
            type: BlobType::ATTACHMENT,
            date: new \DateTimeImmutable(),
        );
    }

    public function testListAttachments(): void
    {
        $event = $this->makeEvent(1, 'alice');
        $this->eventRepo->method('findById')->willReturn($event);

        $blobs = [$this->makeBlob(10, 1, 'alice'), $this->makeBlob(11, 1, 'alice')];
        $this->blobRepo->method('findByEvent')->with(1, BlobType::ATTACHMENT)->willReturn($blobs);

        $user = $this->makeUser('alice');
        $response = $this->controller->list(1, Request::create('/'), $user);

        $this->assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true);
        $this->assertCount(2, $body['data']);
        $this->assertSame('file10.pdf', $body['data'][0]['filename']);
    }

    public function testListAttachmentsEventNotFound(): void
    {
        $this->eventRepo->method('findById')->willReturn(null);
        $user = $this->makeUser('alice');

        $response = $this->controller->list(99, Request::create('/'), $user);
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testDeleteAttachment(): void
    {
        $event = $this->makeEvent(1, 'alice');
        $this->eventRepo->method('findById')->willReturn($event);

        $blob = $this->makeBlob(10, 1, 'alice');
        $this->blobRepo->method('findById')->with(10)->willReturn($blob);
        $this->blobRepo->expects($this->once())->method('delete')->with(10);

        $user = $this->makeUser('alice');
        $response = $this->controller->delete(1, 10, Request::create('/', 'DELETE'), $user);

        $this->assertSame(204, $response->getStatusCode());
    }

    public function testDeleteAttachmentWrongEvent(): void
    {
        $event = $this->makeEvent(1, 'alice');
        $this->eventRepo->method('findById')->willReturn($event);

        $blob = $this->makeBlob(10, 2, 'alice'); // blob belongs to event 2
        $this->blobRepo->method('findById')->willReturn($blob);

        $user = $this->makeUser('alice');
        $response = $this->controller->delete(1, 10, Request::create('/', 'DELETE'), $user);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testDownloadAttachment(): void
    {
        $event = $this->makeEvent(1, 'alice');
        $this->eventRepo->method('findById')->willReturn($event);

        $blob = new Blob(
            id: 10,
            eventId: 1,
            login: 'alice',
            name: 'report.pdf',
            description: '',
            size: 5,
            mimeType: 'application/pdf',
            type: BlobType::ATTACHMENT,
            date: new \DateTimeImmutable(),
            content: 'hello',
        );
        $this->blobRepo->method('findById')->willReturn($blob);

        $user = $this->makeUser('alice');
        $response = $this->controller->download(1, 10, $user);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('report.pdf', (string) $response->headers->get('Content-Disposition'));
    }
}
