<?php
namespace Tartan\Epayment\Adapter;

use SoapClient;
use SoapFault;
use Tartan\Epayment\Adapter\Saderat\Exception;
use Illuminate\Support\Facades\Log;

class Saderat extends AdapterAbstract implements AdapterInterface
{
	protected $WSDL = 'https://mabna.shaparak.ir/PayloadTokenService?wsdl';
	protected $endPoint = 'https://mabna.shaparak.ir';
	protected $verifyWSDL = 'https://mabna.shaparak.ir/TransactionReference/TransactionReference?wsdl';

	protected $testWSDL = 'http://mabna.shaparak.ir/PayloadTokenService?wsdl';
	protected $testEndPoint = 'http://mabna.shaparak.ir';
	protected $testVerifyWSDL = 'http://mabna.shaparak.ir/TransactionReference/TransactionReference?wsdl';

	protected $reverseSupport = false;

	public function init ()
	{
		if (!file_exists($this->public_key_path)) {
			throw new Exception('epayment::epayment.saderat.errors.public_key_file_not_found');
		}

		if (!file_exists($this->private_key_path)) {
			throw new Exception('epayment::epayment.saderat.errors.private_key_file_not_found');
		}

		$this->public_key  = trim(file_get_contents($this->public_key_path));
		$this->private_key = trim(file_get_contents($this->private_key_path));

		Log::debug('public key: ' . $this->public_key_path . ' --- ' . substr($this->public_key, 0, 64));
		Log::debug('private key: ' . $this->private_key_path . ' --- ' . substr($this->private_key, 0, 64));
	}

	/**
	 * @return array
	 * @throws Exception
	 */
	protected function requestToken ()
	{
		if ($this->getTransaction()->checkForRequestToken() == false) {
			throw new Exception('epayment::epayment.could_not_request_payment');
		}

		$this->checkRequiredParameters([
			'MID',
			'TID',
			'public_key',
			'private_key',
			'amount',
			'order_id',
			'redirect_url',
		]);

		$sendParams = [
			"Token_param" => [
				"AMOUNT"        => $this->encryptText($this->amount),
				"CRN"           => $this->encryptText($this->order_id),
				"MID"           => $this->encryptText($this->MID),
				"REFERALADRESS" => $this->encryptText($this->redirect_url),
				"SIGNATURE"     => $this->makeSignature('token'),
				"TID"           => $this->encryptText($this->TID),
				"Payload"       => $this->getTransaction()->description
			]
		];

		try {
			Log::debug('reservation call', $sendParams);

            $soapClient = $this->getSoapClient();

			$response = $soapClient->reservation($sendParams);

			if (is_object($response)) {
				$response = $this->obj2array($response);
			}
			Log::info('reservation response', $response);

			if (isset($response['return'])) {

				if ($response['return']['result'] != 0) {
					throw new Exception($response["return"]["token"], $response['return']['result']);
				}

				if (isset($response['return']['signature'])) {

					/**
					 * Final signature is created
					 */
					$signature = base64_decode($response['return']['signature']);

					/**
					 * State whether signature is okay or not
					 */
					$keyResource  = openssl_get_publickey($this->public_key);
					$verifyResult = openssl_verify($response["return"]["token"], $signature, $keyResource);

					if ($verifyResult == 1) {
						$this->getTransaction()->setReferenceId($response["return"]["token"]); // update transaction reference id
						return $response["return"]["token"];
					}
					else {
						throw new Exception('epayment::epayment.saderat.errors.invalid_verify_result');
					}
				}
				else {
					throw new Exception('epayment::epayment.invalid_response');
				}
			}
			else {
				throw new Exception('epayment::epayment.invalid_response');
			}
		} catch (SoapFault $e) {
			throw new Exception('SoapFault: ' . $e->getMessage() . ' #' . $e->getCode(), $e->getCode());
		}
	}

	/**
	 * @return mixed
	 */
	protected function generateForm ()
	{
		$token = $this->requestToken();

		return view('epayment::saderat-form', [
			'endPoint'    => $this->getEndPoint(),
			'token'       => $token,
			'submitLabel' => !empty($this->submit_label) ? $this->submit_label : trans("epayment::epayment.goto_gate"),
			'autoSubmit'  => boolval($this->auto_submit)
		]);
	}

