<?php
declare(strict_types=1);

namespace Igloonet\MailkitApi\RPC\Adapters;

use Igloonet\MailkitApi\RPC\Exceptions\InvalidRpcResponseException;
use Igloonet\MailkitApi\RPC\Exceptions\RpcRequestFailedException;
use Igloonet\MailkitApi\RPC\Exceptions\UnauthorizedException;
use Igloonet\MailkitApi\RPC\Responses\IRpcResponse;
use Igloonet\MailkitApi\RPC\Responses\XmlErrorRpcResponse;
use Igloonet\MailkitApi\RPC\Responses\XmlSuccessRpcResponse;
use Nette\Utils\Strings;

class XmlAdapter extends BaseAdapter
{
	/** @var string  */
	private $apiUrl = 'https://api.mailkit.eu/rpc.fcgi';

	/** @var string  */
	private $encoding = 'UTF-8';

	public function __construct(string $clientId, string $clientMd5, string $encoding = null)
	{
		parent::__construct($clientId, $clientMd5);

		$this->encoding = $encoding ?? $this->encoding;
	}

	/**
	 * @param string $method
	 * @return bool
	 */
	public function supportsMethod(string $method): bool
	{
		return true; // XML-RPC API supports all possible methods
	}

	/**
	 * @param string $method
	 * @param array $params
	 * @param array $possibleErrors
	 * @return IRpcResponse
	 */
	public function sendRequest(string $method, array $params, array $possibleErrors): IRpcResponse
	{
		$requestData = $this->prepareRequestData($params);
		$options = [
			'escaping' => ['markup'],
			'encoding' => 'utf-8'
		];

		/** @var string|boolean $content */
		$content = $this->getContent($method, $requestData, $options);

		if ($content === false) {
			throw new RpcRequestFailedException(
				$method,
				$requestData
			);
		}

		$responseData = null;

		if (!is_bool($content)) {
			/** @var mixed $responseData */
			$responseData = xmlrpc_decode($content, $this->encoding);
		}

		if (is_array($responseData) && xmlrpc_is_fault($responseData)) {
			throw new InvalidRpcResponseException(
				$method,
				$requestData,
				$responseData,
				sprintf(
					'XML-RPC ERROR: %s (%s)',
					$responseData['faultString'] ?? 'unknown',
					$responseData['faultCode'] ?? 'unknown'
				),
				(int)($responseData['faultCode'] ?? 0)
			);
		}

		if (is_string($responseData)) {
			if (trim($responseData) === 'Unauthorized') {
				throw new UnauthorizedException($method, $requestData);
			} elseif (Strings::startsWith(trim($responseData), 'Disallowed IP')) {
				throw new UnauthorizedException($method, $requestData, $responseData);
			} else {
				foreach ($possibleErrors as $possibleError) {
					if (Strings::compare($responseData, $possibleError) ||
						($possibleError !== '' && Strings::match($responseData, '~'.$possibleError.'~'))
					) {
						return new XmlErrorRpcResponse($responseData);
					}
				}
			}
		}

		return XmlSuccessRpcResponse::createFromResponseData($responseData);
	}

	/**
	 * @param array $params
	 * @return array
	 */
	private function prepareRequestData(array $params): array
	{
		return array_merge(
			[$this->clientId, $this->clientMd5],
			array_values($params)
		);
	}

	/**
	 * @param string $request
	 * @return array
	 */
	protected function getStreamContextOptions(string $request): array
	{
		return [
			'http' => [
				'method' => 'POST',
				'header' => 'Content-Type: text/xml',
				'content' => $request
			]
		];
	}

	/**
	 * @param string $method
	 * @param array $requestData
	 * @param array $options
	 * @return string|false
	 */
	protected function getContent(string $method, array $requestData, array $options)
	{
		$request = xmlrpc_encode_request($method, $requestData, $options);

		$context = stream_context_create($this->getStreamContextOptions($request));

		$content = @file_get_contents($this->apiUrl, false, $context);

		return $content;
	}
}
