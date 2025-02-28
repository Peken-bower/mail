<?php

declare(strict_types=1);

/**
 * Mail App
 *
 * @copyright 2022 Anna Larch <anna.larch@gmx.net>
 *
 * @author Anna Larch <anna.larch@gmx.net>
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Mail\Service;

use OCA\Mail\Account;
use OCA\Mail\Contracts\ILocalMailboxService;
use OCA\Mail\Contracts\IMailManager;
use OCA\Mail\Contracts\IMailTransmission;
use OCA\Mail\Db\LocalMessage;
use OCA\Mail\Db\LocalMessageMapper;
use OCA\Mail\Db\Recipient;
use OCA\Mail\Events\OutboxMessageCreatedEvent;
use OCA\Mail\IMAP\IMAPClientFactory;
use OCA\Mail\Service\Attachment\AttachmentService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

class OutboxService implements ILocalMailboxService {

	/** @var IMailTransmission */
	private $transmission;

	/** @var LocalMessageMapper */
	private $mapper;

	/** @var AttachmentService */
	private $attachmentService;

	/** @var IEventDispatcher */
	private $eventDispatcher;

	/** @var IMAPClientFactory */
	private $clientFactory;

	/** @var IMailManager */
	private $mailManager;

	/** @var AccountService */
	private $accountService;

	/** @var ITimeFactory */
	private $timeFactory;

	/** @var LoggerInterface */
	private $logger;

	public function __construct(IMailTransmission  $transmission,
								LocalMessageMapper $mapper,
								AttachmentService  $attachmentService,
								IEventDispatcher    $eventDispatcher,
								IMAPClientFactory $clientFactory,
								IMailManager $mailManager,
								AccountService $accountService,
								ITimeFactory $timeFactory,
								LoggerInterface $logger) {
		$this->transmission = $transmission;
		$this->mapper = $mapper;
		$this->attachmentService = $attachmentService;
		$this->eventDispatcher = $eventDispatcher;
		$this->clientFactory = $clientFactory;
		$this->mailManager = $mailManager;
		$this->timeFactory = $timeFactory;
		$this->logger = $logger;
		$this->accountService = $accountService;
	}

	/**
	 * @param array $recipients
	 * @param int $type
	 * @return Recipient[]
	 */
	private static function convertToRecipient(array $recipients, int $type): array {
		return array_map(function ($recipient) use ($type) {
			$r = new Recipient();
			$r->setType($type);
			$r->setLabel($recipient['label'] ?? $recipient['email']);
			$r->setEmail($recipient['email']);
			return $r;
		}, $recipients);
	}

	/**
	 * @return LocalMessage[]
	 */
	public function getMessages(string $userId): array {
		return $this->mapper->getAllForUser($userId);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function getMessage(int $id, string $userId): LocalMessage {
		return $this->mapper->findById($id, $userId);
	}

	public function deleteMessage(string $userId, LocalMessage $message): void {
		$this->attachmentService->deleteLocalMessageAttachments($userId, $message->getId());
		$this->mapper->deleteWithRecipients($message);
	}

	public function sendMessage(LocalMessage $message, Account $account): void {
		$this->transmission->sendLocalMessage($account, $message);
		$this->attachmentService->deleteLocalMessageAttachments($account->getUserId(), $message->getId());
		$this->mapper->deleteWithRecipients($message);
	}

	public function saveMessage(Account $account, LocalMessage $message, array $to, array $cc, array $bcc, array $attachments = []): LocalMessage {
		$toRecipients = self::convertToRecipient($to, Recipient::TYPE_TO);
		$ccRecipients = self::convertToRecipient($cc, Recipient::TYPE_CC);
		$bccRecipients = self::convertToRecipient($bcc, Recipient::TYPE_BCC);
		$message = $this->mapper->saveWithRecipients($message, $toRecipients, $ccRecipients, $bccRecipients);

		$client = $this->clientFactory->getClient($account);
		try {
			$attachmentIds = $this->attachmentService->handleAttachments($account, $attachments, $client);
		} finally {
			$client->logout();
		}

		$message->setAttachments($this->attachmentService->saveLocalMessageAttachments($message->getId(), $attachmentIds));
		return $message;
	}

	public function updateMessage(Account $account, LocalMessage $message, array $to, array $cc, array $bcc, array $attachments = []): LocalMessage {
		$toRecipients = self::convertToRecipient($to, Recipient::TYPE_TO);
		$ccRecipients = self::convertToRecipient($cc, Recipient::TYPE_CC);
		$bccRecipients = self::convertToRecipient($bcc, Recipient::TYPE_BCC);
		$message = $this->mapper->updateWithRecipients($message, $toRecipients, $ccRecipients, $bccRecipients);

		$client = $this->clientFactory->getClient($account);
		try {
			$attachmentIds = $this->attachmentService->handleAttachments($account, $attachments, $client);
		} finally {
			$client->logout();
		}
		$message->setAttachments($this->attachmentService->updateLocalMessageAttachments($account->getUserId(), $message, $attachmentIds));
		return $message;
	}

	public function handleDraft(Account $account, int $draftId): void {
		$message = $this->mailManager->getMessage($account->getUserId(), $draftId);
		$this->eventDispatcher->dispatch(
			OutboxMessageCreatedEvent::class,
			new OutboxMessageCreatedEvent($account, $message)
		);
	}

	public function flush(): void {
		$messages = $this->mapper->findDue(
			$this->timeFactory->getTime()
		);

		if (empty($messages)) {
			return;
		}

		$accountIds = array_unique(array_map(function ($message) {
			return $message->getAccountId();
		}, $messages));

		$accounts = array_combine($accountIds, array_map(function ($accountId) {
			return $this->accountService->findById($accountId);
		}, $accountIds));

		foreach ($messages as $message) {
			try {
				$this->sendMessage(
					$message,
					$accounts[$message->getAccountId()],
				);
				$this->logger->debug('Outbox message {id} sent', [
					'id' => $message->getId(),
				]);
			} catch (Throwable $e) {
				// Failure of one message should not stop sending other messages
				// Log and continue
				$this->logger->warning('Could not send outbox message {id}: ' . $e->getMessage(), [
					'id' => $message->getId(),
					'exception' => $e,
				]);
			}
		}
	}
}