	protected function verifyTransaction ()
	{
		if ($this->getTransaction()->checkForVerify() == false) {
			throw new Exception('epayment::epayment.could_not_verify_payment');
		}

		$this->checkRequiredParameters([
			'MID',
			'TID',
			'public_key',
			'private_key',
			'RESCODE',
			'TRN',
			'CRN',
			'AMOUNT',
			'SIGNATURE' //callback signature
		]);

		$sendParams = [
			"SaleConf_req" => [
				"MID"       => $this->encryptText($this->MID),
				"CRN"       => $this->encryptText($this->CRN),
				"TRN"       => $this->encryptText($this->TRN),
				"SIGNATURE" => $this->makeSignature('verify'),
			]
		];

		try {
			Log::debug('sendConfirmation call', $sendParams);

			$soapClient = new SoapClient($this->getVerifyWSDL(), $this->getSoapOptions());

			$response = $soapClient->sendConfirmation($sendParams);

			if (is_object($response)) {
				$response = $this->obj2array($response);
			}
			Log::info('sendConfirmation response', $response);

			if (isset($response['return'], $response['return']['RESCODE'])) {
				if (($response['return']['RESCODE'] == '00') && ($response['return']['successful'] == true)) {
					/**
					 * Final signature is created
					 */
					$signature = base64_decode($response['return']['SIGNATURE']);

					$data = $response["return"]["RESCODE"] .
						$response["return"]["REPETETIVE"] .
						$response["return"]["AMOUNT"] .
						$response["return"]["DATE"] .
						$response["return"]["TIME"] .
						$response["return"]["TRN"] .
						$response["return"]["STAN"];

					/**
					 * State whether signature is okay or not
					 */
					$keyResource  = openssl_get_publickey($this->public_key);
					$verifyResult = openssl_verify($data, $signature, $keyResource);

					if ($verifyResult == 1) {
						// success
						// update server description
						if ($response['return']['description'] != "") {
							$this->getTransaction()->setExtra('description', $response['return']['description'], false);
						}
						$this->getTransaction()->setExtra('stan', $response['return']['STAN'], false);
						$this->getTransaction()->setExtra('repeat', $response['return']['REPETETIVE'], false);
						//update server side transaction time
						$this->getTransaction()->setExtra('server_paid_at', date("Y") . $response["return"]["DATE"] . ' ' . $response['return']['TIME']);
						$this->getTransaction()->setReferenceId($response['return']['TRN'], $save = false) ;

						$this->getTransaction()->setVerified(); // calls SAVE too

						return true; // successful verify

					}
					else {
						throw new Exception('epayment::epayment.saderat.errors.invalid_verify_result');
					}
				}
				else if ($response['return']['RESCODE'] == 101) {
					return true;
				}
				else if ($response['return']['RESCODE'] > 0) {
					throw new Exception($response['return']['RESCODE']);
				}
				else if ($response['return']['RESCODE'] < 0) {
					throw new Exception(900 + abs($response['return']['RESCODE']));
				}
				else {
					throw new Exception('epayment::epayment.invalid_response');
				}
			}
			else {
				throw new Exception('epayment::epayment.invalid_response');
			}
		} catch (SoapFault $e) {
			throw new Exception('SoapFault: ' . $e->getMessage() . ' #' . $e->getCode(), $e->getCode());
		}
	}

	private function encryptText ($text)
	{
		/**
		 * get key resource to start based on public key
		 */
		$keyResource = openssl_get_publickey($this->public_key);
		if (!$keyResource) {
			throw new Exception('epayment::epayment.could_not_get_public_key');
		}

		openssl_public_encrypt($text, $encryptedText, $keyResource);

		return base64_encode($encryptedText);
	}

	private function makeSignature ($action)
	{
		/**
		 * Make a signature temporary
		 * Note: each paid has it's own specific signature
		 */
		$source = $this->getSignSource($action);

		/**
		 * Sign data and make final signature
		 */
		$signature = '';

		$privateKey = openssl_pkey_get_private($this->private_key);

		if (!openssl_sign($source, $signature, $privateKey, OPENSSL_ALGO_SHA1)) {
			throw new Exception('epayment::epayment.saderat.errors.making_openssl_sign_error');
		}

		return base64_encode($signature);
	}

	public function getSignSource ($action)
	{
		switch (strtoupper($action)) {
			case 'TOKEN' : {
				return $this->amount . $this->order_id . $this->MID . $this->redirect_url . $this->TID;
				break;
			}

			case 'VERIFY' : {
				return $this->MID . $this->TRN . $this->CRN;
				break;
			}

			default : {
				throw new Exception('undefined sign source');
				break;
			}
		}
	}

	/**
	 * @return string
	 */
	private function getVerifyWSDL ()
	{
		if (config('epayment.mode') == 'production') {
			return $this->verifyWSDL;
		}
		else {
			return $this->testVerifyWSDL;
		}
	}

	/**
	 * @return bool
	 */
	public function canContinueWithCallbackParameters ()
	{
		if ($this->RESCODE == "00") {
			return true;
		}

		return false;
	}

	public function getGatewayReferenceId ()
	{
		$this->checkRequiredParameters([
			'TRN',
		]);

		return $this->TRN;
	}
}
