<?php
declare(strict_types=1);

namespace Igloonet\MailkitApi\Managers;

use Igloonet\MailkitApi\DataObjects\Enums\Gender;
use Igloonet\MailkitApi\DataObjects\Enums\InsertStatus;
use Igloonet\MailkitApi\DataObjects\Enums\UserStatus;
use Igloonet\MailkitApi\DataObjects\User;
use Igloonet\MailkitApi\Exceptions\User\TooManyStatusResultsException;
use Igloonet\MailkitApi\Exceptions\User\UserCreationBadEmailSyntaxException;
use Igloonet\MailkitApi\Exceptions\User\UserCreationException;
use Igloonet\MailkitApi\Exceptions\User\UserCreationInvalidMailingListIdException;
use Igloonet\MailkitApi\Exceptions\User\UserCreationInvalidTemplateIdException;
use Igloonet\MailkitApi\Exceptions\User\UserCreationMissingEmailException;
use Igloonet\MailkitApi\Exceptions\User\UserCreationMissingMailingListIdException;
use Igloonet\MailkitApi\Exceptions\User\UserCreationUnknownErrorException;
use Igloonet\MailkitApi\Exceptions\User\UserEditException;
use Igloonet\MailkitApi\Exceptions\User\UserEditInvalidEmailIdException;
use Igloonet\MailkitApi\Exceptions\User\UserEditInvalidMailingListIdException;
use Igloonet\MailkitApi\Exceptions\User\UserEditMissingEmailIdException;
use Igloonet\MailkitApi\Exceptions\User\UserEditMissingMailingListIdException;
use Igloonet\MailkitApi\Exceptions\User\UserEditUnknownErrorException;
use Igloonet\MailkitApi\Exceptions\User\UserRevalidationException;
use Igloonet\MailkitApi\Exceptions\User\UserRevalidationNotUnsubscribedException;
use Igloonet\MailkitApi\Exceptions\User\UserRevalidationUnknownErrorException;
use Igloonet\MailkitApi\Exceptions\User\UserStatusMissingEmailIdException;
use Igloonet\MailkitApi\Exceptions\User\UserStatusNoExistingEmailIdException;
use Igloonet\MailkitApi\Exceptions\User\UserStatusReceiveException;
use Igloonet\MailkitApi\Exceptions\User\UserStatusUnknownErrorException;
use Igloonet\MailkitApi\Exceptions\User\UserUnsubscribtionException;
use Igloonet\MailkitApi\Exceptions\User\UserUnsubscribtionInvalidEmailIdException;
use Igloonet\MailkitApi\Exceptions\User\UserUnsubscribtionUnknownErrorException;
use Igloonet\MailkitApi\RPC\Exceptions\InvalidDataTypeException;
use Nette\Utils\Strings;

class UsersManager extends BaseManager
{
	/**
	 * @param string $emailAddress
	 * @return array|User[]
	 * @throws UserStatusReceiveException
	 */
	public function getUsersByEmailAddress(string $emailAddress): array
	{
		return $this->getUsers($emailAddress);
	}

	/**
	 * @param int $emailId
	 * @return User|null
	 * @throws UserStatusReceiveException|TooManyStatusResultsException
	 */
	public function getUserByEmailId(int $emailId): ?User
	{
		$userData = $this->getUsers((string)$emailId);

		if (count($userData) > 1) {
			throw new TooManyStatusResultsException(
				sprintf(
					'getUserData() returned %d results for email id %d. %d expected.',
					count($userData),
					$emailId,
					1
				)
			);
		}

		return $userData[0] ?? null;
	}

