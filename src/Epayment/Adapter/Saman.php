<?php
namespace Tartan\Epayment\Adapter;

use SoapClient;
use SoapFault;
use Tartan\Epayment\Adapter\Saman\Exception;
use Illuminate\Support\Facades\Log;

class Saman extends AdapterAbstract implements AdapterInterface
{
	protected $WSDL         = 'https://sep.shaparak.ir/payments/referencepayment.asmx?WSDL';
	protected $tokenWSDL    = 'https://sep.shaparak.ir/Payments/InitPayment.asmx?WSDL';

	protected $endPoint     = 'https://sep.shaparak.ir/Payment.aspx';

	protected $testWSDL     = 'http://banktest.ir/gateway/saman/ws?wsdl';
	protected $testEndPoint = 'http://banktest.ir/gateway/saman/gate';

	protected $reverseSupport = true;

	/**
	 * @return array
	 * @throws Exception
	 */
	protected function requestToken()
	{
		if($this->getTransaction()->checkForRequestToken() == false) {
			throw new Exception('epayment::epayment.could_not_request_payment');
		}

		$this->checkRequiredParameters([
			'merchant_id',
			'order_id',
			'amount',
			'redirect_url',
		]);

		$sendParams = [
			'TermID'      => $this->merchant_id,
			'ResNum'      => $this->order_id,
			'TotalAmount' => intval($this->amount),
		];

		try {
            $soapClient = $this->getSoapClient();

			Log::debug('RequestToken call', $sendParams);

			$response = $soapClient->__soapCall('RequestToken', $sendParams);

			if (!empty($response))
			{
				Log::info('RequestToken response', ['response' => $response]);

				if (strlen($response) > 10) { // got string token
					$this->getTransaction()->setReferenceId($response); // update transaction reference id
					return $response;
				} else {
					throw new Exception($response); // negative integer as error
				}
			}
			else {
				throw new Exception('epayment::epayment.invalid_response');
			}

		} catch (SoapFault $e) {
			throw new Exception('SoapFault: ' . $e->getMessage() . ' #' . $e->getCode(), $e->getCode());
		}
	}

	public function generateForm()
	{
		Log::debug(__METHOD__);
		if ($this->with_token) {
			return $this->generateFormWithToken();
		} else {
			return $this->generateFormWithoutToken(); // default
		}
	}

	protected function generateFormWithoutToken()
	{
		Log::debug(__METHOD__, $this->getParameters());
		$this->checkRequiredParameters([
			'merchant_id',
			'amount',
			'order_id',
			'redirect_url'
		]);

		return view('epayment::saman-form', [
			'endPoint'    => $this->getEndPoint(),
			'amount'      => intval($this->amount),
			'merchantId'  => $this->merchant_id,
			'orderId'     => $this->order_id,
			'redirectUrl' => $this->redirect_url,
			'submitLabel' => !empty($this->submit_label) ? $this->submit_label : trans("epayment::epayment.goto_gate"),
			'autoSubmit'  => boolval($this->auto_submit),
		]);
	}

	protected function generateFormWithToken()
	{
		Log::debug(__METHOD__, $this->getParameters());
		$this->checkRequiredParameters([
			'merchant_id',
			'order_id',
			'amount',
			'redirect_url',
		]);

		$token = $this->requestToken();

		Log::info(__METHOD__, ['fetchedToken' => $token]);

		return view('epayment::saman-form', [
			'endPoint'    => $this->getEndPoint(),
			'amount'      => '',// just because of view
			'merchantId'  => '', // just because of view
			'orderId'     => '', // just because of view
			'token'       => $token,
			'redirectUrl' => $this->redirect_url,
			'submitLabel' => !empty($this->submit_label) ? $this->submit_label : trans("epayment::epayment.goto_gate"),
			'autoSubmit'  => boolval($this->auto_submit),
		]);
	}

	protected function verifyTransaction()
	{
		if($this->getTransaction()->checkForVerify() == false) {
			throw new Exception('epayment::epayment.could_not_verify_payment');
		}

		$this->checkRequiredParameters([
			'State',
			'RefNum',
			'ResNum',
			'merchant_id',
			'TRACENO',
		]);

		if ($this->State != 'OK') {
			throw new Exception('Error: ' . $this->State);
		}

		try {
            $soapClient = $this->getSoapClient();

			Log::info('VerifyTransaction call', [$this->RefNum, $this->merchant_id]);
			$response = $soapClient->VerifyTransaction($this->RefNum, $this->merchant_id);

			if (isset($response))
			{
				Log::info('VerifyTransaction response', ['response' => $response]);

				if ($response == $this->getTransaction()->getAmount()) { // check by transaction amount
					$this->getTransaction()->setVerified();
					return true;
				} else {
					throw new Exception($response);
				}
			}
			else {
				throw new Exception('epayment::epayment.invalid_response');
			}

		} catch (SoapFault $e) {
			throw new Exception('SoapFault: ' . $e->getMessage() . ' #' . $e->getCode(), $e->getCode());
		}
	}

	protected function reverseTransaction()
	{
		if ($this->reverseSupport == false || $this->getTransaction()->checkForReverse() == false) {
			throw new Exception('epayment::epayment.could_not_reverse_payment');
		}

		$this->checkRequiredParameters([
			'RefNum',
			'merchant_id',
			'password',
			'amount'
		]);

		try {
            $soapClient = $this->getSoapClient();

			Log::info('reverseTransaction call', [$this->RefNum, $this->merchant_id]);
			$response = $soapClient->reverseTransaction(
				$this->ref_id,
				$this->merchant_id,
				$this->password,
				$this->amount
			);

			if (isset($response))
			{
				Log::info('reverseTransaction response', ['response' => $response]);

				if ($response === 1) { // check by transaction amount
					$this->getTransaction()->setReversed();
					return true;
				} else {
					throw new Exception($response);
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
	 * @return bool
	 */
	public function canContinueWithCallbackParameters()
	{
		try {
			$this->checkRequiredParameters([
				'RefNum',
				'State'
			]);
		} catch (\Exception $e) {
			return false;
		}

		if ($this->State == 'OK') {
			return true;
		}
		return false;
	}

	public function getGatewayReferenceId()
	{
		$this->checkRequiredParameters([
			'RefNum',
		]);
		return $this->RefNum;
	}

	protected function getWSDL ($type = null)
	{
		if (config('epayment.mode') == 'production') {
			switch (strtoupper($type)) {
				case 'TOKEN':
					return $this->tokenWSDL;
					break;
				default:
					return $this->WSDL;
					break;
			}
		} else {
			return $this->testWSDL;
		}
	}
}
