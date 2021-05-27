<?php

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

class bKashLegacy
{
    /**
     * @var self
     */
    private static $instance;

    /**
     * @var string
     */
    protected $gatewayModuleName;

    /**
     * @var array
     */
    protected $gatewayParams;

    /**
     * @var string
     */
    protected $verifyType;

    /**
     * @var boolean
     */
    public $isActive;

    /**
     * @var string
     */
    protected $baseUrl;

    /**
     * @var integer
     */
    protected $customerCurrency;

    /**
     * @var object
     */
    protected $gatewayCurrency;

    /**
     * @var integer
     */
    protected $clientCurrency;

    /**
     * @var float
     */
    protected $convoRate;

    /**
     * @var array
     */
    protected $invoice;

    /**
     * @var float
     */
    protected $due;

    /**
     * @var float
     */
    protected $fee;

    /**
     * @var float
     */
    public $total;

    /**
     * @var \Symfony\Component\HttpFoundation\Request
     */
    public $request;

    /**
     * @var array
     */
    private $credential;

    /**
     * bKashCheckout constructor.
     */
    public function __construct()
    {
        $this->setGateway();
        $this->setRequest();
        $this->setInvoice();

        $this->baseUrl = 'https://www.bkashcluster.com:9081/dreamwave/merchant/trxcheck/';
    }

    /**
     * The instance.
     *
     * @return self
     */
    public static function init()
    {
        if (self::$instance == null) {
            self::$instance = new bKashLegacy;
        }

        return self::$instance;
    }

    /**
     * Set the payment gateway.
     */
    private function setGateway()
    {
        $this->gatewayModuleName = basename(__FILE__, '.php');
        $this->gatewayParams     = getGatewayVariables($this->gatewayModuleName);
        $this->isActive          = !empty($this->gatewayParams['type']);
        $this->verifyType        = $this->gatewayParams['verifyType'];

        $this->credential = [
            'msisdn' => $this->gatewayParams['msisdn'],
            'user'   => $this->gatewayParams['user'],
            'pass'   => $this->gatewayParams['pass'],
        ];
    }

    /**
     * Set request
     */
    private function setRequest()
    {
        $this->request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
    }

    /**
     * Set the invoice.
     */
    private function setInvoice()
    {
        $this->invoice = localAPI('GetInvoice', [
            'invoiceid' => $this->request->get('id'),
        ]);

        $this->setCurrency();
        $this->setDue();
        $this->setFee();
        $this->setTotal();
    }

    /**
     * Set currency.
     */
    private function setCurrency()
    {
        $this->gatewayCurrency  = (int) $this->gatewayParams['convertto'];
        $this->customerCurrency = (int) \WHMCS\Database\Capsule::table('tblclients')
            ->where('id', '=', $this->invoice['userid'])
            ->value('currency');

        if (!empty($this->gatewayCurrency) && ($this->customerCurrency !== $this->gatewayCurrency)) {
            $this->convoRate = \WHMCS\Database\Capsule::table('tblcurrencies')
                ->where('id', '=', $this->gatewayCurrency)
                ->value('rate');
        } else {
            $this->convoRate = 1;
        }
    }

    /**
     * Set due.
     */
    private function setDue()
    {
        $this->due = $this->invoice['balance'];
    }

    /**
     * Set fee.
     */
    private function setFee()
    {
        $this->fee = empty($this->gatewayParams['fee']) ? 0 : (($this->gatewayParams['fee'] / 100) * $this->due);
    }

    /**
     * Set total.
     */
    private function setTotal()
    {
        $this->total = ceil(($this->due + $this->fee) * $this->convoRate);
    }

    /**
     * Check if transaction is exists.
     *
     * @param string $trxId
     *
     * @return mixed
     */
    private function checkTransaction($trxId)
    {
        return localAPI(
            'GetTransactions',
            ['transid' => $trxId]
        );
    }

    /**
     * Log the transaction.
     *
     * @param array $payload
     *
     * @return mixed
     */
    private function logTransaction($payload)
    {
        return logTransaction(
            $this->gatewayParams['name'],
            [
                $this->gatewayModuleName => $payload,
                'post'                   => $this->request->request->all(),
            ],
            $payload['trxStatus']
        );
    }

