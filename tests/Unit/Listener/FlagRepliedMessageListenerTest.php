<?php

declare(strict_types=1);

/**
 * @copyright 2019 Christoph Wurst <christoph@winzerhof-wurst.at>
 *
 * @author 2019 Christoph Wurst <christoph@winzerhof-wurst.at>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\Mail\Test\Listener;

use ChristophWurst\Nextcloud\Testing\TestCase;
use OCA\Mail\Account;
use OCA\Mail\Db\Mailbox;
use OCA\Mail\Db\MailboxMapper;
use OCA\Mail\Db\Message;
use OCA\Mail\Db\MessageMapper as DbMessageMapper;
use OCA\Mail\Events\MessageSentEvent;
use OCA\Mail\IMAP\IMAPClientFactory;
use OCA\Mail\IMAP\MessageMapper;
use OCA\Mail\Listener\FlagRepliedMessageListener;
use OCA\Mail\Model\IMessage;
use OCA\Mail\Model\NewMessageData;
use OCA\Mail\Model\RepliedMessageData;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class FlagRepliedMessageListenerTest extends TestCase {

	/** @var IMAPClientFactory|MockObject */
	private $imapClientFactory;

	/** @var MailboxMapper|MockObject */
	private $mailboxMapper;

	/** @var MessageMapper|MockObject */
	private $messageMapper;

	/** @var LoggerInterface|MockObject */
	private $logger;

	/** @var FlagRepliedMessageListener */
	private $listener;

	/** @var DbMessageMapper|MockObject */
	private $dbMessageMapper;

	protected function setUp(): void {
		parent::setUp();

		$this->imapClientFactory = $this->createMock(IMAPClientFactory::class);
		$this->mailboxMapper = $this->createMock(MailboxMapper::class);
		$this->dbMessageMapper = $this->createMock(DbMessageMapper::class);
		$this->messageMapper = $this->createMock(MessageMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->listener = new FlagRepliedMessageListener(
			$this->imapClientFactory,
			$this->mailboxMapper,
			$this->dbMessageMapper,
			$this->messageMapper,
			$this->logger
		);
	}

	public function testHandleUnrelated(): void {
		$event = new Event();

		$this->listener->handle($event);

		$this->addToAssertionCount(1);
	}

	public function testHandleMessageSentEventMailboxNotAReply(): void {
		/** @var Account|MockObject $account */
		$account = $this->createMock(Account::class);
		/** @var NewMessageData|MockObject $newMessageData */
		$newMessageData = $this->createMock(NewMessageData::class);
		/** @var IMessage|MockObject $message */
		$message = $this->createMock(IMessage::class);
		/** @var \Horde_Mime_Mail|MockObject $mail */
		$mail = $this->createMock(\Horde_Mime_Mail::class);
		$draft = new Message();
		$draft->setUid(123);
		$event = new MessageSentEvent(
			$account,
			$newMessageData,
			null,
			$draft,
			$message,
			$mail
		);
		$this->dbMessageMapper->expects($this->never())
			->method('findByMessageId');
		$this->mailboxMapper->expects($this->never())
			->method('find');
		$this->logger->expects($this->never())
			->method('warning');

		$this->listener->handle($event);
	}

	public function testHandleMessageSentEvent(): void {
		/** @var Account|MockObject $account */
		$account = $this->createMock(Account::class);
		/** @var NewMessageData|MockObject $newMessageData */
		$newMessageData = $this->createMock(NewMessageData::class);
		/** @var RepliedMessageData|MockObject $repliedMessageData */
		$repliedMessageData = $this->createMock(RepliedMessageData::class);
		/** @var IMessage|MockObject $message */
		$message = $this->createMock(IMessage::class);
		/** @var \Horde_Mime_Mail|MockObject $mail */
		$mail = $this->createMock(\Horde_Mime_Mail::class);
		$draft = new Message();
		$draft->setUid(123);
		$event = new MessageSentEvent(
			$account,
			$newMessageData,
			'<abc123@123.com>',
			$draft,
			$message,
			$mail
		);
		$messageInReply = new Message();
		$messageInReply->setUid(321);
		$messageInReply->setMailboxId(654);
		$messageInReply->setMessageId('abc123@123.com');
		$repliedMessageData->method('getMessage')
			->willReturn($messageInReply);
		$this->dbMessageMapper->expects($this->once())
			->method('findByMessageId')
			->with(
				$event->getAccount(),
				'<abc123@123.com>'
			)->willReturn([$messageInReply]);
		$mailbox = new Mailbox();
		$this->mailboxMapper->expects($this->once())
			->method('findById')
			->with(654)
			->willReturn($mailbox);
		$client = $this->createMock(\Horde_Imap_Client_Socket::class);
		$this->imapClientFactory->expects($this->once())
			->method('getClient')
			->with($account)
			->willReturn($client);
		$this->messageMapper->expects($this->once())
			->method('addFlag')
			->with(
				$client,
				$mailbox,
				[321],
				\Horde_Imap_Client::FLAG_ANSWERED
			);
		$this->logger->expects($this->never())
			->method('warning');

		$this->listener->handle($event);
	}
}