	/**
	 * @param string $emailId
	 * @return array|User[]
	 * @throws UserStatusReceiveException
	 */
	private function getUsers(string $emailId): array
	{
		$params = [
			'email_id' => $emailId
		];
		$possibleErrors = [
			'',
			'Missing ID email',
			'Invalid ID_email'
		];

		$rpcResponse = $this->sendRpcRequest('mailkit.email.getstatus', $params, $possibleErrors);

		if ($rpcResponse->isError()) {
			switch ($rpcResponse->getError()) {
				case 'Invalid ID_email':
					throw new UserStatusNoExistingEmailIdException($rpcResponse);
					break;
				case 'Missing ID email':
					throw new UserStatusMissingEmailIdException($rpcResponse);
					break;
				default:
					throw new UserStatusUnknownErrorException($rpcResponse);
					break;
			}
		}

		$users = [];

		foreach ($rpcResponse->getArrayValue() as $userData) {
			$user = new User();
			$user->setVocative($userData['VOCATIVE'] ?? null);
			$user->setPrefix($userData['PREFIX'] ?? null);
			$user->setPhone($userData['PHONE'] ?? null);
			$user->setStreet($userData['STREET'] ?? null);
			$user->setStatus(UserStatus::get($userData['STATUS']));
			$user->setCity($userData['CITY'] ?? null);
			$user->setCompany($userData['COMPANY'] ?? null);
			$user->setFirstName($userData['FIRST_NAME'] ?? null);
			$user->setLastName($userData['LAST_NAME'] ?? null);
			$user->setEmail($userData['EMAIL'] ?? null);
			$user->setFax($userData['FAX'] ?? null);
			$user->setId($userData['ID_EMAIL'] ?? null);
			$user->setState($userData['STATE'] ?? null);
			$user->setZip($userData['ZIP'] ?? null);
			$user->setNickName($userData['NICK_NAME'] ?? null);
			$user->setReplyTo($userData['REPLY_TO'] ?? null);
			$user->setGender($userData['GENDER'] != "" ? Gender::get($userData['GENDER']) : null);
			$user->setCountry($userData['COUNTRY'] ?? null);
			$user->setMobile($userData['MOBILE'] ?? null);
			$user->setMailingListId($userData['ID_MAILING_LIST'] ?? null);

			for ($i = 1; $i <= User::CUSTOM_FIELDS_CNT; $i++) {
				$user->setCustomField($i, $userData['CUSTOM_'.$i] ?? null);
			}

			$users[] = $user;
		}

		return $users;
	}

	/**
	 * @param string $emailAddress
	 * @param bool $sendOptOut
	 * @param int|null $campaignMessageId
	 * @return int
	 * @throws UserUnsubscribtionException
	 */
	public function unsubscribeEmailAddress(
		string $emailAddress,
		bool $sendOptOut,
		int $campaignMessageId = null
	): int {
		return $this->unsubscribeUser($emailAddress, $sendOptOut, $campaignMessageId);
	}

	/**
	 * @param int $emailId
	 * @param bool $sendOptOut
	 * @param int|null $campaignMessageId
	 * @return int
	 * @throws UserUnsubscribtionException
	 */
	public function unsubscribeEmailId(int $emailId, bool $sendOptOut, int $campaignMessageId = null): int
	{
		return $this->unsubscribeUser((string)$emailId, $sendOptOut, $campaignMessageId);
	}

	/**
	 * @param string $emailId
	 * @param bool $sendOptOut
	 * @param int|null $campaignMessageId
	 * @return int
	 * @throws UserUnsubscribtionException
	 */
	private function unsubscribeUser(string $emailId, bool $sendOptOut, int $campaignMessageId = null): int
	{
		$params = [
			'ID_email' => $emailId,
			'ID_send_message' => $campaignMessageId,
			'send_optout' => $sendOptOut
		];

		$possibleErrors = [
			'Invalid ID_email or email'
		];

		$rpcResponse = $this->sendRpcRequest('mailkit.email.unsubscribe', $params, $possibleErrors);

		if ($rpcResponse->isError()) {
			switch ($rpcResponse->getError()) {
				case 'Invalid ID_email or email':
					throw new UserUnsubscribtionInvalidEmailIdException($rpcResponse);
					break;
				default:
					throw new UserUnsubscribtionUnknownErrorException($rpcResponse);
					break;
			}
		}

		return $rpcResponse->getIntegerValue();
	}