    /**
     * Add transaction to the invoice.
     *
     * @param string $trxId
     *
     * @return array
     */
    private function addTransaction($trxId)
    {
        $fields = [
            'invoiceid' => $this->invoice['invoiceid'],
            'transid'   => $trxId,
            'gateway'   => $this->gatewayModuleName,
            'date'      => \Carbon\Carbon::now()->toDateTimeString(),
            'amount'    => $this->due,
            'fees'      => $this->fee,
        ];
        $add    = localAPI('AddInvoicePayment', $fields);

        return array_merge($add, $fields);
    }

    /**
     * Get error message by code.
     *
     * @param string $code
     *
     * @return string
     */
    private function getErrorMessage($code)
    {
        $errors = [
            '0000' => 'TrxID is valid and transaction is successful.',
            '0010' => 'TrxID is valid but transaction is in pending state.',
            '0011' => 'TrxID is valid but transaction is in pending state.',
            '0100' => 'TrxID is valid but transaction has been reversed.',
            '0111' => 'TrxID is valid but transaction has failed.',
            '1001' => 'Invalid MSISDN input. Try with correct mobile no.',
            '1002' => 'Invalid trxID, it does not exist.',
            '1003' => 'Access denied. Username or Password is incorrect.',
            '1004' => 'Access denied. TrxID is not related to this username.',
            '2000' => 'Access denied. User does not have access to this module.',
            '2001' => 'Access denied. User date time request is exceeded of the defined limit.',
            '3000' => 'Missing required mandatory fields for this module.',
            '9999' => 'Could not process request.',
            '4001' => 'Duplicate request done with same information (e.g. same transaction id).',
        ];

        return isset($errors[$code]) ? $errors[$code] : 'Unknown error.';
    }

    /**
     * Verify Transaction.
     *
     * @return array
     */
    public function verifyPayment()
    {
        $fields = $this->credential;
        if ($this->verifyType === 'refmsg') {
            $fields['reference'] = $this->invoice['invoiceid'];
        } else {
            $fields['trxid'] = $this->request->get('trxId');
        }

        $context  = [
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\n" .
                    "Accept: application/json\r\n",
                'content' => json_encode($fields),
                'timeout' => 30,
            ],
        ];
        $context  = stream_context_create($context);
        $url      = $this->baseUrl . $this->verifyType;
        $response = file_get_contents($url, false, $context);
        $data     = json_decode($response, true);

        if (is_array($data)) {
            if ($this->verifyType === 'refmsg') {
                return $data['transaction'][0];
            } else {
                return $data['transaction'];
            }
        }

        return [
            'status'  => 'error',
            'message' => 'Invalid response from bKash API.',
        ];
    }

    /**
     * Make the transaction.
     *
     * @return array
     */
    public function makeTransaction()
    {
        $verifyData = $this->verifyPayment();

        if (is_array($verifyData) && isset($verifyData['trxStatus'])) {
            if ($verifyData['trxStatus'] == '0000') {
                $existing = $this->checkTransaction($verifyData['trxId']); // TODO: Pre-check before call API.

                if ($existing['totalresults'] > 0) {
                    return [
                        'status'  => 'error',
                        'message' => 'The transaction has been already used.',
                    ];
                }

                if ($verifyData['amount'] < $this->total) {
                    return [
                        'status'  => 'error',
                        'message' => 'You\'ve paid less than amount is required.',
                    ];
                }

                $this->logTransaction($verifyData); // TODO: Log full response.

                $trxAddResult = $this->addTransaction($verifyData['trxId']);

                if ($trxAddResult['result'] === 'success') {
                    return [
                        'status'  => 'success',
                        'message' => 'The payment has been successfully verified.',
                    ];
                }

                return [
                    'status'  => 'error',
                    'message' => 'Unable to create transaction.',
                ];
            }

            return [
                'status'  => 'error',
                'message' => $this->getErrorMessage($verifyData['trxStatus']),
            ];
        }

        return [
            'status'  => 'error',
            'message' => 'Payment validation error.',
        ];
    }
}

if (!$_SERVER['REQUEST_METHOD'] === 'POST') {
    die("Direct access forbidden.");
}

if (!(new \WHMCS\ClientArea())->isLoggedIn()) {
    die("You will need to login first.");
}

$bKashCheckout = bKashLegacy::init();

if (!$bKashCheckout->isActive) {
    die("The gateway is unavailable.");
}

header('Content-Type: application/json');
die(json_encode($bKashCheckout->makeTransaction()));
