<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

function bkashlegacy_MetaData()
{
    return [
        'DisplayName'                => 'bKash Merchant (Legacy)',
        'APIVersion'                 => '1.1',
        'DisableLocalCredtCardInput' => true,
        'TokenisedStorage'           => false,
    ];
}

function bkashlegacy_config()
{
    return [
        'FriendlyName' => [
            'Type'  => 'System',
            'Value' => 'bKash Merchant (Legacy)',
        ],
        'msisdn'       => [
            'FriendlyName' => 'Merchant No',
            'Type'         => 'text',
            'Size'         => '25',
            'Default'      => '',
            'Description'  => 'Enter your merchant number here',
        ],
        'user'         => [
            'FriendlyName' => 'API Username',
            'Type'         => 'text',
            'Size'         => '25',
            'Default'      => '',
            'Description'  => 'Enter your merchant api user here',
        ],
        'pass'         => [
            'FriendlyName' => 'API Password',
            'Type'         => 'password',
            'Size'         => '25',
            'Default'      => '',
            'Description'  => 'Enter merchant api pass here',
        ],
        'fee'          => [
            'FriendlyName' => 'Gateway Fee',
            'Type'         => 'text',
            'Size'         => '25',
            'Default'      => 1.85,
            'Description'  => 'Enter the gateway fee in percentages.',
        ],
        'verifyType'   => [
            'FriendlyName' => 'Verify Method',
            'Type'         => 'dropdown',
            'Default'      => 'trxid',
            'Options'      => [
                'sendmsg' => 'Transaction',
                'refmsg'  => 'Reference',
            ],
            'Description'  => 'Select which method you want to use for transaction verification.',
        ],
        'counter'      => [
            'FriendlyName' => 'Counter',
            'Type'         => 'text',
            'Size'         => '25',
            'Default'      => 0,
            'Description'  => 'Enter counter number if want.',
        ],
    ];
}

function bkashlegacy_getTotalPayable($params)
{
    $rate           = 1;
    $gwCurrency     = (int)$params['convertto'];
    $clientCurrency = $params['clientdetails']['currency'];

    if (!empty($gwCurrency) && ($gwCurrency !== $clientCurrency)) {
        $rate = \WHMCS\Database\Capsule::table('tblcurrencies')
                                       ->where('id', '=', $gwCurrency)
                                       ->value('rate');
    }

    $fee = empty($params['fee']) ? 0 : (($params['fee'] / 100) * $params['amount']);

    return ceil(($params['amount'] + $fee) * $rate);
}

function bkashlegacy_link($params)
{
    $totalDue = bkashlegacy_getTotalPayable($params);
    $action   = $params['systemurl'] . 'modules/gateways/callback/' . $params['paymentmethod'] . '.php';
    $scripts  = bkashlegacy_scriptsHandle($params);

    $fields = <<<HTML
<div class="form-group">
    <label class="sr-only" for="inlineFormInput">Transaction Key</label>
    <input type="text" name="trxId" class="form-control mb-2" id="bkashlegacy-trxid" placeholder="ABC134CDEF" required>
</div>
HTML;

    if ($params['verifyType'] === 'refmsg') {
        $fields = '<p>Already paid? Please click "Verify" button.</p>';
    }

    return <<<HTML
<ol class="text-left margin-top-5">
    <li>Dial <span class="label label-primary">*247#</span> to open bKash menu.</li>
    <li>Enter <span class="label label-primary">4</span> for <span class="label label-primary">Payment</span></li>
    <li>Enter Number: <span class="label label-primary">{$params['msisdn']}</span></li></li>
    <li>Enter Due Amount <span class="label label-primary">{$totalDue}</span> Taka</li>
    <li>Enter Invoice #<span class="label label-primary">{$params['invoiceid']}</span> as Reference</li>
    <li>Enter <span class="label label-primary">{$params['counter']}</span> as Counter Number</li>
    <li>Enter PIN and Confirm</li>
</ol>
<form id="bkashlegacy-form" action="$action" method="POST" class="form-inline">
    <input type="hidden" name="id" value="{$params['invoiceid']}">
    {$fields}
    <button type="submit" id="bkashlegacy-btn" class="btn btn-primary mb-2"><i class="fas fa-circle-notch fa-spin hidden" style="margin-right: 5px"></i>Verify</button>
</form>
<div id="bkashlegacy-response" class="alert alert-danger hidden" style="margin-top: 20px"></div>
{$scripts}
HTML;
}

function bkashlegacy_scriptsHandle($params)
{
    $apiUrl = $params['systemurl'] . 'modules/gateways/callback/' . $params['paymentmethod'] . '.php';
    $markup = <<<HTML
<script>
    window.addEventListener('load', function() {
        var bkashBtn = $('#bkashlegacy-btn');       
        var bKashResponse = $('#bkashlegacy-response');
        var bKashLoader = $('i', bkashBtn);
    
        $('#bkashlegacy-form').on('submit', function(e) {
            e.preventDefault();
            
            bkashBtn.attr('disabled', 'disabled');
            bKashLoader.removeClass('hidden');
    
            $.ajax({
                method: "POST",
                url: "{$apiUrl}",
                data: $('#bkashlegacy-form').serialize()
            }).done(function(response) {
                if (response.status === 'success') {
                    window.location = "{$params['returnurl']}" + "&paymentsuccess=true";
                } else {
                   bKashResponse.removeClass('hidden');
                   bKashResponse.text(response.message);   
                }
            }).fail(function() {
                bKashResponse.removeClass('hidden');
                bKashResponse.text('Something is wrong! Please contact support.');
              }).always(function () {
                bkashBtn.removeAttr('disabled');
                bKashLoader.addClass('hidden');
            });
        })
    });
</script>
HTML;

    return $markup;
}