	/**
	 * @param string $emailAddress
	 * @param bool $agreement
	 * @param string|null $channel
	 * @param string|null $language
	 * @param int|null $campaignMessageId
	 * @return bool
	 */
	public function revalidateEmailAddress(
		string $emailAddress,
		bool $agreement,
		string $channel = null,
		string $language = null,
		int $campaignMessageId = null
	): bool {
		return $this->revalidateUser($emailAddress, $agreement, $channel, $language, $campaignMessageId);
	}

	public function revalidateEmailId(
		int $emailId,
		bool $agreement,
		string $channel = null,
		string $language = null,
		int $campaignMessageId = null
	): bool {
		return $this->revalidateUser((string)$emailId, $agreement, $channel, $language, $campaignMessageId);
	}

	/**
	 * @param string $emailId
	 * @param bool $agreement
	 * @param string|null $channel
	 * @param string|null $language
	 * @param int|null $campaignMessageId
	 * @return bool
	 * @throws UserRevalidationException
	 */
	private function revalidateUser(
		string $emailId,
		bool $agreement,
		string $channel = null,
		string $language = null,
		int $campaignMessageId = null
	): bool {
		$params = [
			'ID_email' => $emailId,
			'ID_message' => $campaignMessageId,
			'agreement' => $this->getBooleanString($agreement),
			'channel' => $channel,
			'dummy' => null,
			'language' => $this->validateLanguage($language)
		];

		$possibleErrors = [
			'^.+ is not unsubscribed$'
		];

		$rpcResponse = $this->sendRpcRequest('mailkit.email.revalidate', $params, $possibleErrors);

		if ($rpcResponse->isError()) {
			if (Strings::endsWith($rpcResponse->getError(), ' is not unsubscribed')) {
				throw new UserRevalidationNotUnsubscribedException($rpcResponse);
			} else {
				throw new UserRevalidationUnknownErrorException($rpcResponse);
			}
		}

		$result = $rpcResponse->getStringValue();

		return ($agreement === true && $result === 'Email has been revalidated') ||
			($agreement === false && $result === 'Sent subscribe email');
	}

	/**
	 * @param User $user
	 * @param string|null $returnUrl
	 * @param string|null $templateId
	 * @return array
	 */
	private function getUserDataSectionsForAdd(User $user, ?string $returnUrl, ?string $templateId): array
	{
		return $this->fixEmptyUserDataSections($this->getUserDataSections($user, $returnUrl, $templateId));
	}

	/**
	 * @param User $user
	 * @return array
	 */
	private function getUserDataSectionsForEdit(User $user): array
	{
		$dataSections = $this->getUserDataSections($user, null, null);

		unset($dataSections[0]['email']);

		return $this->fixEmptyUserDataSections($dataSections);
	}

	/**
	 * @param User $user
	 * @param int|null $mailingListId
	 * @param bool $doubleOptIn
	 * @param string|null $returnUrl
	 * @param string|null $templateId
	 * @return bool
	 * @throws UserCreationException
	 */
	public function addUser(
		User $user,
		?int $mailingListId,
		bool $doubleOptIn,
		string $returnUrl = null,
		string $templateId = null
	): bool {
		$params = [
			'mailinglist_id' => $mailingListId ?? $user->getMailingListId() ,
			'double_opt_in' => $this->getBooleanString($doubleOptIn)
		];

		foreach ($this->getUserDataSectionsForAdd($user, $returnUrl, $templateId) as $dataSection) {
			$params[] = $dataSection;
		}

		$possibleErrors = [
			'Missing email',
			'Bad email syntax',
			'Missing ID_mailing_list',
			'Invalid ID_mailing_list',
			'Invalid ID_template'
		];

		$rpcResponse = $this->sendRpcRequest('mailkit.mailinglist.adduser', $params, $possibleErrors);

		if ($rpcResponse->isError()) {
			switch ($rpcResponse->getError()) {
				case 'Missing email':
					throw new UserCreationMissingEmailException($rpcResponse);
					break;
				case 'Bad email syntax':
					throw new UserCreationBadEmailSyntaxException($rpcResponse, $user->getEmail());
					break;
				case 'Missing ID_mailing_list':
					throw new UserCreationMissingMailingListIdException($rpcResponse);
					break;
				case 'Invalid ID_mailing_list':
					throw new UserCreationInvalidMailingListIdException($rpcResponse);
					break;
				case 'Invalid ID_template':
					throw new UserCreationInvalidTemplateIdException($rpcResponse);
					break;
				default:
					throw new UserCreationUnknownErrorException($rpcResponse);
					break;
			}
		}

		try {
			$message = $rpcResponse->getStringValue();
			if ($message === 'Sent subscribe email') {
				return true;
			} else {
				throw new UserCreationUnknownErrorException($rpcResponse, $message);
			}
		} catch (InvalidDataTypeException $ex) {
			$value = $rpcResponse->getArrayValue();

			if (isset($value['data']) && is_numeric($value['data'])) {
				$user->setId((int)$value['data']);
			}

			if (isset($value['status']) && is_numeric($value['status'])) {
				$user->setInsertStatus(InsertStatus::get($value['status']));
			}
		}

		return true;
	}

