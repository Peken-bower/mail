<?php

declare(strict_types=1);

/**
 * @copyright 2022 Anna Larch <anna@nextcloud.com>
 *
 * @author 2022 Anna Larch <anna@nextcloud.com>
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

namespace OCA\Mail\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use function array_filter;

/**
 * @method int getType()
 * @method void setType(int $type)
 * @method int getAccountId()
 * @method void setAccountId(int $accountId)
 * @method int|null getAliasId()
 * @method void setAliasId(?int $aliasId)
 * @method int getSendAt()
 * @method void setSendAt(int $sendAt)
 * @method string getSubject()
 * @method void setSubject(string $subject)
 * @method string getBody()
 * @method void setBody(string $body)
 * @method bool isHtml()
 * @method void setHtml(bool $html)
 * @method string|null getInReplyToMessageId()
 * @method void setInReplyToMessageId(?string $inReplyToId)
 */
class LocalMessage extends Entity implements JsonSerializable {
	public const TYPE_OUTGOING = 0;
	public const TYPE_DRAFT = 1;

	/**
	 * @var int
	 * @psalm-var self::TYPE_*
	 */
	protected $type;

	/** @var int */
	protected $accountId;

	/** @var int|null */
	protected $aliasId;

	/** @var int */
	protected $sendAt;

	/** @var string */
	protected $subject;

	/** @var string */
	protected $body;

	/** @var bool */
	protected $html;

	/** @var string|null */
	protected $inReplyToMessageId;

	/** @var array|null */
	protected $attachments;

	/** @var array|null */
	protected $recipients;

	public function __construct() {
		$this->addType('type', 'integer');
		$this->addType('accountId', 'integer');
		$this->addType('aliasId', 'integer');
		$this->addType('sendAt', 'integer');
		$this->addType('html', 'boolean');
	}

	/**
	 * @return array
	 */
	public function jsonSerialize() {
		return [
			'id' => $this->getId(),
			'type' => $this->getType(),
			'accountId' => $this->getAccountId(),
			'aliasId' => $this->getAliasId(),
			'sendAt' => $this->getSendAt(),
			'subject' => $this->getSubject(),
			'body' => $this->getBody(),
			'isHtml' => $this->isHtml(),
			'inReplyToMessageId' => $this->getInReplyToMessageId(),
			'attachments' => $this->getAttachments(),
			'from' => array_filter($this->getRecipients(), function (Recipient $recipient) {
				return $recipient->getType() === Recipient::TYPE_FROM;
			}),
			'to' => array_filter($this->getRecipients(), function (Recipient $recipient) {
				return $recipient->getType() === Recipient::TYPE_TO;
			}),
			'cc' => array_filter($this->getRecipients(), function (Recipient $recipient) {
				return $recipient->getType() === Recipient::TYPE_CC;
			}),
			'bcc' => array_filter($this->getRecipients(), function (Recipient $recipient) {
				return $recipient->getType() === Recipient::TYPE_BCC;
			}),
		];
	}

	/**
	 * @param LocalAttachment[] $attachments
	 * @return void
	 */
	public function setAttachments(array $attachments): void {
		$this->attachments = $attachments;
	}

	/**
	 * @return LocalAttachment[]|null
	 */
	public function getAttachments(): ?array {
		return $this->attachments;
	}

	/**
	 * @param Recipient[] $recipients
	 * @return void
	 */
	public function setRecipients(array $recipients): void {
		$this->recipients = $recipients;
	}

	/**
	 * @return Recipient[]|null
	 */
	public function getRecipients(): ?array {
		return $this->recipients;
	}
}