	/**
	 * @param User $user
	 * @param int|null $mailingListId
	 * @param bool $keepValues
	 * @return bool
	 * @throws UserEditException
	 */
	public function editUser(
		User $user,
		?int $mailingListId,
		bool $keepValues
	): bool {
		$params = [
			'mailinglist_id' => $mailingListId ?? $user->getMailingListId(),
			'email_id' => $user->getEmail(),
			'keep_values' => $this->getBooleanString($keepValues),
		];

		foreach ($this->getUserDataSectionsForEdit($user) as $dataSection) {
			$params[] = $dataSection;
		}

		$possibleErrors = [
			'Missing ID_email',
			'Invalid ID_email',
			'Missing ID_mailing_list',
			'Invalid ID_mailing_list'
		];

		$rpcResponse = $this->sendRpcRequest('mailkit.mailinglist.edituser', $params, $possibleErrors);

		if ($rpcResponse->isError()) {
			switch ($rpcResponse->getError()) {
				case 'Missing ID_email':
					throw new UserEditMissingEmailIdException($rpcResponse);
					break;
				case 'Invalid ID_email':
					throw new UserEditInvalidEmailIdException($rpcResponse);
					break;
				case 'Missing ID_mailing_list':
					throw new UserEditMissingMailingListIdException($rpcResponse);
					break;
				case 'Invalid ID_mailing_list':
					throw new UserEditInvalidMailingListIdException($rpcResponse);
					break;
				default:
					throw new UserEditUnknownErrorException($rpcResponse);
					break;
			}
		}

		$message = trim($rpcResponse->getStringValue());

		if ($message === 'OK') {
			return true;
		}

		throw new UserEditUnknownErrorException($rpcResponse, $message);
	}

	/**
	 * @param string $email
	 * @return int[]
	 */
	public function deleteEmailAddress(string $email): array
	{
		$response = $this->deleteUser($email);

		$emailIds = array_map(function (string $emailId) {
			return (int) $emailId;
		}, explode(',', $response));

		return $emailIds;
	}

	/**
	 * @param int $emailId
	 * @return int
	 */
	public function deleteEmailId(int $emailId): int
	{
		return (int) $this->deleteUser((string)$emailId);
	}

	/**
	 * @param string $emailId email or emailId
	 * @return string
	 */
	private function deleteUser(string $emailId): string
	{
		$params = [
			'ID_email' => $emailId,
		];

		$possibleErrors = [
			'',
		];

		$rpcResponse = $this->sendRpcRequest('mailkit.email.delete', $params, $possibleErrors);

		if ($rpcResponse->isError()) {
			throw new UserUnsubscribtionInvalidEmailIdException($rpcResponse);
		}

		try {
			return (string) $rpcResponse->getIntegerValue();
		} catch (InvalidDataTypeException $e) {
			return $rpcResponse->getStringValue();
		}
	}
}
